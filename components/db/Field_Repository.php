<?php

namespace NobleSchema\Db;

class Field_Repository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ns_config_fields';
    }

    public function for_config(int $config_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE config_id = %d ORDER BY id ASC",
                $config_id
            )
        ) ?: [];
    }

    public function replace(int $config_id, array $rows): void {
        $this->delete_for_config($config_id);

        foreach ($rows as $row) {
            $this->insert_row($config_id, $row);
        }
    }

    public function delete_for_config(int $config_id): void {
        global $wpdb;

        $wpdb->delete($this->table, ['config_id' => $config_id], ['%d']);
    }

    private function insert_row(int $config_id, array $row): void {
        global $wpdb;

        $source_config = $row['source_config'] ?? '';
        if (is_array($source_config)) {
            $source_config = wp_json_encode($source_config);
        }

        $required_override = array_key_exists('required_override', $row)
            ? ($row['required_override'] === null ? null : (int) (bool) $row['required_override'])
            : null;

        $wpdb->insert(
            $this->table,
            [
                'config_id'         => $config_id,
                'field_path'        => (string) ($row['field_path'] ?? ''),
                'source_type'       => (string) ($row['source_type'] ?? ''),
                'source_config'     => (string) $source_config,
                'required_override' => $required_override,
            ],
            ['%d', '%s', '%s', '%s', '%d']
        );
    }
}
