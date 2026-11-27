<?php

namespace Tests\Support\Northdeck;

use App\Northdeck\Sso\Support\IdpHttp;
use DOMDocument;
use DOMXPath;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * A pretend identity provider for the SSO tests: an OpenID Connect issuer
 * with real RSA keys behind Http::fake, and a SAML IdP that signs real
 * assertions. Keys are made once per test run.
 */
final class FakeIdp
{
    public const string ISSUER = 'https://idp.test';

    public const string CLIENT_ID = 'panel-client';

    /** @var array<string, array{key: string, cert: string, kid: string}> */
    private static array $keys = [];

    /** @var array<string, mixed>|null claims for the next ID token */
    public static ?array $nextClaims = null;

    public static string $signingKid = 'key-1';

    private static ?string $faked = null;

    /** @var list<string> keys the JWKS publishes right now */
    public static array $publishedKids = ['key-1'];

    /** @var array<string, mixed> what the UserInfo endpoint answers */
    public static array $userInfo = [];

    /** @var int how many times UserInfo was asked */
    public static int $userInfoCalls = 0;

    /** @var list<array<string, mixed>> token requests seen */
    public static array $tokenRequests = [];

    /** @return array{key: string, cert: string, kid: string} */
    public static function key(string $kid = 'key-1'): array
    {
        if (! isset(self::$keys[$kid])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'idp.test'], $key, ['digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
            openssl_pkey_export($key, $pem);
            openssl_x509_export($cert, $certPem);
            self::$keys[$kid] = ['key' => $pem, 'cert' => $certPem, 'kid' => $kid];
        }

        return self::$keys[$kid];
    }

    /** @return array<string, mixed> */
    public static function jwk(string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::key($kid)['key']));
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    }

    /**
     * Fakes discovery, keys and the token endpoint.
     *
     * @param  list<string>  $publishedKids  keys in the JWKS
     */
    public static function oidc(array $publishedKids = ['key-1'], string $issuer = self::ISSUER): void
    {
        self::$tokenRequests = [];
        self::$publishedKids = $publishedKids;
        self::$userInfo = [];
        self::$userInfoCalls = 0;
        // The fake IdP's hosts don't exist; answer DNS with a public address.
        IdpHttp::$resolver = fn (string $host) => ['93.184.216.34'];

        // Registered once per test; later calls only change what's published.
        if (self::$faked === $issuer.spl_object_id(app())) {
            return;
        }
        self::$faked = $issuer.spl_object_id(app());

        Http::fake([
            $issuer.'/.well-known/openid-configuration' => Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => $issuer.'/authorize',
                'token_endpoint' => $issuer.'/token',
                'jwks_uri' => $issuer.'/jwks',
                'userinfo_endpoint' => $issuer.'/userinfo',
                'end_session_endpoint' => $issuer.'/logout',
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'code_challenge_methods_supported' => ['S256'],
            ]),
            $issuer.'/jwks' => fn () => Http::response(['keys' => array_map(fn ($kid) => self::jwk($kid), self::$publishedKids)]),
            $issuer.'/token' => function ($request) {
                self::$tokenRequests[] = $request->data();

                return Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => self::idToken(self::$nextClaims ?? [])]);
            },
            $issuer.'/userinfo' => function () {
                self::$userInfoCalls++;

                return Http::response(self::$userInfo);
            },
        ]);
    }

    /** @param  array<string, mixed>  $claims */
    public static function idToken(array $claims, ?string $kid = null): string
    {
        $kid ??= self::$signingKid;

        return JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 300,
            ...$claims,
        ], self::key($kid)['key'], 'RS256', $kid);
    }

    /** @return array{state: string, nonce: string, query: array<string, string>} from the authorize redirect */
    public static function authorizeParams(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return ['state' => (string) ($query['state'] ?? ''), 'nonce' => (string) ($query['nonce'] ?? ''), 'query' => $query];
    }

    /**
     * A SAML Response with a signed assertion, base64 encoded for posting.
     *
     * @param  array<string, list<string>>  $attributes
     */
    public static function samlResponse(
        string $acs,
        string $audience,
        string $nameId,
        array $attributes,
        ?string $inResponseTo = null,
        string $issuer = 'https://idp.test/saml',
        bool $sign = true,
        int $notOnOrAfter = 300,
        string $kid = 'saml-1',
        ?string $assertionId = null,
    ): string {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $later = gmdate('Y-m-d\TH:i:s\Z', time() + $notOnOrAfter);
        $before = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $irt = $inResponseTo === null ? '' : ' InResponseTo="'.e($inResponseTo).'"';
        $assertionId ??= '_a'.Str::random(32);

        $attrs = '';
        foreach ($attributes as $name => $values) {
            $attrs .= '<saml:Attribute Name="'.e($name).'">';
            foreach ($values as $value) {
                $attrs .= '<saml:AttributeValue xsi:type="xs:string">'.e($value).'</saml:AttributeValue>';
            }
            $attrs .= '</saml:Attribute>';
        }

        $assertion = <<<XML
<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="{$assertionId}" Version="2.0" IssueInstant="{$now}"><saml:Issuer>{$issuer}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:persistent">{$nameId}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="{$later}" Recipient="{$acs}"{$irt}/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$before}" NotOnOrAfter="{$later}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$now}" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement><saml:AttributeStatement>{$attrs}</saml:AttributeStatement></saml:Assertion>
XML;

        $doc = new DOMDocument;
        $doc->loadXML('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r'.Str::random(32).'" Version="2.0" IssueInstant="'.$now.'" Destination="'.e($acs).'"'.$irt.'><saml:Issuer>'.$issuer.'</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'.$assertion.'</samlp:Response>');

        if ($sign) {
            $node = (new DOMXPath($doc))->query('//*[local-name()="Assertion"]')->item(0);
            $issuerNode = (new DOMXPath($doc))->query('./*[local-name()="Issuer"]', $node)->item(0);

            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
            $key->loadKey(self::key($kid)['key']);
            $dsig = new XMLSecurityDSig;
            $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
            $dsig->addReferenceList([$node], XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID']);
            $dsig->sign($key);
            $dsig->add509Cert(self::key($kid)['cert']);
            $dsig->insertSignature($node, $issuerNode->nextSibling);
        }

        return base64_encode((string) $doc->saveXML());
    }
}
