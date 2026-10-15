<?php

declare(strict_types=1);

namespace NobleSchemaLite;

final class Migrator {
    /**
     * @return array{posts:int,archives:int}
     */
    public function migrate(): array {
        return [
            'posts'    => $this->migrate_post_snapshots(),
            'archives' => $this->migrate_archives(),
        ];
    }

    private function migrate_post_snapshots(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_snapshots';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT post_id, graph_json FROM {$table} WHERE is_complete = 1 AND graph_json <> ''"
        );
        if (!is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $post_id = (int) ($row->post_id ?? 0);
            $json = trim((string) ($row->graph_json ?? ''));
            if ($post_id <= 0 || $json === '') {
                continue;
            }
            update_post_meta($post_id, NS_LITE_META_KEY, $json);
            $count++;
        }

        return $count;
    }

    private function migrate_archives(): int {
        $cache = get_option('ns_archive_schema_cache', []);
        if (!is_array($cache)) {
            update_option(NS_LITE_ARCHIVE_OPTION, [], false);
            return 0;
        }

        $out = [];
        foreach ($cache as $key => $entry) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $json = '';
            if (is_array($entry)) {
                $json = trim((string) ($entry['graph_json'] ?? ''));
            } elseif (is_string($entry)) {
                $json = trim($entry);
            }
            if ($json === '') {
                continue;
            }
            $out[$key] = $json;
        }

        update_option(NS_LITE_ARCHIVE_OPTION, $out, false);

        return count($out);
    }

    /**
     * Remove all Schema Lite migrated/stored JSON-LD (post meta + archive option)
     * and reset the offboarding-complete flag so the process can be run again.
     *
     * @return array{posts:int,archives:int}
     */
    public function purge_migrated_data(): array {
        global $wpdb;

        $posts = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
                NS_LITE_META_KEY
            )
        );
        if ($posts < 0) {
            $posts = 0;
        }

        $archives = 0;
        $existing = get_option(NS_LITE_ARCHIVE_OPTION, []);
        if (is_array($existing)) {
            $archives = count($existing);
        }
        delete_option(NS_LITE_ARCHIVE_OPTION);
        delete_option(NS_LITE_COMPLETE_OPTION);

        return [
            'posts'    => $posts,
            'archives' => $archives,
        ];
    }
}
