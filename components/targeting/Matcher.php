<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

class Matcher {
    /**
     * @param object $config Config row with placement and status.
     * @param array<int, object> $target_rows Target rule rows.
     * @param array{post_id: int, post_type: string, page_template: string, block_types: string[], taxonomies: array<string, string[]>} $context
     */
    public function matches(object $config, array $target_rows, array $context): bool {
        if (($config->status ?? '') !== 'active') {
            return false;
        }

        if (!$this->placement_satisfied($config, $target_rows, $context)) {
            return false;
        }

        $filters = [];
        foreach ($target_rows as $row) {
            if (!$this->is_placement_rule($config, $row)) {
                $filters[] = $row;
            }
        }

        if ($filters === []) {
            return true;
        }

        $groups = [];
        foreach ($filters as $row) {
            $group = (int) ($row->rule_group ?? 0);
            $groups[$group][] = $row;
        }

        foreach ($groups as $rules) {
            $group_ok = true;
            foreach ($rules as $row) {
                if (!$this->row_matches($row, $context)) {
                    $group_ok = false;
                    break;
                }
            }
            if ($group_ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \WP_Post|null $post Optional preloaded post.
     * @param array<int, array<string, mixed>>|null $blocks Optional pre-parsed blocks.
     * @return array{post_id: int, post_type: string, page_template: string, block_types: string[], taxonomies: array<string, string[]>}
     */
    public static function context_from_post(int $post_id, $post = null, ?array $blocks = null): array {
        $post = $post instanceof \WP_Post ? $post : get_post($post_id);
        if (!$post) {
            return [
                'post_id' => $post_id,
                'post_type' => '',
                'page_template' => '',
                'block_types' => [],
                'taxonomies' => [],
            ];
        }

        $taxonomies = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'slugs']);
            if (!is_wp_error($terms) && $terms) {
                $taxonomies[$taxonomy] = array_values($terms);
            }
        }

        if ($blocks === null) {
            $block_types = self::extract_block_types_from_content((string) $post->post_content);
        } else {
            $block_types = self::collect_block_types($blocks);
        }

        return [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'page_template' => get_page_template_slug($post_id) ?: '',
            'block_types' => $block_types,
            'taxonomies' => $taxonomies,
        ];
    }

    /**
     * Lightweight block-name extraction for targeting (avoids parse_blocks memory cost).
     *
     * @return string[]
     */
    public static function extract_block_types_from_content(string $content): array {
        if ($content === '' || !str_contains($content, '<!-- wp:')) {
            return [];
        }

        if (!preg_match_all('/<!--\s+wp:([a-z0-9-_\/]+)/i', $content, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $matches[1] ?? [])));
    }

    /**
     * Placement dimensions (post type / page template / block) are OR'd.
     * Global matches everything.
     *
     * @param array<int, object> $target_rows
     * @param array{post_id: int, post_type: string, page_template: string, block_types: string[], taxonomies: array<string, string[]>} $context
     */
    private function placement_satisfied(object $config, array $target_rows, array $context): bool {
        if ((string) ($config->placement ?? '') === 'global') {
            return true;
        }

        if ((string) ($config->placement ?? '') === 'sitewide') {
            return true;
        }

        $matched_dimension = false;
        $has_placement_rule = false;

        foreach (['post_type', 'page_template', 'archive', 'post_id', 'specialty'] as $rule_type) {
            $rules = $this->placement_rules_of_type($config, $target_rows, $rule_type);
            if ($rules === []) {
                continue;
            }

            $has_placement_rule = true;
            foreach ($rules as $row) {
                if ($this->row_matches($row, $context, positive_only: true)) {
                    $matched_dimension = true;
                    break;
                }
            }
        }

        return $has_placement_rule && $matched_dimension;
    }

    /**
     * @param array<int, object> $target_rows
     * @return array<int, object>
     */
    private function placement_rules_of_type(object $config, array $target_rows, string $rule_type): array {
        $rules = [];
        foreach ($target_rows as $row) {
            if ((string) ($row->rule_type ?? '') !== $rule_type) {
                continue;
            }
            if (!$this->is_placement_rule($config, $row)) {
                continue;
            }
            $rules[] = $row;
        }

        return $rules;
    }

    private function is_placement_rule(object $config, object $row): bool {
        $type = (string) ($row->rule_type ?? '');

        return $type === 'post_type'
            || $type === 'page_template'
            || $type === 'archive'
            || $type === 'post_id'
            || $type === 'specialty';
    }

    /**
     * @param array{post_id: int, post_type: string, page_template: string, block_types: string[], taxonomies: array<string, string[]>} $context
     */
    private function row_matches(object $row, array $context, bool $positive_only = false): bool {
        $operator = (string) ($row->operator ?? 'is');
        if ($positive_only && $operator === 'is_not') {
            return false;
        }

        $type = (string) ($row->rule_type ?? '');
        if ($type === 'post_meta') {
            return Post_Meta_Rule::matches_post(
                (int) ($context['post_id'] ?? 0),
                (string) ($row->rule_key ?? ''),
                $operator,
                (string) ($row->rule_value ?? '')
            );
        }

        if ($type === 'language') {
            return Language_Rule::matches_post(
                (int) ($context['post_id'] ?? 0),
                (string) ($row->rule_value ?? ''),
                $operator
            );
        }

        if ($type === 'taxonomy') {
            return Taxonomy_Rule::matches_post(
                (int) ($context['post_id'] ?? 0),
                (string) ($row->rule_key ?? ''),
                (int) ($row->rule_value ?? 0),
                $operator
            );
        }

        $matched = match ($type) {
            'post_type' => ($context['post_type'] ?? '') === (string) ($row->rule_value ?? ''),
            'page_template' => ($context['page_template'] ?? '') === (string) ($row->rule_value ?? ''),
            'archive' => ($context['archive_key'] ?? '') === (string) ($row->rule_value ?? ''),
            'post_id' => (string) ((int) ($context['post_id'] ?? 0)) === (string) ((int) ($row->rule_value ?? 0))
                && (int) ($row->rule_value ?? 0) > 0,
            'specialty' => $this->specialty_matches((string) ($row->rule_value ?? ''), $context),
            'has_block' => in_array((string) ($row->rule_value ?? ''), $context['block_types'] ?? [], true),
            default => false,
        };

        if ($operator === 'is_not') {
            return !$matched;
        }

        return $matched;
    }

    /**
     * @param array{post_id?: int, specialty_key?: string|null} $context
     */
    private function specialty_matches(string $rule_value, array $context): bool {
        $key = sanitize_key($rule_value);
        if ($key === '' || !Specialty_Context::is_known($key)) {
            return false;
        }

        if (Specialty_Context::is_virtual($key)) {
            return ($context['specialty_key'] ?? '') === $key;
        }

        $resolved = Specialty_Context::resolve_post_id($key);

        return $resolved > 0 && (int) ($context['post_id'] ?? 0) === $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return string[]
     */
    private static function collect_block_types(array $blocks): array {
        $types = [];

        foreach ($blocks as $block) {
            if (!empty($block['blockName'])) {
                $types[] = (string) $block['blockName'];
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $types = array_merge($types, self::collect_block_types($block['innerBlocks']));
            }
        }

        return array_values(array_unique($types));
    }
}
