<?php

namespace App\Northdeck\Admin;

use App\Northdeck\NorthdeckServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * .env settings as instance admins set them on /admin/settings (see
 * SettingsCatalog). A saved value goes over the .env one; clearing it goes
 * back. Secrets are stored encrypted and never sent to the browser.
 */
final class PanelSettings
{
    public const string TABLE = 'northdeck_panel_settings';

    private const string CACHE_KEY = 'northdeck.panel_settings';

    /** @var array<string, mixed>|null what the config files (.env) said, before saved values went over them */
    private static ?array $env = null;

    /** @return array<string, string> saved values as stored (secrets still encrypted) */
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
        $fields = SettingsCatalog::fields();

        foreach (self::saved() as $key => $stored) {
            if (! isset($fields[$key])) {
                continue;
            }
            $value = self::decode($fields[$key], $stored);
            if ($value === null) {
                continue;
            }
            foreach ($fields[$key]['config'] as $configKey) {
                $config->set($configKey, $value);
            }
        }
    }

    /**
     * Every field with what the browser may see: the saved value, or for a
     * secret only whether one is saved or set in .env.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPage(Repository $config): array
    {
        $env = self::$env ?? self::read($config);
        $saved = self::saved();

        $groups = [];
        foreach (SettingsCatalog::groups() as $group) {
            $fields = [];
            foreach ($group['fields'] as $field) {
                $key = $field['key'];
                $secret = $field['type'] === 'secret';
                $fields[] = [
                    'key' => $key,
                    'env' => $field['env'],
                    'label' => $field['label'],
                    'hint' => $field['hint'],
                    'type' => $field['type'],
                    'options' => $field['options'] ?? null,
                    'saved' => isset($saved[$key]),
                    'value' => $secret || ! isset($saved[$key]) ? null : self::display(self::decode($field, $saved[$key])),
                    'envValue' => $secret ? null : self::display($env[$key] ?? null),
                    'envSet' => filled($env[$key] ?? null),
                ];
            }
            $groups[] = [...array_diff_key($group, ['fields' => true]), 'fields' => $fields];
        }

        return $groups;
    }

    /** @return array<string, list<string>> validation rules, by field */
    public static function rules(): array
    {
        $rules = [];
        foreach (SettingsCatalog::fields() as $key => $field) {
            $rules[$key] = ['nullable', ...match ($field['type']) {
                'email' => ['email', 'max:254'],
                'url' => ['url:https,http', 'max:2048'],
                'int' => ['integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? PHP_INT_MAX)],
                'bool' => ['in:on,off'],
                'select' => ['in:'.implode(',', array_keys($field['options'] ?? []))],
                'list' => ['string', 'max:2000', 'regex:/^[a-z0-9.,@\s-]*$/i'],
                'secret' => ['string', 'max:4096'],
                default => ['string', ...($field['rules'] ?? ['max:255'])],
            }];
        }

        return $rules;
    }

    /**
     * Saves what was sent. An empty value goes back to .env; for a secret,
     * an empty value leaves it alone and `clear` lists the ones to remove.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $clear  secrets to go back to .env
     * @return list<string> the fields that changed
     */
    public static function save(array $values, array $clear = []): array
    {
        $before = self::saved();
        $fields = SettingsCatalog::fields();
        $changed = [];

        foreach ($fields as $key => $field) {
            $secret = $field['type'] === 'secret';
            if ($secret && in_array($key, $clear, true)) {
                $new = null;
            } elseif (! array_key_exists($key, $values)) {
                continue;
            } else {
                $new = self::encode($field, $values[$key]);
                if ($secret && $new === null) {
                    continue; // blank secret: unchanged
                }
            }

            $old = $before[$key] ?? null;
            $same = $secret && $old !== null && $new !== null
                ? self::decode($field, $old) === self::decode($field, $new)
                : $old === $new;
            if ($same) {
                continue;
            }

            $changed[] = $key;
            if ($new === null) {
                DB::table(self::TABLE)->where('key', $key)->delete();
            } else {
                DB::table(self::TABLE)->updateOrInsert(['key' => $key], ['value' => $new, 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        if ($changed !== []) {
            self::forget();
            self::refresh(app('config'), $changed);
            // Queue workers read config once when they start; this makes them
            // start again with the new values.
            rescue(fn () => Artisan::call('queue:restart'), report: false);
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

    /**
     * The rest of this request (and the page it renders) sees the change.
     *
     * @param  list<string>  $changed
     */
    private static function refresh(Repository $config, array $changed): void
    {
        $env = self::$env ?? self::read($config);
        self::$env = $env;
        $fields = SettingsCatalog::fields();
        $saved = self::saved();

        foreach ($changed as $key) {
            $value = isset($saved[$key]) ? self::decode($fields[$key], $saved[$key]) : ($env[$key] ?? null);
            foreach ($fields[$key]['config'] as $configKey) {
                $config->set($configKey, $value);
            }
        }

        NorthdeckServiceProvider::applyDefaults($config);
    }

    /** @param  array{type: string}  $field */
    private static function encode(array $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) (is_scalar($value) ? $value : ''));
        if ($value === '') {
            return null;
        }

        return match ($field['type']) {
            'secret' => Crypt::encryptString($value),
            'list' => implode(',', self::domains($value)) ?: null,
            'bool' => $value === 'on' ? '1' : '0',
            default => $value,
        };
    }

    /** @param  array{type: string}  $field */
    private static function decode(array $field, string $stored): mixed
    {
        return match ($field['type']) {
            'secret' => rescue(fn () => Crypt::decryptString($stored), null, false),
            'list' => self::domains($stored),
            'bool' => $stored === '1',
            'int' => (int) $stored,
            default => $stored,
        };
    }

    /** @return list<string> */
    public static function domains(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($d) => ltrim(strtolower(trim($d)), '@'),
            preg_split('/[\s,]+/', $value) ?: [],
        ))));
    }

    private static function display(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => null,
            is_bool($value) => $value ? 'on' : 'off',
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function read(Repository $config): array
    {
        $values = [];
        foreach (SettingsCatalog::fields() as $key => $field) {
            $values[$key] = $config->get($field['config'][0]);
        }

        return $values;
    }
}
