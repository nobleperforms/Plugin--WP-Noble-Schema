<?php

namespace NobleSchema\Db;

class Installer {
    private const OPTION_KEY = 'ns_db_version';

    public static function db_version(): string {
        return '1.8';
    }

    public static function activate(): void {
        self::install();
        self::cleanup_legacy_regen_options();
        self::migrate_draft_status();
        self::migrate_filter_groups();
        self::migrate_drop_schema_subtype();
        self::clear_access_restriction_lockout();
        \NobleSchema\Admin\Onboarding::seed_on_activate();
        update_option(self::OPTION_KEY, self::db_version());
    }

    public static function maybe_upgrade(): void {
        $installed = get_option(self::OPTION_KEY, '0');
        if (version_compare((string) $installed, self::db_version(), '<')) {
            self::install();
            self::cleanup_legacy_regen_options();
            self::migrate_draft_status();
            self::migrate_filter_groups();
            self::migrate_drop_schema_subtype();
            self::clear_access_restriction_lockout();
            update_option(self::OPTION_KEY, self::db_version());
        }
    }

    /**
     * One-time unlock: turn off schema admin restriction after a bad import lockout.
     */
    public static function clear_access_restriction_lockout(): void {
        delete_option('ns_restrict_schema_admin');
    }

    /**
     * Drop legacy all-ID regen queues without loading them (can OOM).
     */
    public static function cleanup_legacy_regen_options(): void {
        delete_option('ns_regen_queue');
        delete_option('ns_regen_total');
    }

    /**
     * Replace draft status with disabled (enable/disable replaces draft/publish).
     */
    public static function migrate_draft_status(): void {
        global $wpdb;

        $wpdb->query(
            "UPDATE {$wpdb->prefix}ns_configs SET status = 'disabled' WHERE status = 'draft'"
        );
    }

    /**
     * Split legacy filter_logic=or into one rule per OR group.
     */
    public static function migrate_filter_groups(): void {
        global $wpdb;

        $configs_table = $wpdb->prefix . 'ns_configs';
        $targets_table = $wpdb->prefix . 'ns_config_targets';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $or_ids = $wpdb->get_col("SELECT id FROM {$configs_table} WHERE filter_logic = 'or'");
        if (!is_array($or_ids) || $or_ids === []) {
            return;
        }

        foreach ($or_ids as $config_id) {
            $config_id = (int) $config_id;
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, rule_type, rule_key FROM {$targets_table} WHERE config_id = %d ORDER BY id ASC",
                    $config_id
                )
            );
            if (!is_array($rows)) {
                continue;
            }

            $placement = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT placement FROM {$configs_table} WHERE id = %d", $config_id)
            );

            $group = 0;
            foreach ($rows as $row) {
                $type = (string) ($row->rule_type ?? '');
                $key  = (string) ($row->rule_key ?? '');
                $is_filter = $type === 'taxonomy'
                    || ($type === 'has_block' && $key === 'filter')
                    || (
                        $type === 'has_block'
                        && $key === ''
                        && in_array($placement, ['post_type', 'page_template', 'global'], true)
                    );
                if (!$is_filter) {
                    continue;
                }
                $wpdb->update(
                    $targets_table,
                    ['rule_group' => $group],
                    ['id' => (int) $row->id],
                    ['%d'],
                    ['%d']
                );
                $group++;
            }

            $wpdb->update(
                $configs_table,
                ['filter_logic' => 'and'],
                ['id' => $config_id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * Remove deprecated schema_subtype column (replaced by Storefront-specific templates).
     */
    public static function migrate_drop_schema_subtype(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_configs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'schema_subtype'");
        if (!$exists) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN schema_subtype");
    }

    private static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix;

        $sql = "CREATE TABLE {$prefix}ns_configs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            template_slug varchar(100) NOT NULL,
            schema_type varchar(100) NOT NULL,
            placement varchar(50) NOT NULL DEFAULT 'global',
            filter_logic varchar(10) NOT NULL DEFAULT 'and',
            status varchar(20) NOT NULL DEFAULT 'disabled',
            priority int(11) NOT NULL DEFAULT 10,
            strict_validation tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY priority (priority)
        ) $charset_collate;

        CREATE TABLE {$prefix}ns_config_targets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            config_id bigint(20) unsigned NOT NULL,
            rule_type varchar(50) NOT NULL,
            rule_key varchar(100) DEFAULT NULL,
            rule_value varchar(255) NOT NULL,
            operator varchar(20) NOT NULL DEFAULT 'is',
            rule_group int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY config_id (config_id)
        ) $charset_collate;

        CREATE TABLE {$prefix}ns_config_fields (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            config_id bigint(20) unsigned NOT NULL,
            field_path varchar(255) NOT NULL,
            source_type varchar(50) NOT NULL,
            source_config longtext NOT NULL,
            required_override tinyint(1) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY config_id (config_id)
        ) $charset_collate;

        CREATE TABLE {$prefix}ns_manual_values (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            config_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            block_client_id varchar(100) DEFAULT NULL,
            field_path varchar(255) NOT NULL,
            value longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY config_post_block_field (config_id, post_id, block_client_id, field_path),
            KEY post_id (post_id)
        ) $charset_collate;

        CREATE TABLE {$prefix}ns_snapshots (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            graph_json longtext NOT NULL,
            content_hash varchar(64) NOT NULL,
            is_complete tinyint(1) NOT NULL DEFAULT 0,
            generated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            config_ids longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id)
        ) $charset_collate;

        CREATE TABLE {$prefix}ns_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            level varchar(16) NOT NULL DEFAULT 'info',
            category varchar(64) NOT NULL DEFAULT 'system',
            post_id bigint(20) unsigned DEFAULT NULL,
            message text NOT NULL,
            context longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY level (level),
            KEY category (category),
            KEY post_id (post_id)
        ) $charset_collate;";

        dbDelta($sql);
    }
}
