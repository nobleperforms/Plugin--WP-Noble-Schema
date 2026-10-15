<?php

namespace NobleSchema\Db;

class Target_Repository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ns_config_targets';
    }

    public function for_config(int $config_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE config_id = %d ORDER BY rule_group ASC, id ASC",
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

        $wpdb->insert(
            $this->table,
            [
                'config_id'  => $config_id,
                'rule_type'  => (string) ($row['rule_type'] ?? ''),
                'rule_key'   => array_key_exists('rule_key', $row) && $row['rule_key'] !== null
                    ? (string) $row['rule_key']
                    : null,
                'rule_value' => (string) ($row['rule_value'] ?? ''),
                'operator'   => (string) ($row['operator'] ?? 'is'),
                'rule_group' => (int) ($row['rule_group'] ?? 0),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d']
        );
    }
}
