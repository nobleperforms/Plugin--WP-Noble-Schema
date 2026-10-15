<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

use NobleSchema\Cache\Archive_Cache;
use NobleSchema\Cache\Specialty_Cache;

add_action('wp_ajax_ns_clear_archive_cache', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();

    check_ajax_referer('ns_clear_archive_cache', 'nonce');

    $config_id = isset($_POST['config_id']) ? (int) $_POST['config_id'] : 0;
    $archive_cache = new Archive_Cache();
    $specialty_cache = new Specialty_Cache();

    if ($config_id <= 0) {
        $archive_cache->clear_all();
        $specialty_cache->clear_all();
        wp_send_json_success([
            'message' => __('All archive and specialty schema caches cleared.', 'noble-schema'),
            'removed' => -1,
        ]);
    }

    $removed = $archive_cache->clear_for_config($config_id) + $specialty_cache->clear_for_config($config_id);

    wp_send_json_success([
        'message' => $removed > 0
            ? sprintf(
                /* translators: %d: number of cache entries */
                _n('%d cache entry cleared.', '%d cache entries cleared.', $removed, 'noble-schema'),
                $removed
            )
            : __('No cache entries found for this config.', 'noble-schema'),
        'removed' => $removed,
    ]);
});
