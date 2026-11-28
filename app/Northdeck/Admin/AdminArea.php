<?php

namespace App\Northdeck\Admin;

use App\Models\User;
use App\Northdeck\Admin\Http\EnsureAdminPages;
use App\Northdeck\Admin\Http\PanelSettingsController;
use App\Northdeck\Admin\Http\UsersController;
use App\Support\Extensions\AdminNavItem;
use App\Support\Extensions\Extensions;
use Illuminate\Support\Facades\Route;

/**
 * Northdeck's /admin pages: the panel's name and addresses, and the people
 * with accounts. Open to instance admins (php artisan admin:grant) with a
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
            key: 'northdeck-panel', label: 'Panel settings', icon: 'pen-line', path: '/admin/panel',
            group: 'platform', components: ['admin/NorthdeckPanel'], before: 'pulse', visible: $visible,
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
        Route::get('panel', [PanelSettingsController::class, 'show'])->name('northdeck.panel');
        Route::put('panel', [PanelSettingsController::class, 'update'])->middleware('throttle:30,1')->name('northdeck.panel.update');
        // Named admin.users so the panel's own "new signup" links find it.
        Route::get('users', [UsersController::class, 'index'])->name('users');
        Route::put('users/{user}', [UsersController::class, 'update'])->middleware('throttle:60,1')->name('northdeck.users.update');
    }
}
