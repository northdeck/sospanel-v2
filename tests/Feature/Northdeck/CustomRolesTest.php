<?php

use App\Enums\Feature;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Northdeck\Scim\ScimToken;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use App\Northdeck\Sso\SsoSignIn;
use Tests\Support\Northdeck\FakeIdp;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    config()->set('northdeck.sso.allow_private_hosts', true);
    config()->set('northdeck.roles.custom', true);
    FakeIdp::oidc();
});

function deployer(Workspace $workspace): WorkspaceCustomRole
{
    $role = new WorkspaceCustomRole(['name' => 'Deployer', 'description' => null, 'permissions' => [WorkspacePermission::ManageMachines->value]]);
    $role->workspace_id = $workspace->id;
    $role->save();

    return $role;
}

test('custom roles are on when NORTHDECK_CUSTOM_ROLES is', function () {
    $workspace = Workspace::factory()->create();
    expect($workspace->hasFeature(Feature::CustomRoles))->toBeTrue();

    config()->set('northdeck.roles.custom', false);
    expect($workspace->hasFeature(Feature::CustomRoles))->toBeFalse();
});

test('a group can map to a custom role, and the settings page offers it', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $role = deployer($workspace);

    $this->actingAs($workspace->owner)
        ->post($workspace->url("/settings/sso/connections/{$connection->uuid}/groups"), ['group' => 'deploy', 'role' => "custom:{$role->id}"])
        ->assertSessionHasNoErrors();

    expect(SsoGroupMapping::query()->where('group', 'deploy')->value('role'))->toBe("custom:{$role->id}");

    $this->actingAs($workspace->owner)->get($workspace->url('/settings/sso'))
        ->assertInertia(fn ($page) => $page->where('roles', fn ($roles) => collect($roles)->contains('value', "custom:{$role->id}")));
});

test('a custom role from another workspace is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $other = deployer(Workspace::factory()->create());

    $this->actingAs($workspace->owner)
        ->post($workspace->url("/settings/sso/connections/{$connection->uuid}/groups"), ['group' => 'deploy', 'role' => "custom:{$other->id}"])
        ->assertSessionHasErrors('role');
});

test('sign-in gives the custom role a group maps to, and moves people off it again', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $role = deployer($workspace);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'deploy', 'role' => "custom:{$role->id}"]);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'readers', 'role' => 'viewer']);
    $connection->load('groupMappings');
    $signIn = app(SsoSignIn::class);
    $user = SsoFixtures::member($workspace, 'ada@acme.test', 'viewer');

    $signIn->syncMembership($workspace, $connection, $user, ['readers', 'deploy']);
    expect($workspace->customRoleOf($user)?->id)->toBe($role->id)
        ->and($workspace->allows($user, WorkspacePermission::ManageMachines))->toBeTrue()
        ->and($workspace->allows($user, WorkspacePermission::ManageSettings))->toBeFalse();

    $signIn->syncMembership($workspace, $connection, $user, ['readers']);
    expect($workspace->customRoleOf($user))->toBeNull()
        ->and($workspace->roleOf($user))->toBe(WorkspaceRole::Viewer);
});

test('a built-in role with more access beats a custom one', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $role = deployer($workspace);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'deploy', 'role' => "custom:{$role->id}"]);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'admins', 'role' => 'admin']);
    $connection->load('groupMappings');

    expect(app(SsoSignIn::class)->mappedRole($workspace, $connection, ['deploy', 'admins'])?->role)->toBe(WorkspaceRole::Admin);
});

test('new people get a custom default role', function () {
    $workspace = SsoFixtures::workspace();
    $role = deployer($workspace);
    $connection = SsoFixtures::oidc($workspace, ['default_role' => "custom:{$role->id}"]);
    $user = User::factory()->create(['email' => 'new@acme.test']);

    app(SsoSignIn::class)->syncMembership($workspace, $connection, $user, []);

    expect($workspace->customRoleOf($user)?->id)->toBe($role->id);
});

test('a default role that was deleted falls back to Member', function () {
    $workspace = SsoFixtures::workspace();
    $role = deployer($workspace);
    $connection = SsoFixtures::oidc($workspace, ['default_role' => "custom:{$role->id}"]);
    $role->delete();
    $user = User::factory()->create(['email' => 'new@acme.test']);

    app(SsoSignIn::class)->syncMembership($workspace, $connection, $user, []);

    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Member)
        ->and($workspace->customRoleOf($user))->toBeNull();
});

test('SCIM groups map to custom roles too', function () {
    config()->set('northdeck.scim.enabled', true);
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $role = deployer($workspace);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'Deploy', 'role' => "custom:{$role->id}"]);
    [, $secret] = ScimToken::issue($workspace);
    $headers = ['Authorization' => "Bearer {$secret}", 'Accept' => 'application/scim+json'];

    $userId = $this->withHeaders($headers)->postJson('/api/scim/v2/Users', ['userName' => 'ada@acme.test', 'emails' => [['value' => 'ada@acme.test', 'primary' => true]]])->json('id');
    $this->withHeaders($headers)->postJson('/api/scim/v2/Groups', ['displayName' => 'Deploy', 'members' => [['value' => $userId]]])->assertCreated();

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    expect($workspace->fresh()?->customRoleOf($user)?->id)->toBe($role->id);
});
