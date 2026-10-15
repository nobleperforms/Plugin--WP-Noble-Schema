<?php

declare(strict_types=1);

namespace NobleSchema\Debug;

/**
 * Same-site eligibility and normalization for Page Debugger URLs.
 */
final class Debug_Url {
    /**
     * @return array{ok:bool,code:string,message:string,normalized:?string}
     */
    public static function eligibility(string $input): array {
        $raw = trim($input);
        if ($raw === '') {
            return self::fail('empty', __('Enter a URL or path to debug.', 'noble-schema'));
        }

        $absolute = self::to_absolute($raw);
        if ($absolute === null) {
            return self::fail('invalid', __('That does not look like a valid URL.', 'noble-schema'));
        }

        $parts = wp_parse_url($absolute);
        if (!is_array($parts) || empty($parts['host'])) {
            return self::fail('invalid', __('That does not look like a valid URL.', 'noble-schema'));
        }

        if (!self::host_is_same_site((string) $parts['host'])) {
            return self::fail(
                'off_site',
                __('Only URLs on this site can be debugged.', 'noble-schema')
            );
        }

        return [
            'ok'         => true,
            'code'       => 'ok',
            'message'    => '',
            'normalized' => self::canonicalize($absolute),
        ];
    }

    public static function host_is_same_site(string $host): bool {
        $host = self::normalize_host($host);
        if ($host === '') {
            return false;
        }

        foreach (self::allowed_hosts() as $allowed) {
            if ($host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function allowed_hosts(): array {
        $hosts = [];
        foreach ([home_url('/'), site_url('/')] as $base) {
            $parts = wp_parse_url($base);
            if (!is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $normalized = self::normalize_host((string) $parts['host']);
            if ($normalized !== '') {
                $hosts[$normalized] = $normalized;
            }
        }

        return array_values($hosts);
    }

    private static function normalize_host(string $host): string {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private static function to_absolute(string $raw): ?string {
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        // Protocol-relative.
        if (str_starts_with($raw, '//')) {
            $scheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME) ?: 'https';

            return $scheme . ':' . $raw;
        }

        // Relative path or query.
        if ($raw[0] === '/' || $raw[0] === '?') {
            $home = rtrim(home_url('/'), '/');
            if ($raw[0] === '?') {
                return $home . '/' . $raw;
            }

            return $home . $raw;
        }

        // Reject bare words without scheme/path markers.
        if (!str_contains($raw, '/') && !str_contains($raw, '.')) {
            return null;
        }

        // Host without scheme — only accept if it parses as our host after adding scheme.
        $candidate = 'https://' . ltrim($raw, '/');
        $parts = wp_parse_url($candidate);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return $candidate;
    }

    private static function canonicalize(string $absolute): string {
        $parts = wp_parse_url($absolute);
        if (!is_array($parts) || empty($parts['host'])) {
            return $absolute;
        }

        $home_parts = wp_parse_url(home_url('/'));
        $scheme = is_array($home_parts) && !empty($home_parts['scheme'])
            ? (string) $home_parts['scheme']
            : 'https';
        $host = (string) $parts['host'];
        // Prefer configured home host casing/www style.
        if (is_array($home_parts) && !empty($home_parts['host'])
            && self::normalize_host($host) === self::normalize_host((string) $home_parts['host'])
        ) {
            $host = (string) $home_parts['host'];
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?' . $parts['query']
            : '';

        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * @return array{ok:bool,code:string,message:string,normalized:?string}
     */
    private static function fail(string $code, string $message): array {
        return [
            'ok'         => false,
            'code'       => $code,
            'message'    => $message,
            'normalized' => null,
        ];
    }
}
