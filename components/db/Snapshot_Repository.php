<?php

namespace NobleSchema\Db;

class Snapshot_Repository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ns_snapshots';
    }

    public function get(int $post_id): ?object {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE post_id = %d", $post_id)
        );

        return $row ?: null;
    }

    /**
     * @param list<int> $post_ids
     * @return array<int, object> keyed by post_id
     */
    public function get_many(array $post_ids): array {
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if ($post_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE post_id IN ({$placeholders})",
            ...$post_ids
        );

        $rows = $wpdb->get_results($sql);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->post_id] = $row;
        }

        return $out;
    }

    public function upsert(int $post_id, string $graph_json, array $config_ids, bool $is_complete): void {
        global $wpdb;

        $content_hash = hash('sha256', $graph_json);
        $config_ids_json = wp_json_encode(array_values(array_map('intval', $config_ids)));
        $generated_at = current_time('mysql');

        $existing = $this->get($post_id);

        if ($existing) {
            $wpdb->update(
                $this->table,
                [
                    'graph_json'   => $graph_json,
                    'content_hash' => $content_hash,
                    'is_complete'  => $is_complete ? 1 : 0,
                    'generated_at' => $generated_at,
                    'config_ids'   => $config_ids_json,
                ],
                ['post_id' => $post_id],
                ['%s', '%s', '%d', '%s', '%s'],
                ['%d']
            );

            return;
        }

        $wpdb->insert(
            $this->table,
            [
                'post_id'      => $post_id,
                'graph_json'   => $graph_json,
                'content_hash' => $content_hash,
                'is_complete'  => $is_complete ? 1 : 0,
                'generated_at' => $generated_at,
                'config_ids'   => $config_ids_json,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function delete(int $post_id): void {
        global $wpdb;

        $wpdb->delete($this->table, ['post_id' => $post_id], ['%d']);
    }
}
