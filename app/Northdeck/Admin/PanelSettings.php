<?php

namespace App\Northdeck\Admin;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The panel's name and addresses, as instance admins set them on
 * /admin/panel. A saved value overrides the env value; an empty one goes
 * back to it.
 */
final class PanelSettings
{
    public const string TABLE = 'northdeck_panel_settings';

    private const string CACHE_KEY = 'northdeck.panel_settings';

    /**
     * Each field, the config keys it sets, and how it's checked.
     *
     * @var array<string, array{config: list<string>, rules: list<string>}>
     */
    public const array FIELDS = [
        'name' => ['config' => ['app.name'], 'rules' => ['string', 'max:60']],
        'support_email' => ['config' => ['northdeck.support.email', 'mail.support.address'], 'rules' => ['email', 'max:254']],
        'security_email' => ['config' => ['northdeck.support.security_email'], 'rules' => ['email', 'max:254']],
        'mail_from_address' => ['config' => ['mail.from.address'], 'rules' => ['email', 'max:254']],
        'mail_from_name' => ['config' => ['mail.from.name'], 'rules' => ['string', 'max:60']],
        'support_url' => ['config' => ['northdeck.support.url'], 'rules' => ['url:https,http', 'max:2048']],
        'chat_url' => ['config' => ['northdeck.support.chat_url', 'serveros.brand.discord_url'], 'rules' => ['url:https,http', 'max:2048']],
    ];

    /** @var array<string, string|null>|null what the env (config files) said, before saved values were applied */
    private static ?array $env = null;

    /** @return array<string, string> saved values only */
    public static function saved(): array
    {
        try {
            /** @var array<string, string> */
            return Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable(self::TABLE)
                ? DB::table(self::TABLE)->whereNotNull('value')->pluck('value', 'key')->map(fn ($v) => (string) $v)->all()
                : []);
        } catch (Throwable) {
            // No database yet (first install, a build step): use the env.
            return [];
        }
    }

    /** Puts the saved values over the env ones. Runs at boot. */
    public static function apply(Repository $config): void
    {
        if (! (bool) $config->get('northdeck.admin.enabled')) {
            return;
        }

        self::$env ??= self::read($config);

        foreach (self::saved() as $field => $value) {
            foreach (self::FIELDS[$field]['config'] ?? [] as $key) {
                $config->set($key, $value);
            }
        }
    }

    /**
     * The values a field shows: saved, or what the env says.
     *
     * @return array<string, array{value: string|null, env: string|null, saved: bool}>
     */
    public static function current(Repository $config): array
    {
        $env = self::$env ?? self::read($config);
        $saved = self::saved();

        $out = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $out[$field] = [
                'value' => $saved[$field] ?? $env[$field],
                'env' => $env[$field],
                'saved' => isset($saved[$field]),
            ];
        }

        return $out;
    }

    /**
     * Saves the values; null or '' clears a field back to the env.
     *
     * @param  array<string, string|null>  $values
     * @return list<string> the fields that changed
     */
    public static function save(array $values): array
    {
        $before = self::saved();
        $changed = [];

        foreach (array_keys(self::FIELDS) as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = trim((string) $values[$field]);
            $value = $value === '' ? null : $value;
            if (($before[$field] ?? null) === $value) {
                continue;
            }
            $changed[] = $field;
            if ($value === null) {
                DB::table(self::TABLE)->where('key', $field)->delete();
            } else {
                DB::table(self::TABLE)->updateOrInsert(['key' => $field], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        if ($changed !== []) {
            self::forget();
            // The rest of this request (and the page it renders) sees the change.
            $config = app('config');
            foreach ($changed as $field) {
                $value = self::saved()[$field] ?? (self::$env ?? self::read($config))[$field];
                foreach (self::FIELDS[$field]['config'] as $key) {
                    $config->set($key, $value);
                }
            }
        }

        return $changed;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** For tests: forget the remembered env values. */
    public static function reset(): void
    {
        self::$env = null;
        self::forget();
    }

    /** @return array<string, string|null> */
    private static function read(Repository $config): array
    {
        $values = [];
        foreach (self::FIELDS as $field => $spec) {
            $value = $config->get($spec['config'][0]);
            $values[$field] = is_string($value) && $value !== '' ? $value : null;
        }

        return $values;
    }
}
