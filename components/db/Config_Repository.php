<?php

namespace NobleSchema\Db;

class Config_Repository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ns_configs';
    }

    public function find(int $id): ?object {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)
        );

        return $row ?: null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> config_id => placement
     */
    public function placements_for_ids(array $ids): array {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT id, placement FROM {$this->table} WHERE id IN ({$placeholders})",
            ...$ids
        );
        $rows = $wpdb->get_results($sql);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = (string) ($row->placement ?? '');
        }

        return $out;
    }

    /**
     * @return list<object>
     */
    public function find_by_template_slug(string $slug): array {
        global $wpdb;

        $slug = (string) $slug;
        if ($slug === '') {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE template_slug = %s ORDER BY name ASC",
                $slug
            )
        );

        return $rows ?: [];
    }

    public function find_by_name(string $name): ?object {
        global $wpdb;

        $name = (string) $name;
        if ($name === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE name = %s ORDER BY id ASC LIMIT 1",
                $name
            )
        );

        return $row ?: null;
    }

    public function all(array $args = []): array {
        global $wpdb;

        $where  = '1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where   .= ' AND status = %s';
            $params[] = $args['status'];
        }

        $allowed_orderby = [
            'priority'   => 'priority',
            'updated_at' => 'updated_at',
            'name'       => 'name',
            'created_at' => 'created_at',
        ];
        $orderby = $allowed_orderby[$args['orderby'] ?? 'priority'] ?? 'priority';
        $order   = strtoupper($args['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        if ($orderby === 'priority') {
            $order_clause = "priority {$order}, updated_at DESC";
        } else {
            $order_clause = "{$orderby} {$order}";
        }

        $sql = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$order_clause}";

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    public function insert(array $data): int {
        global $wpdb;

        $now  = current_time('mysql');
        $row  = $this->prepare_row($data);
        $row += [
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $wpdb->insert($this->table, $row, $this->formats($row));

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool {
        global $wpdb;

        $row = $this->prepare_row($data);
        if (!$row) {
            return false;
        }

        $row['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $this->table,
            $row,
            ['id' => $id],
            $this->formats($row),
            ['%d']
        );

        return $result !== false;
    }

    public function delete(int $id): bool {
        global $wpdb;

        $targets = new Target_Repository();
        $fields  = new Field_Repository();

        $targets->delete_for_config($id);
        $fields->delete_for_config($id);

        $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

        return $result !== false;
    }

    public function duplicate(int $id): int {
        $config = $this->find($id);
        if (!$config) {
            return 0;
        }

        $new_id = $this->insert([
            'name'               => $config->name . ' (Copy)',
            'template_slug'      => $config->template_slug,
            'schema_type'        => $config->schema_type,
            'placement'          => $config->placement,
            'filter_logic'       => (string) ($config->filter_logic ?? 'and'),
            'status'             => 'disabled',
            'priority'           => (int) $config->priority,
            'strict_validation'  => !isset($config->strict_validation) || (int) $config->strict_validation === 1 ? 1 : 0,
        ]);

        if (!$new_id) {
            return 0;
        }

        $targets = new Target_Repository();
        $fields  = new Field_Repository();

        $target_rows = array_map(static function ($row) {
            return [
                'rule_type'  => $row->rule_type,
                'rule_key'   => $row->rule_key,
                'rule_value' => $row->rule_value,
                'operator'   => $row->operator,
                'rule_group' => (int) ($row->rule_group ?? 0),
            ];
        }, $targets->for_config($id));

        $field_rows = array_map(static function ($row) {
            return [
                'field_path'        => $row->field_path,
                'source_type'       => $row->source_type,
                'source_config'     => $row->source_config,
                'required_override' => $row->required_override,
            ];
        }, $fields->for_config($id));

        $targets->replace($new_id, $target_rows);
        $fields->replace($new_id, $field_rows);

        return $new_id;
    }

    private function prepare_row(array $data): array {
        $row = [];

        if (array_key_exists('name', $data)) {
            $row['name'] = (string) $data['name'];
        }
        if (array_key_exists('template_slug', $data)) {
            $row['template_slug'] = (string) $data['template_slug'];
        }
        if (array_key_exists('schema_type', $data)) {
            $row['schema_type'] = (string) $data['schema_type'];
        }
        if (array_key_exists('placement', $data)) {
            $row['placement'] = (string) $data['placement'];
        }
        if (array_key_exists('filter_logic', $data)) {
            $logic = strtolower((string) $data['filter_logic']);
            $row['filter_logic'] = $logic === 'or' ? 'or' : 'and';
        }
        if (array_key_exists('status', $data)) {
            $row['status'] = (string) $data['status'];
        }
        if (array_key_exists('priority', $data)) {
            $row['priority'] = (int) $data['priority'];
        }
        if (array_key_exists('strict_validation', $data)) {
            $row['strict_validation'] = !empty($data['strict_validation']) ? 1 : 0;
        }

        return $row;
    }

    private function formats(array $row): array {
        $formats = [];
        foreach (array_keys($row) as $key) {
            $formats[] = in_array($key, ['priority', 'strict_validation'], true) ? '%d' : '%s';
        }

        return $formats;
    }
}
