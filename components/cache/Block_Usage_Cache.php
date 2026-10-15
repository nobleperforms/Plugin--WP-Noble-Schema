<?php

declare(strict_types=1);

namespace NobleSchema\Cache;

/**
 * Caches published-post counts for block-name targeting (content LIKE scans).
 */
class Block_Usage_Cache {
    private const OPTION = 'ns_block_usage_counts';
    private const TTL = 43200; // 12 hours

    /**
     * @param string[] $blocks
     */
    public function count_published_with_blocks(array $blocks): int {
        $blocks = $this->normalize_blocks($blocks);
        if ($blocks === []) {
            return 0;
        }

        $key = 'blocks:' . implode('|', $blocks);
        return $this->remember($key, function () use ($blocks): int {
            return $this->query_published_with_blocks($blocks);
        });
    }

    /**
     * @param callable():int $producer
     */
    public function remember(string $key, callable $producer): int {
        $storage_key = md5($key);
        $all = $this->all();
        $entry = $all[$storage_key] ?? null;
        if (is_array($entry)) {
            $at = (int) ($entry['at'] ?? 0);
            if ($at > 0 && (time() - $at) < self::TTL && isset($entry['count'])) {
                return max(0, (int) $entry['count']);
            }
        }

        $count = max(0, (int) $producer());
        $all[$storage_key] = [
            'count' => $count,
            'at'    => time(),
        ];
        update_option(self::OPTION, $all, false);

        return $count;
    }

    public function invalidate_all(): void {
        delete_option(self::OPTION);
    }

    /**
     * Build OR'd LIKE clauses that match serialized block comments for the given names.
     *
     * @param string[] $blocks
     * @return array{sql:string,params:array<int,string>}|null
     */
    public static function content_like_clause(string $column, array $blocks): ?array {
        global $wpdb;

        $blocks = array_values(array_unique(array_filter(array_map('strval', $blocks))));
        if ($blocks === [] || $column === '') {
            return null;
        }

        $parts = [];
        $params = [];
        foreach ($blocks as $block) {
            $escaped = $wpdb->esc_like($block);
            // Token boundary after block name (space, attrs object, self-close, or end).
            foreach ([' %', '{%', '/%', '-->%'] as $suffix) {
                $parts[] = "{$column} LIKE %s";
                $params[] = '%<!-- wp:' . $escaped . $suffix;
            }
        }

        return [
            'sql'    => '(' . implode(' OR ', $parts) . ')',
            'params' => $params,
        ];
    }

    /**
     * @param string[] $blocks
     */
    private function query_published_with_blocks(array $blocks): int {
        global $wpdb;

        $like = self::content_like_clause('post_content', $blocks);
        if ($like === null) {
            return 0;
        }

        $public_types = array_values(get_post_types(['public' => true]));
        if ($public_types === []) {
            return 0;
        }

        $type_placeholders = implode(',', array_fill(0, count($public_types), '%s'));
        $params = array_merge($public_types, $like['params']);
        $sql = $wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ($type_placeholders)
               AND {$like['sql']}",
            ...$params
        );

        return max(0, (int) $wpdb->get_var($sql));
    }

    /**
     * @param string[] $blocks
     * @return string[]
     */
    private function normalize_blocks(array $blocks): array {
        $blocks = array_values(array_unique(array_filter(array_map(
            static fn ($block) => strtolower(trim((string) $block)),
            $blocks
        ))));
        sort($blocks);
        return $blocks;
    }

    /**
     * @return array<string, array{count?:int,at?:int}>
     */
    private function all(): array {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }
}
