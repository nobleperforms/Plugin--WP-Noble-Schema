<?php

declare(strict_types=1);

namespace NobleSchema\Cache;

class Specialty_Cache {
    private const OPTION = 'ns_specialty_schema_cache';

    /**
     * @return array{graph_json: string, config_ids: int[], generated_at: string}|null
     */
    public function get(string $specialty_key): ?array {
        $all = $this->all();
        $entry = $all[$specialty_key] ?? null;
        if (!is_array($entry) || empty($entry['graph_json'])) {
            return null;
        }

        return [
            'graph_json'   => (string) $entry['graph_json'],
            'config_ids'   => array_values(array_map('intval', (array) ($entry['config_ids'] ?? []))),
            'generated_at' => (string) ($entry['generated_at'] ?? ''),
        ];
    }

    /**
     * @param array<int, int> $config_ids
     */
    public function set(string $specialty_key, string $graph_json, array $config_ids): void {
        $all = $this->all();
        $all[$specialty_key] = [
            'graph_json'   => $graph_json,
            'config_ids'   => array_values(array_map('intval', $config_ids)),
            'generated_at' => current_time('mysql'),
        ];
        update_option(self::OPTION, $all, false);
    }

    public function delete(string $specialty_key): void {
        $all = $this->all();
        if (!isset($all[$specialty_key])) {
            return;
        }
        unset($all[$specialty_key]);
        update_option(self::OPTION, $all, false);
    }

    public function count_for_config(int $config_id): int {
        if ($config_id <= 0) {
            return 0;
        }

        $count = 0;
        foreach ($this->all() as $entry) {
            $ids = array_map('intval', (array) ($entry['config_ids'] ?? []));
            if (in_array($config_id, $ids, true)) {
                $count++;
            }
        }

        return $count;
    }

    public function clear_for_config(int $config_id): int {
        if ($config_id <= 0) {
            return 0;
        }

        $all = $this->all();
        $removed = 0;
        foreach ($all as $key => $entry) {
            $ids = array_map('intval', (array) ($entry['config_ids'] ?? []));
            if (!in_array($config_id, $ids, true)) {
                continue;
            }
            unset($all[$key]);
            $removed++;
        }

        if ($removed > 0) {
            update_option(self::OPTION, $all, false);
        }

        return $removed;
    }

    public function clear_all(): void {
        delete_option(self::OPTION);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? $all : [];
    }
}
