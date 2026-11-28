<?php

namespace App\Northdeck\Admin;

use App\Models\User;
use App\Northdeck\Admin\Http\EnsureAdminPages;
use App\Northdeck\Admin\Http\InvitesController;
use App\Northdeck\Admin\Http\PanelSettingsController;
use App\Northdeck\Admin\Http\UsersController;
use App\Support\Extensions\AdminNavItem;
use App\Support\Extensions\Extensions;
use Illuminate\Support\Facades\Route;

/**
 * Northdeck's /admin pages: the people with accounts (and invites), and the
 * .env settings that can change while the panel runs. Open to instance admins (php artisan admin:grant) with a
 * verified email and two-factor on, like the rest of /admin.
 */
final class AdminArea
{
    public static function register(Extensions $extensions): void
    {
        $extensions->routes(Extensions::ADMIN, self::routes(...));

        $visible = fn (User $user): bool => (bool) config('northdeck.admin.enabled') && $user->can('viewAdmin');

        $extensions->addAdminNavItem(new AdminNavItem(
            key: 'northdeck-users', label: 'Users', icon: 'users', path: '/admin/users',
            group: 'customers', components: ['admin/NorthdeckUsers'], visible: $visible,
        ));
        $extensions->addAdminNavItem(new AdminNavItem(
            key: 'northdeck-settings', label: 'Settings', icon: 'pen-line', path: '/admin/settings',
            group: 'platform', components: ['admin/NorthdeckSettings'], before: 'pulse', visible: $visible,
        ));
    }

    /** Extensions::ADMIN: under /admin, names start with admin. */
    public static function routes(): void
    {
        Route::middleware(EnsureAdminPages::class)->group(self::pages(...));
    }

    private static function pages(): void
    {
        Route::get('/', fn () => redirect('/admin/users'))->name('northdeck.home');
        Route::get('settings', [PanelSettingsController::class, 'show'])->name('northdeck.settings');
        Route::put('settings', [PanelSettingsController::class, 'update'])->middleware('throttle:30,1')->name('northdeck.settings.update');
        Route::get('panel', fn () => redirect('/admin/settings'))->name('northdeck.panel');
        // Named admin.users so the panel's own "new signup" links find it.
        Route::get('users', [UsersController::class, 'index'])->name('users');
        Route::put('users/{user}', [UsersController::class, 'update'])->middleware('throttle:60,1')->name('northdeck.users.update');
        Route::post('invites', [InvitesController::class, 'store'])->middleware('throttle:30,1')->name('northdeck.invites.store');
        Route::post('invites/{invite}/resend', [InvitesController::class, 'resend'])->middleware('throttle:30,1')->name('northdeck.invites.resend');
        Route::delete('invites/{invite}', [InvitesController::class, 'destroy'])->name('northdeck.invites.destroy');
    }
}
