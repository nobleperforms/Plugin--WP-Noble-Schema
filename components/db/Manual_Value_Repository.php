<?php

namespace NobleSchema\Db;

class Manual_Value_Repository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ns_manual_values';
    }

    public function for_post(int $post_id, ?int $config_id = null): array {
        global $wpdb;

        if ($config_id !== null) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE post_id = %d AND config_id = %d ORDER BY id ASC",
                    $post_id,
                    $config_id
                )
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE post_id = %d ORDER BY id ASC",
                $post_id
            )
        ) ?: [];
    }

    public function upsert(array $row): void {
        global $wpdb;

        $config_id       = (int) ($row['config_id'] ?? 0);
        $post_id         = (int) ($row['post_id'] ?? 0);
        $block_client_id = isset($row['block_client_id']) ? (string) $row['block_client_id'] : null;
        $field_path      = (string) ($row['field_path'] ?? '');

        $value = $row['value'] ?? '';
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        } else {
            $value = (string) $value;
        }

        $existing_id = $this->find_id($config_id, $post_id, $block_client_id, $field_path);

        if ($existing_id) {
            $wpdb->update(
                $this->table,
                ['value' => $value],
                ['id' => $existing_id],
                ['%s'],
                ['%d']
            );

            return;
        }

        $wpdb->insert(
            $this->table,
            [
                'config_id'       => $config_id,
                'post_id'         => $post_id,
                'block_client_id' => $block_client_id,
                'field_path'      => $field_path,
                'value'           => $value,
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
    }

    public function delete_for_post(int $post_id): void {
        global $wpdb;

        $wpdb->delete($this->table, ['post_id' => $post_id], ['%d']);
    }

    /**
     * @param array<int, string> $keep_client_ids
     */
    public function delete_block_scoped_except(int $post_id, array $keep_client_ids): void {
        global $wpdb;

        $keep_client_ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): string => sanitize_text_field((string) $id),
            $keep_client_ids
        ))));

        if ($keep_client_ids === []) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table}
                    WHERE post_id = %d AND block_client_id IS NOT NULL AND block_client_id != ''",
                    $post_id
                )
            );

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($keep_client_ids), '%s'));
        $params = array_merge([$post_id], $keep_client_ids);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table}
                WHERE post_id = %d AND block_client_id IS NOT NULL AND block_client_id != ''
                AND block_client_id NOT IN ({$placeholders})",
                ...$params
            )
        );
    }

    private function find_id(int $config_id, int $post_id, ?string $block_client_id, string $field_path): int {
        global $wpdb;

        if ($block_client_id === null || $block_client_id === '') {
            $id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table}
                    WHERE config_id = %d AND post_id = %d AND field_path = %s
                    AND (block_client_id IS NULL OR block_client_id = '')",
                    $config_id,
                    $post_id,
                    $field_path
                )
            );
        } else {
            $id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table}
                    WHERE config_id = %d AND post_id = %d AND block_client_id = %s AND field_path = %s",
                    $config_id,
                    $post_id,
                    $block_client_id,
                    $field_path
                )
            );
        }

        return (int) $id;
    }
}
