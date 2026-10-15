<?php

declare(strict_types=1);

namespace NobleSchema\Coverage;

use NobleSchema\Schema\Schema_Validator;
use NobleSchema\Tools\Log;

/**
 * Classify a post/archive schema graph into coverage tiers.
 */
final class Coverage_Classifier {
    public const TIER_VALIDATED = 'validated';
    public const TIER_PAGE_COMPLETE = 'page_complete';
    public const TIER_PAGE_INCOMPLETE = 'page_incomplete';
    public const TIER_SITEWIDE_ONLY = 'sitewide_only';
    public const TIER_NONE = 'none';

    /**
     * @return list<string>
     */
    public static function sitewide_types(): array {
        return \NobleSchema\Admin\Settings_Page::sitewide_schema_types();
    }

    /**
     * @param array{
     *   graph_json?: string|null,
     *   is_complete?: bool,
     *   config_ids?: list<int>,
     *   config_placements?: array<int, string>,
     *   validation?: array{ok?: bool, error_count?: int, warning_count?: int, issues?: list<array<string, mixed>>}
     * } $input
     * @return array{
     *   tier: string,
     *   has_page_specific: bool,
     *   has_sitewide: bool,
     *   types: list<string>,
     *   page_types: list<string>,
     *   sitewide_types: list<string>,
     *   invalid: bool,
     *   validation: array{ok: bool, error_count: int, warning_count: int, issues: list<array<string, mixed>>}
     * }
     */
    public static function classify(array $input): array {
        $graph_json = $input['graph_json'] ?? null;
        $graph_json = is_string($graph_json) ? $graph_json : null;
        $is_complete = !empty($input['is_complete']);
        $config_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['config_ids'] ?? [])))));
        /** @var array<int, string> $placements */
        $placements = [];
        foreach ((array) ($input['config_placements'] ?? []) as $id => $placement) {
            $placements[(int) $id] = (string) $placement;
        }

        $validation = $input['validation'] ?? null;
        if (!is_array($validation)) {
            $validation = Schema_Validator::validate($graph_json);
        }

        $types = Log::types_from_graph_json($graph_json);
        $sitewide_set = array_fill_keys(self::sitewide_types(), true);

        $page_types = [];
        $sitewide_types_present = [];
        foreach ($types as $type) {
            if (isset($sitewide_set[$type])) {
                $sitewide_types_present[] = $type;
            } else {
                $page_types[] = $type;
            }
        }
        $page_types = array_values(array_unique($page_types));
        $sitewide_types_present = array_values(array_unique($sitewide_types_present));

        $has_page_config = false;
        $has_global_config = false;
        foreach ($config_ids as $id) {
            $placement = $placements[$id] ?? '';
            if ($placement === 'global' || $placement === 'sitewide') {
                $has_global_config = true;
            } else {
                // targeted / unknown → treat as page-targeted when listed on the snapshot
                $has_page_config = true;
            }
        }

        $has_rich_types = $page_types !== [];
        // Hybrid: page-targeted placement wins even for bare WebPage.
        $has_page_specific = $has_page_config || $has_rich_types;
        $has_sitewide = $has_global_config || $sitewide_types_present !== [];

        $present = $graph_json !== null && trim($graph_json) !== '' && $types !== [];
        // Missing schema is a coverage gap (tier none), not a validation issue.
        $invalid = $present && ((int) ($validation['error_count'] ?? 0)) > 0;

        if (!$present) {
            return self::result(self::TIER_NONE, false, $has_sitewide, $types, $page_types, $sitewide_types_present, false, $validation);
        }

        if (!$has_page_specific) {
            return self::result(self::TIER_SITEWIDE_ONLY, false, true, $types, $page_types, $sitewide_types_present, $invalid, $validation);
        }

        if (!$is_complete) {
            return self::result(self::TIER_PAGE_INCOMPLETE, true, $has_sitewide, $types, $page_types, $sitewide_types_present, $invalid, $validation);
        }

        if ($invalid) {
            return self::result(self::TIER_PAGE_COMPLETE, true, $has_sitewide, $types, $page_types, $sitewide_types_present, true, $validation);
        }

        return self::result(self::TIER_VALIDATED, true, $has_sitewide, $types, $page_types, $sitewide_types_present, false, $validation);
    }

    /**
     * @param list<string> $types
     * @param list<string> $page_types
     * @param list<string> $sitewide_types
     * @param array{ok: bool, error_count: int, warning_count: int, issues: list<array<string, mixed>>} $validation
     * @return array{
     *   tier: string,
     *   has_page_specific: bool,
     *   has_sitewide: bool,
     *   types: list<string>,
     *   page_types: list<string>,
     *   sitewide_types: list<string>,
     *   invalid: bool,
     *   validation: array{ok: bool, error_count: int, warning_count: int, issues: list<array<string, mixed>>}
     * }
     */
    private static function result(
        string $tier,
        bool $has_page_specific,
        bool $has_sitewide,
        array $types,
        array $page_types,
        array $sitewide_types,
        bool $invalid,
        array $validation
    ): array {
        return [
            'tier'              => $tier,
            'has_page_specific' => $has_page_specific,
            'has_sitewide'      => $has_sitewide,
            'types'             => array_values($types),
            'page_types'        => array_values($page_types),
            'sitewide_types'    => array_values($sitewide_types),
            'invalid'           => $invalid,
            'validation'        => $validation,
        ];
    }
}
