<?php

namespace App\Northdeck\Sso;

use App\Models\User;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Oidc\OidcClient;
use App\Northdeck\Sso\Saml\SamlService;
use App\Northdeck\Sso\Support\SsoUrls;
use App\Support\Extensions\SignInHooks;
use Illuminate\Http\Request;

final class SsoSignInHooks extends SignInHooks
{
    /** @return array<string, string> */
    public function signInRoutes(): array
    {
        return [
            'northdeck.sso.oidc.callback' => 'sso',
            'northdeck.sso.saml.finish' => 'sso',
            'northdeck.sso.saml.confirm' => 'sso',
        ];
    }

    public function refuseSocialSignIn(Request $request, ?User $user, string $email): ?string
    {
        $workspace = $user === null ? null : Enforcement::blockingWorkspace($user);

        if ($workspace === null) {
            return null;
        }
        $message = __(':workspace asks you to sign in with SSO.', ['workspace' => $workspace->name]);

        return is_string($message) ? $message : $workspace->name.' asks you to sign in with SSO.';
    }

    /**
     * Single logout: where the provider supports it, signing out of the panel
     * signs out there too. Otherwise only the panel's session ends.
     */
    public function signingOut(Request $request): ?string
    {
        $marker = $request->hasSession() ? $request->session()->get(SsoSignIn::SESSION) : null;
        if (! is_array($marker) || ! is_string($marker['connection'] ?? null) || ! is_array($marker['idp'] ?? null)) {
            return null;
        }

        $connection = SsoConnection::query()->where('uuid', $marker['connection'])->first();
        if ($connection === null || ! (bool) $connection->setting('single_logout', true)) {
            return null;
        }

        /** @var array<string, string> $idp */
        $idp = $marker['idp'];
        $returnTo = SsoUrls::route('northdeck.sso.signed-out');

        if ($connection->isOidc() && ($idp['end_session_endpoint'] ?? '') !== '' && ($idp['id_token'] ?? '') !== '') {
            return OidcClient::logoutUrl($idp['end_session_endpoint'], $idp['id_token'], $returnTo, $connection->stringSetting('client_id'));
        }

        if ($connection->isSaml()) {
            return app(SamlService::class)->logoutUrl($connection, $idp, $returnTo);
        }

        return null;
    }
}
