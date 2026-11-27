<?php

namespace App\Northdeck\Scim;

/** Reads the attribute a SCIM PATCH path or key points at. */
final class PatchPath
{
    /** User attributes the panel keeps. */
    public const array STORED_USER = ['active', 'userName', 'externalId', 'displayName', 'name', 'emails'];

    /**
     * Core and enterprise User attributes the panel doesn't keep. Providers
     * send them as a matter of course; SCIM lets a service provider not
     * store attributes it doesn't support, so they're accepted and dropped.
     */
    public const array IGNORED_USER = [
        'title', 'nickName', 'profileUrl', 'userType', 'preferredLanguage', 'locale', 'timezone',
        'phoneNumbers', 'addresses', 'ims', 'photos', 'roles', 'entitlements', 'x509Certificates',
        'enterprise',
    ];

    private const string CORE = 'urn:ietf:params:scim:schemas:core:2.0:User';

    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** The path without the core schema's URN prefix. */
    public static function strip(string $path): string
    {
        return str_starts_with($path, self::CORE.':') ? substr($path, strlen(self::CORE) + 1) : $path;
    }

    /** The top-level attribute, as the panel names it, or null for an unknown one. */
    public static function userAttribute(string $path): ?string
    {
        if (str_starts_with($path, self::ENTERPRISE)) {
            return 'enterprise';
        }

        $base = strtok(self::strip($path), '.[');
        if ($base === false) {
            return null;
        }
        foreach ([...self::STORED_USER, ...self::IGNORED_USER] as $attribute) {
            if (strcasecmp($base, $attribute) === 0) {
                return $attribute;
            }
        }

        return null;
    }
}
