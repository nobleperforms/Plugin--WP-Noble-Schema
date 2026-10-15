<?php

declare(strict_types=1);

namespace NobleSchema\Cache;

/**
 * Per-config JSON-LD for placement=sitewide (one shared payload, not per post).
 */
class Sitewide_Cache {
    private const OPTION = 'ns_sitewide_schema_cache';

    /**
     * @return array{graph_json: string, generated_at: string}|null
     */
    public function get(int $config_id): ?array {
        if ($config_id <= 0) {
            return null;
        }

        $entry = $this->all()[$config_id] ?? null;
        if (!is_array($entry) || empty($entry['graph_json'])) {
            return null;
        }

        return [
            'graph_json'   => (string) $entry['graph_json'],
            'generated_at' => (string) ($entry['generated_at'] ?? ''),
        ];
    }

    public function set(int $config_id, string $graph_json): void {
        if ($config_id <= 0) {
            return;
        }

        $all = $this->all();
        $all[$config_id] = [
            'graph_json'   => $graph_json,
            'generated_at' => current_time('mysql'),
        ];
        update_option(self::OPTION, $all, false);
    }

    public function delete(int $config_id): void {
        if ($config_id <= 0) {
            return;
        }

        $all = $this->all();
        if (!isset($all[$config_id])) {
            return;
        }
        unset($all[$config_id]);
        update_option(self::OPTION, $all, false);
    }

    public function clear_all(): void {
        delete_option(self::OPTION);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(): array {
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) {
            return [];
        }

        $out = [];
        foreach ($all as $key => $entry) {
            $id = (int) $key;
            if ($id > 0 && is_array($entry)) {
                $out[$id] = $entry;
            }
        }

        return $out;
    }
}
