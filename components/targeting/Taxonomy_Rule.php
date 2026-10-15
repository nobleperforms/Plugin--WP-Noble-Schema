<?php

declare(strict_types=1);

namespace NobleSchema\Targeting;

use NobleSchema\Integrations\Plugin_Integrations;

/**
 * Taxonomy additional-filter helpers keyed by term ID (+ WPML translation expansion).
 */
final class Taxonomy_Rule {
    public const OPERATORS = ['is', 'is_not'];

    /**
     * Taxonomies an editor can target or map, including private ones shown in wp-admin.
     *
     * Public taxonomies stay included. Private taxonomies are included when they have
     * an admin UI, so term filters can use them even when they have no public archive.
     *
     * @return array<string, object>
     */
    public static function selectable_taxonomies(): array {
        $taxonomies = get_taxonomies([], 'objects');
        if (!is_array($taxonomies)) {
            return [];
        }

        $selectable = [];
        foreach ($taxonomies as $slug => $taxonomy) {
            if (!is_object($taxonomy)) {
                continue;
            }
            if (empty($taxonomy->public) && empty($taxonomy->show_ui)) {
                continue;
            }
            $selectable[(string) $slug] = $taxonomy;
        }

        uasort($selectable, static function (object $a, object $b): int {
            $left = (string) ($a->labels->singular_name ?? '');
            $right = (string) ($b->labels->singular_name ?? '');

            return strcasecmp($left, $right);
        });

        return $selectable;
    }

    public static function sanitize_operator(string $operator): string {
        $operator = sanitize_key($operator);

        return in_array($operator, self::OPERATORS, true) ? $operator : 'is';
    }

    public static function sanitize_taxonomy(string $taxonomy): string {
        return sanitize_key($taxonomy);
    }

    public static function sanitize_term_id(mixed $term_id): int {
        return max(0, (int) $term_id);
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{type:string,taxonomy:string,term_id:int,operator:string}|null
     */
    public static function normalize_rule(array $rule): ?array {
        $taxonomy = self::sanitize_taxonomy((string) ($rule['taxonomy'] ?? ''));
        $term_id  = self::sanitize_term_id($rule['term_id'] ?? $rule['term'] ?? $rule['value'] ?? 0);
        if ($taxonomy === '' || $term_id <= 0) {
            return null;
        }

        return [
            'type'     => 'taxonomy',
            'taxonomy' => $taxonomy,
            'term_id'  => $term_id,
            'operator' => self::sanitize_operator((string) ($rule['operator'] ?? 'is')),
        ];
    }

    /**
     * Selected term ID plus WPML translation siblings (when available).
     *
     * @return list<int>
     */
    public static function expanded_term_ids(int $term_id, string $taxonomy): array {
        $term_id  = self::sanitize_term_id($term_id);
        $taxonomy = self::sanitize_taxonomy($taxonomy);
        if ($term_id <= 0 || $taxonomy === '') {
            return [];
        }

        $ids = Plugin_Integrations::term_translation_ids($term_id, $taxonomy);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);

        return $ids !== [] ? $ids : [$term_id];
    }

    public static function matches_post(int $post_id, string $taxonomy, int $term_id, string $operator): bool {
        $taxonomy = self::sanitize_taxonomy($taxonomy);
        $term_id  = self::sanitize_term_id($term_id);
        $operator = self::sanitize_operator($operator);

        if ($taxonomy === '' || $term_id <= 0) {
            return $operator === 'is_not';
        }

        if ($post_id <= 0) {
            return $operator === 'is_not';
        }

        $wanted = self::expanded_term_ids($term_id, $taxonomy);
        if ($wanted === []) {
            return $operator === 'is_not';
        }

        $assigned = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($assigned) || !is_array($assigned)) {
            $assigned = [];
        }
        $assigned = array_map('intval', $assigned);

        $matched = array_intersect($wanted, $assigned) !== [];

        return $operator === 'is_not' ? !$matched : $matched;
    }

    /**
     * Human label for UI / chips; falls back to "#ID" when the term is missing.
     */
    public static function term_label(int $term_id, string $taxonomy = ''): string {
        $term_id = self::sanitize_term_id($term_id);
        if ($term_id <= 0) {
            return '';
        }

        $term = get_term($term_id, $taxonomy !== '' ? $taxonomy : '');
        if (!$term || is_wp_error($term)) {
            return sprintf(
                /* translators: %d: term ID */
                __('Unknown term #%d', 'noble-schema'),
                $term_id
            );
        }

        $name = html_entity_decode((string) ($term->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $name !== '' ? $name : sprintf('#%d', $term_id);
    }

    /**
     * Resolve a portable export payload to a local term ID.
     *
     * @param array<string, mixed> $row Target row, possibly with term_slug / term_name.
     */
    public static function resolve_imported_term_id(array $row): int {
        $taxonomy = self::sanitize_taxonomy((string) ($row['rule_key'] ?? $row['taxonomy'] ?? ''));
        if ($taxonomy === '') {
            return 0;
        }

        $slug = sanitize_title((string) ($row['term_slug'] ?? ''));
        if ($slug !== '') {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return (int) $term->term_id;
            }
        }

        $name = trim(sanitize_text_field((string) ($row['term_name'] ?? '')));
        if ($name !== '') {
            $term = get_term_by('name', $name, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return (int) $term->term_id;
            }
        }

        $raw_id = self::sanitize_term_id($row['rule_value'] ?? 0);
        if ($raw_id > 0) {
            $term = get_term($raw_id, $taxonomy);
            if ($term && !is_wp_error($term) && (string) ($term->taxonomy ?? '') === $taxonomy) {
                return $raw_id;
            }
        }

        return 0;
    }
}
