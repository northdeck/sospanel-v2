<?php

namespace App\Northdeck\Sso\Saml;

use App\Northdeck\Sso\Claims;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\Support\IdpHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\Response as SamlResponse;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * SAML 2.0 through onelogin/php-saml. Assertions must be signed; encrypted
 * assertions are optional. Every assertion ID is remembered until it expires,
 * so a captured response can't be posted twice.
 */
final class SamlService
{
    public const array EMAIL_ATTRIBUTES = [
        'email', 'mail', 'emailaddress', 'Email',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'urn:oid:0.9.2342.19200300.100.1.3',
    ];

    public const array NAME_ATTRIBUTES = [
        'name', 'displayName', 'displayname',
        'http://schemas.microsoft.com/identity/claims/displayname',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
        'urn:oid:2.16.840.1.113730.3.1.241',
    ];

    public const array GROUP_ATTRIBUTES = [
        'groups', 'memberOf', 'Group',
        'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
        'http://schemas.xmlsoap.org/claims/Group',
    ];

    /** @return array<string, mixed> */
    public function settings(SsoConnection $connection): array
    {
        $certs = array_values(array_filter(array_map('strval', (array) $connection->setting('idp_certificates', []))));

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $connection->entityId(),
                'assertionConsumerService' => ['url' => $connection->acsUrl(), 'binding' => Constants::BINDING_HTTP_POST],
                'singleLogoutService' => ['url' => $connection->sloUrl(), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'NameIDFormat' => $connection->stringSetting('name_id_format', Constants::NAMEID_UNSPECIFIED),
                'x509cert' => (string) $connection->sp_certificate,
                'privateKey' => (string) $connection->sp_private_key,
            ],
            'idp' => [
                'entityId' => $connection->stringSetting('idp_entity_id'),
                'singleSignOnService' => ['url' => $connection->stringSetting('idp_sso_url'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'singleLogoutService' => ['url' => $connection->stringSetting('idp_slo_url'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509certMulti' => ['signing' => $certs, 'encryption' => $certs !== [] ? [$certs[0]] : []],
            ],
            'security' => [
                'authnRequestsSigned' => filled($connection->sp_private_key),
                'logoutRequestSigned' => filled($connection->sp_private_key),
                'logoutResponseSigned' => filled($connection->sp_private_key),
                'wantMessagesSigned' => false,
                'wantAssertionsSigned' => true,
                'wantAssertionsEncrypted' => (bool) $connection->setting('want_encrypted', false),
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => false,
                'wantXMLValidation' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                'rejectUnsolicitedResponsesWithInResponseTo' => false,
                'destinationStrictlyMatches' => true,
                'relaxDestinationValidation' => false,
                'lowercaseUrlencoding' => (bool) $connection->setting('lowercase_urlencoding', false),
            ],
        ];
    }

    public function assertReady(SsoConnection $connection): void
    {
        if ($connection->stringSetting('idp_entity_id') === '' || $connection->stringSetting('idp_sso_url') === '' || $connection->setting('idp_certificates', []) === []) {
            throw new SsoException('Add the identity provider’s metadata first.');
        }
    }

    /** @return array{url: string, request_id: string} */
    public function loginUrl(SsoConnection $connection, string $relayState, bool $forceAuthn = false): array
    {
        $this->assertReady($connection);

        try {
            $auth = new Auth($this->settings($connection));
            $url = $auth->login($relayState, [], $forceAuthn, false, true);
        } catch (Throwable $e) {
            throw new SsoException('The SAML settings aren’t complete ('.Str::limit($e->getMessage(), 120).').');
        }

        return ['url' => (string) $url, 'request_id' => (string) $auth->getLastRequestID()];
    }

    /**
     * Checks a posted SAMLResponse: signature, times, audience, destination
     * and replay. The browser's session isn't sent on this cross-site POST, so
     * whether this browser started the sign-in (InResponseTo) is checked by
     * the caller once it's back on a same-site request.
     *
     * @return array{claims: Claims, in_response_to: string|null}
     */
    public function consume(SsoConnection $connection, string $samlResponse): array
    {
        $this->assertReady($connection);

        try {
            $settings = new Settings($this->settings($connection));
            $response = new SamlResponse($settings, $samlResponse);
        } catch (Throwable $e) {
            throw new SsoException('That isn’t a SAML response we can read.');
        }

        $inResponseTo = $response->document->documentElement?->getAttribute('InResponseTo') ?: null;
        // Unsolicited responses are refused unless an admin turned them on.
        if ($inResponseTo === null && ! (bool) $connection->setting('allow_idp_initiated', false)) {
            throw new SsoException('Sign-ins started from the identity provider are turned off for this connection.');
        }

        $valid = self::atUrl($connection->acsUrl(), [], fn () => $response->isValid($inResponseTo));
        if (! $valid) {
            throw new SsoException('The SAML response didn’t check out ('.Str::limit((string) $response->getError(), 160).').');
        }

        $assertionId = (string) $response->getAssertionId();
        $notOnOrAfter = $response->getAssertionNotOnOrAfter();
        $ttl = max(60, ((int) ($notOnOrAfter ?: time() + 300)) - time() + 180);
        if ($assertionId === '' || ! Cache::add('northdeck:sso:saml:seen:'.$connection->id.':'.sha1($assertionId), true, $ttl)) {
            throw new SsoException('This SAML response was already used.');
        }

        return ['claims' => $this->claims($connection, $response), 'in_response_to' => $inResponseTo];
    }

    private function claims(SsoConnection $connection, SamlResponse $response): Claims
    {
        /** @var array<string, list<string>> $attributes */
        $attributes = $response->getAttributes();
        $nameId = (string) $response->getNameId();
        $format = (string) $response->getNameIdFormat();

        $subjectAttribute = $connection->stringSetting('subject_attribute');
        $subject = $subjectAttribute !== '' ? (string) ($attributes[$subjectAttribute][0] ?? '') : $nameId;
        if ($subjectAttribute === '' && $format === Constants::NAMEID_TRANSIENT) {
            throw new SsoException('The identity provider sent a transient NameID, which changes every sign-in. Send a persistent one, or pick a subject attribute.');
        }
        if ($subject === '') {
            throw new SsoException('The SAML response has no subject.');
        }

        $email = $this->first($attributes, $connection->stringSetting('email_attribute'), self::EMAIL_ATTRIBUTES);
        if ($email === '' && filter_var($nameId, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $nameId;
        }

        $name = $this->first($attributes, $connection->stringSetting('name_attribute'), self::NAME_ATTRIBUTES);
        if ($name === '') {
            $name = trim($this->first($attributes, '', ['givenName', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname']).' '
                .$this->first($attributes, '', ['sn', 'surname', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname']));
        }

        $groupsAttribute = $connection->stringSetting('groups_claim', 'groups');
        $groups = $attributes[$groupsAttribute] ?? null;
        foreach (self::GROUP_ATTRIBUTES as $candidate) {
            $groups ??= $attributes[$candidate] ?? null;
        }

        return new Claims(
            subject: $subject,
            email: Str::lower(trim($email)),
            // The assertion is signed by the provider the workspace set up for
            // its own DNS-verified domains, so the email is the provider's word.
            emailVerified: true,
            name: $name,
            groups: Claims::groupsFrom($groups),
            raw: ['NameID' => $nameId, 'NameIDFormat' => $format, 'attributes' => $attributes],
            session: [
                'protocol' => 'saml',
                'name_id' => $nameId,
                'name_id_format' => $format,
                'session_index' => (string) $response->getSessionIndex(),
            ],
        );
    }

    /**
     * @param  array<string, list<string>>  $attributes
     * @param  list<string>  $fallbacks
     */
    private function first(array $attributes, string $preferred, array $fallbacks): string
    {
        foreach ($preferred !== '' ? [$preferred, ...$fallbacks] : $fallbacks as $name) {
            $value = $attributes[$name][0] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    public function metadataXml(SsoConnection $connection): string
    {
        $settings = new Settings($this->settings($connection), true);
        $xml = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($xml);
        if ($errors !== []) {
            throw new SsoException('The service provider metadata isn’t valid: '.implode(', ', $errors));
        }

        return $xml;
    }

    /** @param  array<string, string>  $session */
    public function logoutUrl(SsoConnection $connection, array $session, string $returnTo): ?string
    {
        if ($connection->stringSetting('idp_slo_url') === '') {
            return null;
        }

        try {
            $auth = new Auth($this->settings($connection));

            return (string) $auth->logout($returnTo, [], $session['name_id'] ?? null, ($session['session_index'] ?? '') ?: null, true, ($session['name_id_format'] ?? '') ?: null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Handles the provider's LogoutRequest or LogoutResponse at the SLO URL.
     *
     * @return array{kind: 'request'|'response', redirect: string|null, name_id: string|null}
     */
    public function handleSlo(SsoConnection $connection, Request $request): array
    {
        $query = $request->query->all();
        $kind = $request->query->has('SAMLRequest') ? 'request' : 'response';
        if (! $request->query->has('SAMLRequest') && ! $request->query->has('SAMLResponse')) {
            throw new SsoException('That isn’t a SAML logout message.');
        }

        $auth = new Auth($this->settings($connection));
        $redirect = self::atUrl($connection->sloUrl(), $query, fn () => $auth->processSLO(true, null, false, null, true));

        if ($auth->getErrors() !== []) {
            throw new SsoException('The logout message didn’t check out ('.Str::limit((string) $auth->getLastErrorReason(), 160).').');
        }

        $nameId = null;
        if ($kind === 'request' && ($xml = $auth->getLastRequestXML()) !== null) {
            try {
                $nameId = (string) LogoutRequest::getNameId($xml, (string) $connection->sp_private_key);
            } catch (Throwable) {
                $nameId = null;
            }
        }

        return ['kind' => $kind, 'redirect' => $redirect !== '' ? $redirect : null, 'name_id' => $nameId];
    }

    /**
     * php-saml compares a message's Destination with the URL it thinks it was
     * posted to, from $_SERVER. Behind proxies and tunnels that's wrong, and in
     * tests it's empty, so pin it to the endpoint's canonical URL.
     *
     * @template T
     *
     * @param  array<string, mixed>  $query
     * @param  callable(): T  $work
     * @return T
     */
    public static function atUrl(string $url, array $query, callable $work): mixed
    {
        $parts = parse_url($url) ?: [];
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $server = ['REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, 'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null];
        $get = $_GET;

        // php-saml keeps the URL it was told in private statics; put back
        // exactly what was there afterwards.
        $statics = [];
        foreach (['_host', '_protocol', '_port', '_baseurlpath'] as $name) {
            $statics[$name] = (new \ReflectionProperty(Utils::class, $name))->getValue();
        }

        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost((string) ($parts['host'] ?? ''));
        Utils::setSelfPort((int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)));
        (new \ReflectionProperty(Utils::class, '_baseurlpath'))->setValue(null, null);
        $_SERVER['REQUEST_URI'] = (string) ($parts['path'] ?? '/');
        unset($_SERVER['QUERY_STRING']);
        $_GET = $query;

        try {
            return $work();
        } finally {
            $_GET = $get;
            foreach ($server as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
            foreach ($statics as $name => $value) {
                (new \ReflectionProperty(Utils::class, $name))->setValue(null, $value);
            }
        }
    }

    /**
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_slo_url: string, idp_certificates: list<string>}
     */
    public static function parseIdpMetadata(string $xml): array
    {
        try {
            $parsed = IdPMetadataParser::parseXML($xml);
        } catch (Throwable) {
            throw new SsoException('That metadata isn’t valid SAML metadata.');
        }

        $idp = $parsed['idp'] ?? [];
        $certs = array_values(array_unique(array_filter([
            ...((array) ($idp['x509certMulti']['signing'] ?? [])),
            (string) ($idp['x509cert'] ?? ''),
        ])));

        if (($idp['entityId'] ?? '') === '' || ($idp['singleSignOnService']['url'] ?? '') === '' || $certs === []) {
            throw new SsoException('The metadata needs an entity ID, a sign-in URL and a signing certificate.');
        }

        return [
            'idp_entity_id' => (string) $idp['entityId'],
            'idp_sso_url' => (string) $idp['singleSignOnService']['url'],
            'idp_slo_url' => (string) ($idp['singleLogoutService']['url'] ?? ''),
            'idp_certificates' => array_map(fn ($c) => self::normalizeCertificate((string) $c), $certs),
        ];
    }

    /** @return array{idp_entity_id: string, idp_sso_url: string, idp_slo_url: string, idp_certificates: list<string>} */
    public static function fetchIdpMetadata(string $url): array
    {
        $client = IdpHttp::for($url)->accept('application/samlmetadata+xml, application/xml, text/xml');
        try {
            $response = $client->get($url);
        } catch (Throwable) {
            throw new SsoException('Couldn’t reach the metadata URL.');
        }
        if (! $response->successful()) {
            throw new SsoException('The metadata URL answered '.$response->status().'.');
        }

        return self::parseIdpMetadata($response->body());
    }

    public static function normalizeCertificate(string $cert): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $cert) ?? '';
    }

    /** @return array{key: string, certificate: string} */
    public static function newKeyPair(string $commonName): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new SsoException('Couldn’t make a signing key.');
        }
        $csr = openssl_csr_new(['commonName' => Str::limit($commonName, 64, '')], $key, ['digest_alg' => 'sha256']);
        $cert = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new SsoException('Couldn’t make a signing certificate.');
        }

        openssl_pkey_export($key, $pem);
        openssl_x509_export($cert, $certPem);

        return ['key' => (string) $pem, 'certificate' => (string) $certPem];
    }
}
