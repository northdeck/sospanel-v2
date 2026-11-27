<?php

namespace App\Northdeck\Sso\Oidc;

use App\Northdeck\Sso\Claims;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\Support\IdpHttp;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenID Connect, authorization code flow with PKCE. The ID token's signature
 * and times are checked by firebase/php-jwt; this class checks what belongs to
 * the flow: issuer, audience, nonce, and the preset's own rules.
 */
final class OidcClient
{
    public function __construct(private readonly Discovery $discovery, private readonly Jwks $jwks) {}

    /**
     * @param  array{state: string, nonce: string, verifier: string}  $flow
     */
    public function authorizationUrl(SsoConnection $connection, array $flow, ?string $loginHint = null, bool $selectAccount = false): string
    {
        $doc = $this->discovery->document($this->issuer($connection));

        $params = array_filter([
            'response_type' => 'code',
            'client_id' => $connection->stringSetting('client_id'),
            'redirect_uri' => $connection->callbackUrl(),
            'scope' => $connection->stringSetting('scopes', 'openid email profile'),
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => self::challenge($flow['verifier']),
            'code_challenge_method' => 'S256',
            'login_hint' => $loginHint,
            'prompt' => $selectAccount ? 'select_account' : null,
            'hd' => $connection->preset === 'google' ? ($connection->stringSetting('hosted_domain') ?: null) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $endpoint = (string) $doc['authorization_endpoint'];

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{state: string, nonce: string, verifier: string} */
    public static function newFlow(): array
    {
        return [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
        ];
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Swaps the code for tokens and checks the ID token.
     *
     * @param  array{state: string, nonce: string, verifier: string}  $flow
     */
    public function complete(SsoConnection $connection, string $code, array $flow): Claims
    {
        $doc = $this->discovery->document($this->issuer($connection));
        $clientId = $connection->stringSetting('client_id');
        $secret = (string) $connection->client_secret;

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $connection->callbackUrl(),
            'code_verifier' => $flow['verifier'],
            'client_id' => $clientId,
        ];

        $methods = is_array($doc['token_endpoint_auth_methods_supported'] ?? null) ? $doc['token_endpoint_auth_methods_supported'] : ['client_secret_basic'];
        $basic = $secret !== '' && in_array('client_secret_basic', $methods, true);
        if ($secret !== '' && ! $basic) {
            $form['client_secret'] = $secret;
        }

        $response = IdpHttp::postForm((string) $doc['token_endpoint'], $form, $basic ? $clientId : null, $basic ? $secret : null);
        $tokens = $response->json();
        if (! $response->successful() || ! is_array($tokens) || ! is_string($tokens['id_token'] ?? null)) {
            $error = is_array($tokens) && is_string($tokens['error_description'] ?? $tokens['error'] ?? null) ? ': '.($tokens['error_description'] ?? $tokens['error']) : '';
            throw new SsoException('The identity provider didn’t accept the sign-in'.Str::limit($error, 200));
        }

        $claims = $this->verifyIdToken($connection, $doc, $tokens['id_token'], $flow['nonce']);

        // Ask UserInfo for anything the ID token left out that we act on:
        // the email, whether it's verified, the name and the groups.
        $info = [];
        if ($this->missingClaims($connection, $claims) !== [] && is_string($doc['userinfo_endpoint'] ?? null) && is_string($tokens['access_token'] ?? null)) {
            $info = $this->userInfo((string) $doc['userinfo_endpoint'], $tokens['access_token'], (string) $claims['sub']);
        }

        return $this->toClaims($connection, $claims, $info, [
            'protocol' => 'oidc',
            'id_token' => $tokens['id_token'],
            'end_session_endpoint' => is_string($doc['end_session_endpoint'] ?? null) ? $doc['end_session_endpoint'] : '',
            'sid' => is_string($claims['sid'] ?? null) ? $claims['sid'] : '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public function verifyIdToken(SsoConnection $connection, array $doc, string $jwt, string $nonce): array
    {
        $kid = $this->kidOf($jwt);
        $uri = (string) $doc['jwks_uri'];
        $keys = $this->jwks->keys($uri);
        if ($kid !== null && ! isset($keys[$kid])) {
            $keys = $this->jwks->keys($uri, fresh: true);
        }

        $key = $kid === null && count($keys) === 1 ? array_values($keys)[0] : $keys;

        $leeway = JWT::$leeway;
        JWT::$leeway = max(0, (int) config('northdeck.sso.clock_skew', 120));
        try {
            /** @var array<string, mixed> $claims */
            $claims = json_decode((string) json_encode(JWT::decode($jwt, $key instanceof Key ? $key : $keys)), true);
        } catch (Throwable $e) {
            throw new SsoException('The ID token didn’t check out ('.Str::limit($e->getMessage(), 120).').');
        } finally {
            JWT::$leeway = $leeway;
        }

        $clientId = $connection->stringSetting('client_id');
        $tenant = is_string($claims['tid'] ?? null) ? $claims['tid'] : null;
        $expectedIssuer = str_replace('{tenantid}', (string) $tenant, rtrim((string) $doc['issuer'], '/'));

        if (! is_string($claims['iss'] ?? null) || rtrim($claims['iss'], '/') !== $expectedIssuer) {
            throw new SsoException('The ID token is from a different issuer.');
        }
        if (! isset($claims['exp'], $claims['iat']) || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new SsoException('The ID token is missing sub, exp or iat.');
        }

        $audience = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (! in_array($clientId, $audience, true)) {
            throw new SsoException('The ID token is for a different app.');
        }
        if ((count($audience) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $clientId) {
            throw new SsoException('The ID token is for a different app.');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SsoException('The ID token doesn’t belong to this sign-in.');
        }

        if ($connection->preset === 'entra') {
            $pinned = $this->entraTenant($connection);
            if ($pinned !== null && strcasecmp($pinned, (string) $tenant) !== 0) {
                throw new SsoException('That account is in a different Microsoft tenant.');
            }
        }

        if ($connection->preset === 'google' && ($hd = $connection->stringSetting('hosted_domain')) !== '') {
            if (strcasecmp((string) ($claims['hd'] ?? ''), $hd) !== 0) {
                throw new SsoException("Sign in with your {$hd} Google account.");
            }
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private function userInfo(string $endpoint, string $accessToken, string $subject): array
    {
        try {
            $response = IdpHttp::for($endpoint)->withToken($accessToken)->get($endpoint);
        } catch (Throwable) {
            return [];
        }
        $info = $response->successful() ? $response->json() : null;

        // A userinfo answer for anyone else is ignored, as the spec says.
        return is_array($info) && ($info['sub'] ?? null) === $subject ? $info : [];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    public function missingClaims(SsoConnection $connection, array $claims): array
    {
        $wanted = [
            $connection->stringSetting('email_claim', 'email'),
            'email_verified',
            $connection->stringSetting('name_claim', 'name'),
            $connection->groupsClaim(),
        ];

        return array_values(array_filter(array_unique($wanted), fn (string $claim) => ! array_key_exists($claim, $claims)));
    }

    /**
     * The ID token wins; UserInfo only fills what it left out. The email and
     * whether it's verified always come from the same place, so a "verified"
     * from one source never vouches for an email from the other.
     *
     * @param  array<string, mixed>  $claims  from the ID token
     * @param  array<string, mixed>  $info  from UserInfo (already checked to be for the same sub)
     * @param  array<string, string>  $session
     */
    private function toClaims(SsoConnection $connection, array $claims, array $info, array $session): Claims
    {
        $emailClaim = $connection->stringSetting('email_claim', 'email');
        $emailOf = fn (array $source): string => Str::lower(trim(is_string($source[$emailClaim] ?? null) ? $source[$emailClaim] : ''));

        $email = $emailOf($claims);
        $verified = $this->emailVerified($connection, $claims);
        if ($email !== '' && ! array_key_exists('email_verified', $claims) && $emailOf($info) === $email) {
            // Same address in UserInfo, which says whether it's verified.
            $verified = $this->emailVerified($connection, $info);
        } elseif ($email === '' && $emailOf($info) !== '') {
            $email = $emailOf($info);
            $verified = $this->emailVerified($connection, $info);
        }

        if ($email === '' && $connection->preset === 'entra' && is_string($claims['preferred_username'] ?? null) && str_contains($claims['preferred_username'], '@')) {
            $email = Str::lower($claims['preferred_username']);
            $verified = $this->emailVerified($connection, $claims);
        }

        $subject = (string) $claims['sub'];
        if ($connection->preset === 'entra' && is_string($claims['oid'] ?? null)) {
            // oid is the same for a person across every app in the tenant; sub isn't.
            $subject = ((string) ($claims['tid'] ?? '')).'/'.$claims['oid'];
        }

        $merged = $claims + $info;
        $nameClaim = $connection->stringSetting('name_claim', 'name');
        $name = is_string($merged[$nameClaim] ?? null) ? $merged[$nameClaim] : trim(((string) ($merged['given_name'] ?? '')).' '.((string) ($merged['family_name'] ?? '')));

        return new Claims(
            subject: $subject,
            email: $email,
            emailVerified: $verified,
            name: $name,
            groups: Claims::groupsFrom($merged[$connection->groupsClaim()] ?? null),
            raw: $info === [] ? $claims : [...$claims, 'userinfo' => $info],
            session: $session,
        );
    }

    /**
     * Only a provider that vouches for the email gets linked to an existing
     * account. Entra doesn't send email_verified: we trust its email when the
     * connection is pinned to one tenant, or when it says the domain owner
     * verified it (xms_edov).
     *
     * @param  array<string, mixed>  $claims
     */
    private function emailVerified(SsoConnection $connection, array $claims): bool
    {
        $verified = $claims['email_verified'] ?? null;
        if ($verified === true || $verified === 'true') {
            return true;
        }

        if ($connection->preset === 'entra') {
            $edov = $claims['xms_edov'] ?? null;

            return $edov === true || $edov === 'true' || $edov === 1 || $this->entraTenant($connection) !== null;
        }

        return false;
    }

    private function entraTenant(SsoConnection $connection): ?string
    {
        $tenant = $connection->stringSetting('tenant');

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant) === 1 ? $tenant : null;
    }

    public function issuer(SsoConnection $connection): string
    {
        if ($connection->preset === 'entra') {
            $tenant = $connection->stringSetting('tenant', 'organizations');

            return "https://login.microsoftonline.com/{$tenant}/v2.0";
        }

        return $connection->stringSetting('issuer');
    }

    private function kidOf(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoException('The ID token isn’t a JWT.');
        }

        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
        } catch (Throwable) {
            throw new SsoException('The ID token isn’t a JWT.');
        }

        return is_object($header) && isset($header->kid) && is_string($header->kid) ? $header->kid : null;
    }

    /** RP-initiated logout, when the provider offers it. */
    public static function logoutUrl(string $endSession, string $idToken, string $returnTo, string $clientId): string
    {
        return $endSession.(str_contains($endSession, '?') ? '&' : '?').http_build_query([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => $returnTo,
            'client_id' => $clientId,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
