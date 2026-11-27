<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Northdeck\Scim\ScimToken;
use App\Northdeck\Scim\ScimUser;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    config()->set('northdeck.scim.enabled', true);
});

function scim(object $test, string $secret): object
{
    return $test->withHeaders(['Authorization' => "Bearer {$secret}", 'Accept' => 'application/scim+json']);
}

test('SCIM is off unless NORTHDECK_SCIM is on', function () {
    config()->set('northdeck.scim.enabled', false);
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);

    scim($this, $secret)->getJson('/api/scim/v2/Users')->assertStatus(401);
});

test('a bad token gets nothing', function () {
    $this->withHeaders(['Authorization' => 'Bearer nope'])->getJson('/api/scim/v2/Users')->assertStatus(401);
});

test('creating a user adds them to the workspace; deactivating removes them', function () {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);

    $created = scim($this, $secret)->postJson('/api/scim/v2/Users', [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => 'ada@acme.test',
        'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
        'emails' => [['value' => 'ada@acme.test', 'primary' => true]],
        'active' => true,
    ])->assertCreated()->json();

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Member)
        ->and($user->name)->toBe('Ada Lovelace');

    scim($this, $secret)->patchJson('/api/scim/v2/Users/'.$created['id'], [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
    ])->assertOk()->assertJsonPath('active', false);

    expect($workspace->fresh()->hasMember($user))->toBeFalse();

    scim($this, $secret)->getJson('/api/scim/v2/Users?filter='.rawurlencode('userName eq "ada@acme.test"'))
        ->assertOk()->assertJsonPath('totalResults', 1);
});

test('users on a domain the workspace hasn’t verified are refused', function () {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);

    scim($this, $secret)->postJson('/api/scim/v2/Users', ['userName' => 'eve@other.test', 'emails' => [['value' => 'eve@other.test', 'primary' => true]]])
        ->assertStatus(400);
    expect(User::query()->where('email', 'eve@other.test')->exists())->toBeFalse();
});

test('SCIM never changes a person’s sign-in email', function () {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);
    $id = scim($this, $secret)->postJson('/api/scim/v2/Users', ['userName' => 'ada@acme.test', 'emails' => [['value' => 'ada@acme.test', 'primary' => true]]])->json('id');

    scim($this, $secret)->putJson('/api/scim/v2/Users/'.$id, ['userName' => 'ada@acme.test', 'emails' => [['value' => 'attacker@evil.test', 'primary' => true]], 'active' => true])->assertOk();

    expect(ScimUser::query()->where('uuid', $id)->first()->user->email)->toBe('ada@acme.test');
});

test('the workspace owner is never removed', function () {
    $workspace = SsoFixtures::workspace();
    $workspace->owner->forceFill(['email' => 'boss@acme.test'])->save();
    [, $secret] = ScimToken::issue($workspace);
    $id = scim($this, $secret)->postJson('/api/scim/v2/Users', ['userName' => 'boss@acme.test', 'emails' => [['value' => 'boss@acme.test', 'primary' => true]]])->json('id');

    scim($this, $secret)->deleteJson('/api/scim/v2/Users/'.$id)->assertNoContent();

    expect($workspace->fresh()->roleOf($workspace->owner))->toBe(WorkspaceRole::Owner);
});

test('group membership maps to roles through the SSO group mappings', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'Platform', 'role' => 'admin']);
    [, $secret] = ScimToken::issue($workspace);
    $userId = scim($this, $secret)->postJson('/api/scim/v2/Users', ['userName' => 'ada@acme.test', 'emails' => [['value' => 'ada@acme.test', 'primary' => true]]])->json('id');
    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();

    $groupId = scim($this, $secret)->postJson('/api/scim/v2/Groups', ['displayName' => 'Platform', 'members' => [['value' => $userId]]])->assertCreated()->json('id');
    expect($workspace->fresh()->roleOf($user))->toBe(WorkspaceRole::Admin);

    scim($this, $secret)->patchJson('/api/scim/v2/Groups/'.$groupId, ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "'.$userId.'"]']]])->assertOk();
    expect(scim($this, $secret)->getJson('/api/scim/v2/Groups/'.$groupId)->json('members'))->toBe([]);
});

function scimUser(object $test, string $secret, string $email = 'ada@acme.test'): string
{
    return scim($test, $secret)->postJson('/api/scim/v2/Users', [
        'userName' => $email, 'externalId' => 'ext-1',
        'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
        'emails' => [['value' => $email, 'primary' => true]],
    ])->assertCreated()->json('id');
}

test('PATCH remove clears externalId', function () {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);
    $id = scimUser($this, $secret);

    scim($this, $secret)->patchJson('/api/scim/v2/Users/'.$id, ['Operations' => [['op' => 'remove', 'path' => 'externalId']]])
        ->assertOk()->assertJsonPath('externalId', null);
});

test('PATCH remove of an attribute the panel needs is refused, not skipped', function (string $path) {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);
    $id = scimUser($this, $secret);

    scim($this, $secret)->patchJson('/api/scim/v2/Users/'.$id, ['Operations' => [['op' => 'remove', 'path' => $path]]])
        ->assertStatus(400)->assertJsonPath('scimType', 'mutability');
})->with(['userName', 'active', 'emails', 'name.givenName', 'displayName']);

test('PATCH with an unknown op or path is an error, and changes nothing', function (array $operations, string $type) {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);
    $id = scimUser($this, $secret);

    scim($this, $secret)->patchJson('/api/scim/v2/Users/'.$id, ['Operations' => $operations])
        ->assertStatus(400)->assertJsonPath('scimType', $type);

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    expect($user->name)->toBe('Ada Lovelace')->and($workspace->fresh()->hasMember($user))->toBeTrue();
})->with([
    'unknown op' => [[['op' => 'move', 'path' => 'active', 'value' => false]], 'invalidSyntax'],
    'unknown path' => [[['op' => 'replace', 'path' => 'favouriteColour', 'value' => 'teal']], 'invalidPath'],
    'bad op after a good one' => [[['op' => 'replace', 'path' => 'active', 'value' => false], ['op' => 'copy']], 'invalidSyntax'],
    'remove without a path' => [[['op' => 'remove']], 'noTarget'],
]);

test('PATCH understands what Entra and Okta send', function () {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);
    $id = scimUser($this, $secret);

    // Entra: dotted keys in a value object, "False" as a string, enterprise extension, title.
    scim($this, $secret)->patchJson('/api/scim/v2/Users/'.$id, ['Operations' => [
        ['op' => 'replace', 'value' => ['name.givenName' => 'Augusta', 'title' => 'Countess']],
        ['op' => 'add', 'path' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department', 'value' => 'Maths'],
        ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
    ]])->assertOk()->assertJsonPath('active', false);

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    expect($user->name)->toBe('Augusta Lovelace')->and($workspace->fresh()->hasMember($user))->toBeFalse();
});

test('Group PATCH removes everyone with a bare members remove, and refuses unknown paths', function () {
    $workspace = SsoFixtures::workspace();
    [, $secret] = ScimToken::issue($workspace);
    $a = scimUser($this, $secret, 'ada@acme.test');
    $b = scimUser($this, $secret, 'grace@acme.test');
    $group = scim($this, $secret)->postJson('/api/scim/v2/Groups', ['displayName' => 'Eng', 'members' => [['value' => $a], ['value' => $b]]])->json('id');

    scim($this, $secret)->patchJson('/api/scim/v2/Groups/'.$group, ['Operations' => [['op' => 'remove', 'path' => 'owner']]])
        ->assertStatus(400)->assertJsonPath('scimType', 'noTarget');
    expect(scim($this, $secret)->getJson('/api/scim/v2/Groups/'.$group)->json('members'))->toHaveCount(2);

    scim($this, $secret)->patchJson('/api/scim/v2/Groups/'.$group, ['Operations' => [['op' => 'remove', 'path' => 'members']]])->assertOk();
    expect(scim($this, $secret)->getJson('/api/scim/v2/Groups/'.$group)->json('members'))->toBe([]);

    scim($this, $secret)->patchJson('/api/scim/v2/Groups/'.$group, ['Operations' => [['op' => 'remove', 'path' => 'displayName']]])
        ->assertStatus(400)->assertJsonPath('scimType', 'mutability');
});
