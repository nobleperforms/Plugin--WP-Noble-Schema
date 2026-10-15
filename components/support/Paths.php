<?php

declare(strict_types=1);

namespace NobleSchema\Support;

/**
 * Durable filesystem locations (survive plugin updates / immutable deploys).
 */
final class Paths {
    /**
     * Bundled JSON templates shipped with the plugin.
     */
    public static function bundled_templates_dir(?string $plugin_dir = null): string {
        $root = $plugin_dir !== null && $plugin_dir !== ''
            ? rtrim($plugin_dir, '/\\')
            : dirname(__DIR__, 2);

        return $root . '/templates';
    }

    /**
     * Site-specific custom templates (uploads — writable on Pantheon and similar).
     */
    public static function custom_templates_dir(): string {
        $uploads = wp_upload_dir();
        $basedir = is_array($uploads) && !empty($uploads['basedir'])
            ? (string) $uploads['basedir']
            : '';

        if ($basedir === '') {
            return '';
        }

        return trailingslashit($basedir) . 'noble-schema/templates';
    }

    /**
     * Human-readable relative path for admin copy.
     */
    public static function custom_templates_dir_label(): string {
        return 'uploads/noble-schema/templates';
    }
}
