<?php

namespace App\Northdeck\Sso;

use App\Northdeck\Scim\AuthenticateScim;
use App\Northdeck\Scim\ScimController;
use App\Northdeck\Sso\Http\Controllers\ScimTokenController;
use App\Northdeck\Sso\Http\Controllers\SsoController;
use App\Northdeck\Sso\Http\Controllers\SsoSettingsController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

final class Routes
{
    /** Extensions::SIGN_IN: every host, the web group. */
    public static function signIn(): void
    {
        Route::prefix('sso')->name('northdeck.sso.')->middleware('throttle:30,1')->group(function (): void {
            Route::get('/', [SsoController::class, 'index'])->name('index');
            Route::post('/', [SsoController::class, 'lookup'])->name('lookup');
            Route::get('callback', [SsoController::class, 'oidcCallback'])->name('oidc.callback');
            Route::get('signed-out', [SsoController::class, 'signedOut'])->name('signed-out');

            Route::prefix('connections/{connection}')->group(function (): void {
                Route::get('start', [SsoController::class, 'start'])->name('start');
                Route::post('saml/acs', [SsoController::class, 'acs'])
                    ->withoutMiddleware(PreventRequestForgery::class)
                    ->name('saml.acs');
                Route::get('saml/finish', [SsoController::class, 'samlFinish'])->name('saml.finish');
                Route::get('saml/metadata', [SsoController::class, 'metadata'])->name('saml.metadata');
                Route::get('saml/slo', [SsoController::class, 'slo'])->name('saml.slo');
            });

            Route::get('{workspace}', [SsoController::class, 'workspace'])
                ->where('workspace', '^(?!callback$|signed-out$|connections$)[a-z0-9][a-z0-9-]*$')
                ->name('workspace');
        });
    }

    /** Extensions::WORKSPACE: <dashboard host>/{workspace}/... */
    public static function workspace(): void
    {
        Route::get('settings/sso', [SsoSettingsController::class, 'show'])->name('workspace.settings.sso');
        Route::post('settings/sso/connections', [SsoSettingsController::class, 'store'])->middleware('throttle:20,1')->name('workspace.settings.sso.store');
        Route::put('settings/sso/connections/{connection}', [SsoSettingsController::class, 'update'])->middleware('throttle:20,1')->name('workspace.settings.sso.update');
        Route::delete('settings/sso/connections/{connection}', [SsoSettingsController::class, 'destroy'])->name('workspace.settings.sso.destroy');
        Route::post('settings/sso/connections/{connection}/groups', [SsoSettingsController::class, 'addMapping'])->name('workspace.settings.sso.groups.store');
        Route::delete('settings/sso/connections/{connection}/groups/{mapping}', [SsoSettingsController::class, 'removeMapping'])->name('workspace.settings.sso.groups.destroy');
        Route::put('settings/sso/require', [SsoSettingsController::class, 'updateRequire'])->name('workspace.settings.sso.require');
        Route::post('settings/sso/scim-token', [ScimTokenController::class, 'store'])->name('workspace.settings.sso.scim.store');
        Route::delete('settings/sso/scim-token', [ScimTokenController::class, 'destroy'])->name('workspace.settings.sso.scim.destroy');
    }

    /** Extensions::API: /api, the api group. SCIM 2.0 (preview). */
    public static function api(): void
    {
        Route::prefix('scim/v2')->name('northdeck.scim.')->middleware([AuthenticateScim::class, 'throttle:600,1'])->group(function (): void {
            Route::get('ServiceProviderConfig', [ScimController::class, 'serviceProviderConfig'])->name('config');
            Route::get('ResourceTypes', [ScimController::class, 'resourceTypes'])->name('resource-types');
            Route::get('Schemas', [ScimController::class, 'schemas'])->name('schemas');
            Route::get('Users', [ScimController::class, 'users'])->name('users');
            Route::post('Users', [ScimController::class, 'createUser'])->name('users.store');
            Route::get('Users/{id}', [ScimController::class, 'showUser'])->name('users.show');
            Route::put('Users/{id}', [ScimController::class, 'replaceUser'])->name('users.replace');
            Route::patch('Users/{id}', [ScimController::class, 'patchUser'])->name('users.patch');
            Route::delete('Users/{id}', [ScimController::class, 'deleteUser'])->name('users.destroy');
            Route::get('Groups', [ScimController::class, 'groups'])->name('groups');
            Route::post('Groups', [ScimController::class, 'createGroup'])->name('groups.store');
            Route::get('Groups/{id}', [ScimController::class, 'showGroup'])->name('groups.show');
            Route::put('Groups/{id}', [ScimController::class, 'replaceGroup'])->name('groups.replace');
            Route::patch('Groups/{id}', [ScimController::class, 'patchGroup'])->name('groups.patch');
            Route::delete('Groups/{id}', [ScimController::class, 'deleteGroup'])->name('groups.destroy');
        });
    }
}
