<?php

namespace App\Northdeck\Sso\Http\Middleware;

use App\Models\User;
use App\Northdeck\Sso\Enforcement;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Runs in Fortify's password sign-in pipeline. Only after the password is
 * right does it say SSO is required, so it never tells a stranger whether an
 * email has an account.
 */
final class RefusePasswordWhenSsoRequired
{
    public function handle(Request $request, Closure $next): mixed
    {
        $email = Str::lower(trim((string) $request->input(Fortify::username())));
        $user = $email === '' ? null : User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && Hash::check((string) $request->input('password'), $user->password)
            && ($workspace = Enforcement::blockingWorkspace($user)) !== null) {
            throw ValidationException::withMessages([
                Fortify::username() => __(':workspace asks you to sign in with SSO.', ['workspace' => $workspace->name]),
            ]);
        }

        return $next($request);
    }
}
