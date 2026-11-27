<?php

namespace Tests\Support\Northdeck;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Saml\SamlService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SsoFixtures
{
    public static function workspace(string $domain = 'acme.test', bool $verified = true): Workspace
    {
        $workspace = Workspace::factory()->create();
        $email = new WorkspaceEmailDomain(['domain' => $domain]);
        $email->workspace_id = $workspace->id;
        $email->verified_at = $verified ? now() : null;
        $email->save();

        return $workspace;
    }

    /** @param  array<string, mixed>  $attributes */
    public static function oidc(Workspace $workspace, array $attributes = [], array $settings = []): SsoConnection
    {
        $connection = new SsoConnection([
            'workspace_id' => $workspace->id,
            'name' => 'Acme IdP',
            'protocol' => SsoConnection::OIDC,
            'preset' => 'oidc',
            'enabled' => true,
            'settings' => ['issuer' => FakeIdp::ISSUER, 'client_id' => FakeIdp::CLIENT_ID, 'scopes' => 'openid email profile', 'groups_claim' => 'groups', ...$settings],
            'jit' => true,
            'default_role' => 'member',
            ...$attributes,
        ]);
        $connection->client_secret = 'top-secret';
        $connection->save();
        $connection->domains()->sync($workspace->emailDomains()->whereNotNull('verified_at')->pluck('id')->all());

        return $connection;
    }

    /** @param  array<string, mixed>  $settings */
    public static function saml(Workspace $workspace, array $settings = []): SsoConnection
    {
        $pair = SamlService::newKeyPair('serveros.test');
        $connection = new SsoConnection([
            'workspace_id' => $workspace->id,
            'name' => 'Acme SAML',
            'protocol' => SsoConnection::SAML,
            'preset' => 'saml',
            'enabled' => true,
            'settings' => [
                'idp_entity_id' => 'https://idp.test/saml',
                'idp_sso_url' => 'https://idp.test/saml/sso',
                'idp_slo_url' => 'https://idp.test/saml/slo',
                'idp_certificates' => [SamlService::normalizeCertificate(FakeIdp::key('saml-1')['cert'])],
                'groups_claim' => 'groups',
                'allow_idp_initiated' => true,
                ...$settings,
            ],
            'jit' => true,
            'default_role' => 'member',
        ]);
        $connection->sp_private_key = $pair['key'];
        $connection->sp_certificate = $pair['certificate'];
        $connection->save();
        $connection->domains()->sync($workspace->emailDomains()->whereNotNull('verified_at')->pluck('id')->all());

        return $connection;
    }

    public static function member(Workspace $workspace, string $email, string $role = 'member'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $workspace->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /** Sends the next request with the session cookie the last response set. */
    public static function keepSession(TestCase $test, TestResponse $response): void
    {
        $name = (string) config('session.cookie');
        $cookie = $response->getCookie($name);
        if ($cookie !== null) {
            $test->withCookie($name, (string) $cookie->getValue());
        }
    }
}
