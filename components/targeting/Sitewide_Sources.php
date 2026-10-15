<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

/**
 * Which field sources are safe for placement=sitewide (no page context).
 */
final class Sitewide_Sources {
    public const ALLOWED = ['site_info', 'static'];

    public const BLOCKED = [
        'core',
        'post_meta',
        'acf',
        'manual_post',
        'manual_block',
        'taxonomy',
        'block_attr',
        'block_content',
        'yoast',
        'events_calendar',
        'callback',
    ];

    public static function is_allowed(string $source_type): bool {
        return in_array(sanitize_key($source_type), self::ALLOWED, true);
    }

    public static function is_blocked(string $source_type): bool {
        $type = sanitize_key($source_type);
        if ($type === '') {
            return false;
        }
        if (self::is_allowed($type)) {
            return false;
        }

        // Unknown types are blocked to keep sitewide output page-independent.
        return true;
    }

    /**
     * @param list<array{source_type?:string}|object> $field_maps
     * @return list<string> blocked source type slugs found
     */
    public static function blocked_in_maps(array $field_maps): array {
        $blocked = [];
        foreach ($field_maps as $map) {
            $type = is_object($map)
                ? (string) ($map->source_type ?? '')
                : (string) ($map['source_type'] ?? '');
            $type = sanitize_key($type);
            if ($type !== '' && self::is_blocked($type)) {
                $blocked[$type] = $type;
            }
        }

        return array_values($blocked);
    }
}
