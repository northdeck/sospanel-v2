<?php

namespace App\Northdeck\Sso;

/**
 * What an identity provider told us about a person, after every check passed.
 */
final readonly class Claims
{
    /**
     * @param  list<string>  $groups
     * @param  array<string, mixed>  $raw  for the test page only
     * @param  array<string, string>  $session  what single logout needs later
     */
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $name,
        public array $groups,
        public array $raw,
        public array $session = [],
    ) {}

    /** @return list<string> */
    public static function groupsFrom(mixed $value): array
    {
        $groups = is_array($value) ? $value : (is_string($value) && $value !== '' ? preg_split('/\s*,\s*/', $value) : []);

        return array_values(array_unique(array_filter(array_map(
            fn ($group) => is_scalar($group) ? trim((string) $group) : '',
            $groups ?: [],
        ))));
    }
}
