<?php
namespace NobleSchema\Tools;

function get_version(): string {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data(dirname(__DIR__, 2) . '/index.php', false, false);
    return $data['Version'] ?? '0.1.0';
}

/**
 * Cache-busting version for an enqueued asset.
 *
 * Appends the built file's modification time so rebuilt CSS/JS invalidates the
 * browser cache even when the plugin version header hasn't changed.
 */
function asset_version(string $file): string {
    $version = get_version();
    if (is_readable($file)) {
        $mtime = filemtime($file);
        if ($mtime !== false) {
            return $version . '.' . $mtime;
        }
    }

    return $version;
}
