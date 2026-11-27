<?php

namespace App\Northdeck\Sso\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Workspace;
use App\Northdeck\Sso\Claims;
use App\Northdeck\Sso\Directory;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Oidc\OidcClient;
use App\Northdeck\Sso\Saml\SamlService;
use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\SsoSignIn;
use App\Northdeck\Sso\Support\SsoUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The sign-in side of SSO. Everything here lives on the panel's canonical
 * host (APP_URL), whatever host the person started from.
 */
final class SsoController extends Controller
{
    private const string PENDING = 'northdeck_sso_pending';

    private const string UNSOLICITED = 'northdeck_sso_unsolicited';

    private const int PENDING_MINUTES = 15;

    public function __construct(
        private readonly OidcClient $oidc,
        private readonly SamlService $saml,
        private readonly SsoSignIn $signIn,
    ) {}

    /** Email first: "Sign in with SSO". */
    public function index(Request $request): SymfonyResponse
    {
        return Inertia::render('auth/NorthdeckSso', [
            'email' => (string) $request->query('email', ''),
            'error' => $request->session()->get('northdeck_sso_error'),
        ])->toResponse($request);
    }

    public function lookup(Request $request): SymfonyResponse
    {
        $email = Str::lower(trim((string) $request->validate(['email' => ['required', 'email', 'max:255']])['email']));
        $connection = Directory::forEmail($email);

        if ($connection === null) {
            return back()->withInput()->withErrors(['email' => __('There’s no SSO for :domain. Sign in another way, or ask your admin.', ['domain' => Str::after($email, '@')])]);
        }

        // A full page visit: the identity provider is on another site.
        return Inertia::location(SsoUrls::route('northdeck.sso.start', $connection).'?'.http_build_query(['login_hint' => $email]));
    }

    /** /sso/{workspace}: straight to the workspace's identity provider. */
    public function workspace(Request $request, string $workspace): SymfonyResponse
    {
        $found = Workspace::query()->where('slug', Str::lower($workspace))->first();
        $connections = $found === null ? collect() : Directory::forWorkspace($found);

        if ($connections->count() === 1) {
            return redirect()->to(SsoUrls::route('northdeck.sso.start', $connections->first()));
        }

        return Inertia::render('auth/NorthdeckSso', [
            'email' => '',
            'error' => $connections->isEmpty() ? __('That workspace doesn’t sign in with SSO.') : null,
            'workspace' => $found?->name,
            'connections' => $connections->map(fn (SsoConnection $c) => [
                'name' => $c->name,
                'url' => SsoUrls::route('northdeck.sso.start', $c),
            ])->values()->all(),
        ])->toResponse($request);
    }

    /** Sends the browser to the identity provider. ?test=1 is a dry run. */
    public function start(Request $request, SsoConnection $connection): RedirectResponse
    {
        // Start on the canonical host, so the session that comes back is ours.
        if ($request->getHost() !== SsoUrls::host() && SsoUrls::host() !== '') {
            return redirect()->to(SsoUrls::route('northdeck.sso.start', $connection).($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $test = $request->boolean('test');
        if ($test) {
            $workspace = $connection->workspace;
            abort_unless($request->user() !== null && $workspace !== null && Gate::allows('manageSettings', $workspace), 403);
        } elseif (! $connection->enabled || ($connection->workspace !== null && ! $connection->workspace->hasSso())) {
            return $this->toLogin($request, __('This SSO connection is turned off.'));
        }

        $hint = (string) $request->query('login_hint', '');
        $hint = filter_var($hint, FILTER_VALIDATE_EMAIL) !== false ? $hint : null;

        try {
            if ($connection->isOidc()) {
                $flow = OidcClient::newFlow();
                $url = $this->oidc->authorizationUrl($connection, $flow, $hint, $test);
                $this->remember($request, $flow['state'], [...$flow, 'connection' => $connection->uuid, 'test' => $test, 'user_id' => $request->user()?->id]);

                return redirect()->away($url);
            }

            $relay = Str::random(32);
            $login = $this->saml->loginUrl($connection, $relay, $test);
            $this->remember($request, $login['request_id'], ['connection' => $connection->uuid, 'test' => $test, 'user_id' => $request->user()?->id]);

            return redirect()->away($login['url']);
        } catch (SsoException $e) {
            return $test ? $this->testResult($connection, null, $e->getMessage()) : $this->toLogin($request, $e->getMessage());
        }
    }

    public function oidcCallback(Request $request): SymfonyResponse
    {
        $state = (string) $request->query('state', '');
        $pending = $state === '' ? null : $this->forget($request, $state);
        $connection = $pending === null ? null : SsoConnection::query()->where('uuid', $pending['connection'] ?? '')->first();

        if ($connection === null) {
            return $this->toLogin($request, __('That sign-in expired or was started in another browser. Try again.'));
        }

        try {
            if ($request->filled('error')) {
                throw new SsoException(__('The identity provider said no: :error', ['error' => Str::limit((string) ($request->query('error_description') ?: $request->query('error')), 200)]));
            }
            $claims = $this->oidc->complete($connection, (string) $request->query('code', ''), [
                'state' => $state,
                'nonce' => (string) ($pending['nonce'] ?? ''),
                'verifier' => (string) ($pending['verifier'] ?? ''),
            ]);
        } catch (SsoException $e) {
            return $this->finishFailed($request, $connection, $pending, $e->getMessage());
        }

        return $this->finish($request, $connection, $pending, $claims);
    }

    /**
     * The provider posts here from its own site, so the browser doesn't send
     * our session cookie (SameSite=Lax). Check the response, park the result
     * for a minute, and hop to a same-site GET that has the session.
     */
    public function acs(Request $request, SsoConnection $connection): RedirectResponse
    {
        $token = Str::random(48);

        try {
            $result = $this->saml->consume($connection, (string) $request->input('SAMLResponse', ''));
            Cache::put('northdeck:sso:saml:result:'.$token, [
                'connection' => $connection->uuid,
                'in_response_to' => $result['in_response_to'],
                'claims' => serialize($result['claims']),
            ], 120);
        } catch (SsoException $e) {
            Cache::put('northdeck:sso:saml:result:'.$token, ['connection' => $connection->uuid, 'error' => $e->getMessage()], 120);
        }

        return redirect()->to(SsoUrls::route('northdeck.sso.saml.finish', $connection).'?'.http_build_query(['result' => $token]), 303);
    }

    public function samlFinish(Request $request, SsoConnection $connection): SymfonyResponse
    {
        $result = Cache::pull('northdeck:sso:saml:result:'.Str::limit((string) $request->query('result', ''), 64, ''));
        if (! is_array($result) || ($result['connection'] ?? null) !== $connection->uuid) {
            return $this->toLogin($request, __('That sign-in expired. Try again.'));
        }

        $inResponseTo = $result['in_response_to'] ?? null;
        $pending = is_string($inResponseTo) ? $this->forget($request, $inResponseTo) : ['test' => false];

        if (is_string($inResponseTo) && $pending === null) {
            return $this->finishFailed($request, $connection, ['test' => false], __('This SAML response answers a sign-in this browser didn’t start. Try again from the start.'));
        }
        if (isset($result['error'])) {
            return $this->finishFailed($request, $connection, (array) $pending, (string) $result['error']);
        }

        $claims = unserialize((string) $result['claims'], ['allowed_classes' => [Claims::class]]);
        if (! $claims instanceof Claims) {
            return $this->toLogin($request, __('That sign-in expired. Try again.'));
        }

        // Started at the identity provider, so nothing ties it to this
        // browser: a page on another site could have posted it (login CSRF).
        // Ask the person to confirm before signing anyone in.
        if ($inResponseTo === null) {
            $token = Str::random(40);
            $request->session()->put(self::UNSOLICITED.'.'.$token, [
                'connection' => $connection->uuid,
                'claims' => serialize($claims),
                'at' => now()->getTimestamp(),
            ]);

            return Inertia::render('auth/NorthdeckSsoConfirm', [
                'connection' => $connection->name,
                'email' => $claims->email,
                'signedInAs' => $request->user()?->email,
                'confirmUrl' => SsoUrls::route('northdeck.sso.saml.confirm', $connection),
                'token' => $token,
            ])->toResponse($request);
        }

        return $this->finish($request, $connection, (array) $pending, $claims);
    }

    /** The person pressed Continue on the confirmation page (a CSRF-checked POST). */
    public function samlConfirm(Request $request, SsoConnection $connection): SymfonyResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:40']])['token'];
        $parked = $request->session()->pull(self::UNSOLICITED.'.'.$token);

        if (! is_array($parked) || ($parked['connection'] ?? null) !== $connection->uuid
            || ($parked['at'] ?? 0) < now()->subMinutes(5)->getTimestamp()) {
            return $this->toLogin($request, __('That sign-in expired. Try again.'));
        }

        $claims = unserialize((string) $parked['claims'], ['allowed_classes' => [Claims::class]]);
        if (! $claims instanceof Claims) {
            return $this->toLogin($request, __('That sign-in expired. Try again.'));
        }

        $response = $this->finish($request, $connection, ['test' => false], $claims);

        // The page posted with Inertia; the next stop may be another host.
        return $response instanceof RedirectResponse && $request->header('X-Inertia')
            ? Inertia::location($response->getTargetUrl())
            : $response;
    }

    public function metadata(SsoConnection $connection): Response
    {
        abort_unless($connection->isSaml(), 404);

        try {
            $xml = $this->saml->metadataXml($connection);
        } catch (SsoException $e) {
            abort(500, $e->getMessage());
        }

        return response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    /** SAML single logout, both directions. */
    public function slo(Request $request, SsoConnection $connection): RedirectResponse
    {
        abort_unless($connection->isSaml(), 404);

        try {
            $result = $this->saml->handleSlo($connection, $request);
        } catch (SsoException $e) {
            return $this->toLogin($request, $e->getMessage());
        }

        if ($result['kind'] === 'request') {
            $marker = $request->session()->get(SsoSignIn::SESSION);
            $user = $request->user();
            if ($user !== null && is_array($marker) && ($marker['connection'] ?? null) === $connection->uuid
                && ($result['name_id'] === null || ($marker['idp']['name_id'] ?? null) === $result['name_id'])) {
                if (($workspace = $this->signIn->workspaceOf($connection)) !== null) {
                    Activity::record($workspace, 'sso.logout_by_idp', $user->name, user: $user, meta: ['connection' => $connection->name]);
                }
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->away($result['redirect'] ?? SsoUrls::route('login'));
        }

        return redirect()->route('login')->with('status', __('You’re signed out.'));
    }

    public function signedOut(): RedirectResponse
    {
        return redirect()->route('login')->with('status', __('You’re signed out.'));
    }

    /** @param  array<string, mixed>  $pending */
    private function finish(Request $request, SsoConnection $connection, array $pending, Claims $claims): SymfonyResponse
    {
        if ((bool) ($pending['test'] ?? false)) {
            return $this->testResult($connection, $claims, null);
        }

        try {
            return $this->signIn->signIn($request, $connection, $claims);
        } catch (SsoException $e) {
            return $this->toLogin($request, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $pending */
    private function finishFailed(Request $request, SsoConnection $connection, array $pending, string $message): SymfonyResponse
    {
        if ((bool) ($pending['test'] ?? false)) {
            return $this->testResult($connection, null, $message);
        }

        $this->signIn->failed($connection, $message);

        return $this->toLogin($request, $message);
    }

    /** The test button: shows what came back and what a real sign-in would do. */
    private function testResult(SsoConnection $connection, ?Claims $claims, ?string $error): RedirectResponse
    {
        $plan = $claims === null ? null : $this->signIn->plan($connection, $claims);
        $ok = $claims !== null && $plan !== null && $plan['action'] !== 'refuse';

        $connection->forceFill([
            'last_tested_at' => now(),
            'last_test' => [
                'ok' => $ok,
                'error' => $error,
                'subject' => $claims?->subject,
                'email' => $claims?->email,
                'emailVerified' => $claims?->emailVerified,
                'name' => $claims?->name,
                'groups' => $claims?->groups,
                'role' => $plan['role'] ?? null,
                'plan' => $plan['action'] ?? null,
                'reason' => $plan['reason'] ?? null,
                'claims' => $claims === null ? null : Str::limit((string) json_encode($claims->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 8000),
            ],
        ])->save();

        if ($connection->workspace !== null) {
            Activity::record($connection->workspace, 'sso.connection_test', $connection->name, meta: ['ok' => $ok]);
        }

        Inertia::flash('toast', ['type' => $ok ? 'success' : 'error', 'message' => $ok ? __('The test sign-in worked.') : __('The test sign-in didn’t work.')]);

        return redirect()->to($connection->workspace !== null
            ? $connection->workspace->url('/settings/sso').'#connection-'.$connection->uuid
            : SsoUrls::route('login'));
    }

    private function toLogin(Request $request, string $message): RedirectResponse
    {
        return redirect()->to(SsoUrls::route('northdeck.sso.index'))->with('northdeck_sso_error', $message);
    }

    /** @param  array<string, mixed>  $data */
    private function remember(Request $request, string $key, array $data): void
    {
        $pending = array_filter(
            (array) $request->session()->get(self::PENDING, []),
            fn ($p) => is_array($p) && ($p['at'] ?? 0) > now()->subMinutes(self::PENDING_MINUTES)->getTimestamp(),
        );
        $pending[$key] = [...$data, 'at' => now()->getTimestamp()];
        $request->session()->put(self::PENDING, array_slice($pending, -5, null, true));
    }

    /** @return array<string, mixed>|null */
    private function forget(Request $request, string $key): ?array
    {
        $pending = (array) $request->session()->get(self::PENDING, []);
        $found = $pending[$key] ?? null;
        unset($pending[$key]);
        $request->session()->put(self::PENDING, $pending);

        if (! is_array($found) || ($found['at'] ?? 0) < now()->subMinutes(self::PENDING_MINUTES)->getTimestamp()) {
            return null;
        }

        // A test only counts for the person who started it.
        if (($found['test'] ?? false) && ($found['user_id'] ?? null) !== $request->user()?->id) {
            return null;
        }

        return $found;
    }
}
