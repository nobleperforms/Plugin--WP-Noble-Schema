<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Off by default — only wipe when the Schema Settings toggle is enabled.
if ((string) get_option('ns_delete_data_on_uninstall', '') !== '1') {
    return;
}

global $wpdb;

$prefix = $wpdb->prefix;
$tables = [
    'ns_configs',
    'ns_config_targets',
    'ns_config_fields',
    'ns_manual_values',
    'ns_snapshots',
    'ns_logs',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
}

delete_option('ns_db_version');
delete_option('ns_regen_queue');
delete_option('ns_regen_total');
delete_option('ns_regen_job');
delete_option('ns_regen_cancelled');
delete_option('ns_block_usage_counts');
delete_option('ns_archive_schema_cache');
delete_option('ns_enable_updates');
delete_option('ns_gh_username');
delete_option('ns_gh_repo');
delete_option('ns_gh_token');
delete_option('ns_gh_update_last_check');
delete_option('ns_plugin_version');
delete_option('ns_onboarding_state');
delete_option('ns_settings_touched');
delete_option('ns_delete_data_on_uninstall');
delete_option('ns_restrict_schema_admin');
delete_option('ns_schema_admin_user_ids');
delete_option('ns_logging_enabled');
delete_option('ns_logging_retention');

wp_clear_scheduled_hook('ns_schema_regen_batch');
wp_clear_scheduled_hook('ns_schema_logs_cleanup');

// Remove durable custom templates from uploads.
$uploads = wp_upload_dir();
if (empty($uploads['error']) && !empty($uploads['basedir'])) {
    $custom_dir = trailingslashit((string) $uploads['basedir']) . 'noble-schema/templates';
    if (is_dir($custom_dir)) {
        foreach (glob($custom_dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($custom_dir);
        $parent = dirname($custom_dir);
        if (is_dir($parent) && basename($parent) === 'noble-schema') {
            $left = glob($parent . '/*') ?: [];
            if ($left === []) {
                @rmdir($parent);
            }
        }
    }
}
