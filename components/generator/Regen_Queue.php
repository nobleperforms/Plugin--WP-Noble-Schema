<?php

declare(strict_types=1);

namespace NobleSchema\Generator;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Target_Repository;

class Regen_Queue {
    public const OPTION_KEY = 'ns_regen_queue'; // legacy ID list (cleared on migrate)
    public const OPTION_TOTAL = 'ns_regen_total'; // legacy
    public const OPTION_JOB = 'ns_regen_job';
    public const OPTION_CANCELLED = 'ns_regen_cancelled';
    public const CRON_HOOK = 'ns_schema_regen_batch';
    private const BATCH_SIZE = 5;
    /** Soft skip posts whose content is large enough to risk parse_blocks OOM. */
    private const MAX_CONTENT_BYTES = 800000;
    /** Cap IDs listed on cancel/error regen logs to avoid huge option/log payloads. */
    private const UNPROCESSED_LOG_IDS_CAP = 500;

    /**
     * @param list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>> $groups
     * @return list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>
     */
    public static function normalize_filter_groups(array $groups): array {
        $normalized = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $rules = [];
            foreach ($group as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $type = sanitize_key((string) ($rule['type'] ?? ''));
                if ($type === 'taxonomy') {
                    $taxonomy_rule = \NobleSchema\Targeting\Taxonomy_Rule::normalize_rule($rule);
                    if ($taxonomy_rule !== null) {
                        $rules[] = $taxonomy_rule;
                    }
                    continue;
                }

                if ($type === 'has_block') {
                    $block = strtolower(trim(sanitize_text_field((string) ($rule['block'] ?? ''))));
                    if ($block === '') {
                        continue;
                    }
                    $rules[] = [
                        'type'  => 'has_block',
                        'block' => $block,
                    ];
                    continue;
                }

                if ($type === 'post_meta') {
                    $meta_rule = \NobleSchema\Targeting\Post_Meta_Rule::normalize_rule($rule);
                    if ($meta_rule !== null) {
                        $rules[] = $meta_rule;
                    }
                    continue;
                }

                if ($type === 'language') {
                    $language_rule = \NobleSchema\Targeting\Language_Rule::normalize_rule($rule);
                    if ($language_rule !== null) {
                        $rules[] = $language_rule;
                    }
                }
            }

            if ($rules !== []) {
                $normalized[] = $rules;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @return list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>
     */
    public static function filter_groups_from_target_rows(array $rows): array {
        $grouped = [];
        foreach ($rows as $row) {
            $row = is_object($row) ? $row : (object) $row;
            $type = (string) ($row->rule_type ?? '');

            if ($type === 'taxonomy') {
                $group = (int) ($row->rule_group ?? 0);
                $grouped[$group][] = [
                    'type'     => 'taxonomy',
                    'taxonomy' => (string) ($row->rule_key ?? ''),
                    'term_id'  => (int) ($row->rule_value ?? 0),
                    'operator' => (string) ($row->operator ?? 'is'),
                ];
                continue;
            }

            if ($type === 'has_block') {
                $block = strtolower(trim((string) ($row->rule_value ?? '')));
                if ($block === '') {
                    continue;
                }
                $group = (int) ($row->rule_group ?? 0);
                $grouped[$group][] = [
                    'type'  => 'has_block',
                    'block' => $block,
                ];
                continue;
            }

            if ($type === 'post_meta') {
                $group = (int) ($row->rule_group ?? 0);
                $grouped[$group][] = [
                    'type'     => 'post_meta',
                    'key'      => (string) ($row->rule_key ?? ''),
                    'value'    => (string) ($row->rule_value ?? ''),
                    'operator' => (string) ($row->operator ?? 'is'),
                ];
                continue;
            }

            if ($type === 'language') {
                $group = (int) ($row->rule_group ?? 0);
                $grouped[$group][] = [
                    'type'     => 'language',
                    'language' => (string) ($row->rule_value ?? ''),
                    'operator' => (string) ($row->operator ?? 'is'),
                ];
            }
        }

        if ($grouped === []) {
            return [];
        }

        ksort($grouped);

        return self::normalize_filter_groups(array_values($grouped));
    }

    /**
     * @param list<list<array<string, mixed>>> $a
     * @param list<list<array<string, mixed>>> $b
     * @return list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>
     */
    public static function merge_filter_groups(array $a, array $b): array {
        $a = self::normalize_filter_groups($a);
        $b = self::normalize_filter_groups($b);
        if ($a === [] || $b === []) {
            return [];
        }

        return array_merge($a, $b);
    }

    public function register(): void {
        add_action(self::CRON_HOOK, [$this, 'process_batch']);
    }

    public function enqueue_for_config(int $config_id): void {
        $config = (new Config_Repository())->find($config_id);
        if (!$config) {
            return;
        }

        $targets = (new Target_Repository())->for_config($config_id);
        $this->enqueue_for_config_targets($config, $targets);
    }

    /**
     * @param object $config Current (post-save) config row.
     * @param array<int, object|array<string, mixed>> $target_rows Current targets.
     * @param object|null $previous_config Pre-save config when retargeting / status changes.
     * @param array<int, object|array<string, mixed>> $previous_target_rows Pre-save targets (unioned into regen scope).
     */
    public function enqueue_for_config_targets(
        object $config,
        array $target_rows,
        ?object $previous_config = null,
        array $previous_target_rows = []
    ): void {
        $config_id = (int) ($config->id ?? 0);
        $placement = (string) ($config->placement ?? '');
        $previous_placement = $previous_config
            ? (string) ($previous_config->placement ?? '')
            : $placement;
        $is_active = (string) ($config->status ?? '') === 'active';

        if ($placement === 'sitewide' && $previous_placement === 'sitewide') {
            if ($is_active) {
                \NobleSchema\plugin()->generator()->rebuild_sitewide_config($config_id);
            } else {
                (new \NobleSchema\Cache\Sitewide_Cache())->delete($config_id);
            }

            return;
        }

        if ($previous_placement === 'sitewide' && $placement !== 'sitewide') {
            (new \NobleSchema\Cache\Sitewide_Cache())->delete($config_id);
        }

        $new_rows = $this->normalize_target_rows($target_rows);
        $old_rows = $this->normalize_target_rows($previous_target_rows);
        $all_rows = array_merge($old_rows, $new_rows);

        $new_post_ids = $this->post_ids_from_rows($new_rows);
        $old_post_ids = $this->post_ids_from_rows($old_rows);
        $post_ids = array_values(array_unique(array_merge($old_post_ids, $new_post_ids)));
        foreach ($post_ids as $post_id) {
            \NobleSchema\plugin()->generator()->generate_for_post($post_id);
        }

        $this->refresh_specialty_caches_from_rows($all_rows);

        $scope_new = $this->resolve_config_scope($new_rows);
        $scope_old = $this->resolve_config_scope($old_rows);
        // First save / no previous targets: use the new filters as-is.
        // When both sides exist, empty filters on either side means that side was
        // unfiltered — keep the union broad so we don't under-scan the old set.
        $filters = $old_rows === []
            ? $scope_new['filters']
            : self::merge_filter_groups($scope_new['filters'], $scope_old['filters']);

        if ($this->should_scan_all_public($config, $previous_config, $all_rows)) {
            $queued = array_values(get_post_types(['public' => true]));
            $this->enqueue_scope($queued, [], [], $filters);
            $this->credit_immediate_posts($this->posts_outside_scope($post_ids, $queued, [], [], $filters));
            $rescan_extra = 0;
            if (!$this->should_scan_all_public($config, null, $new_rows)) {
                $union_work = $this->count_regen_work($post_ids, $queued, [], [], $filters);
                $new_work = $this->count_regen_work(
                    $new_post_ids,
                    $scope_new['post_types'],
                    $scope_new['page_templates'],
                    $scope_new['blocks'],
                    $scope_new['filters']
                );
                $rescan_extra = max(0, $union_work - $new_work);
            }
            $this->apply_rescan_extra($rescan_extra);
            if ($placement === 'sitewide' && $is_active) {
                \NobleSchema\plugin()->generator()->rebuild_sitewide_config($config_id);
            }

            return;
        }

        $post_types = array_values(array_unique(array_filter(array_merge(
            $scope_new['post_types'],
            $scope_old['post_types']
        ))));
        $page_templates = array_values(array_unique(array_filter(array_merge(
            $scope_new['page_templates'],
            $scope_old['page_templates']
        ))));
        $blocks = array_values(array_unique(array_filter(array_merge(
            $scope_new['blocks'],
            $scope_old['blocks']
        ))));

        $union_work = $this->count_regen_work($post_ids, $post_types, $page_templates, $blocks, $filters);
        $new_work = $this->count_regen_work(
            $new_post_ids,
            $scope_new['post_types'],
            $scope_new['page_templates'],
            $scope_new['blocks'],
            $scope_new['filters']
        );
        $rescan_extra = $old_rows === [] ? 0 : max(0, $union_work - $new_work);

        if ($post_types !== [] || $page_templates !== [] || $blocks !== [] || $filters !== []) {
            $this->enqueue_scope($post_types, $page_templates, $blocks, $filters);
        }
        $this->credit_immediate_posts(
            $this->posts_outside_scope($post_ids, $post_types, $page_templates, $blocks, $filters)
        );
        $this->apply_rescan_extra($rescan_extra);

        if ($placement === 'sitewide' && $is_active) {
            \NobleSchema\plugin()->generator()->rebuild_sitewide_config($config_id);
        } elseif ($placement === 'sitewide') {
            (new \NobleSchema\Cache\Sitewide_Cache())->delete($config_id);
        }
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @return array<int, object>
     */
    private function normalize_target_rows(array $rows): array {
        $normalized = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $normalized[] = $row;
                continue;
            }
            if (is_array($row)) {
                $normalized[] = (object) $row;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, object> $all_rows
     */
    private function should_scan_all_public(object $config, ?object $previous_config, array $all_rows): bool {
        foreach ([$config, $previous_config] as $cfg) {
            if ($cfg && (string) ($cfg->placement ?? '') === 'global') {
                return true;
            }
        }

        return false;
    }

    public function enqueue_all_public_posts(): void {
        $this->enqueue_post_types(array_values(get_post_types(['public' => true])));
    }

    /**
     * Estimate how many posts a tools targeting selection will regenerate.
     *
     * @param array{
     *   placement?: string,
     *   post_types?: string[],
     *   page_templates?: string[],
     *   blocks?: string[],
     *   filter_groups?: list<list<array<string, mixed>>>,
     *   posts?: int[],
     *   archives?: string[],
     *   specialties?: string[]
     * } $targeting
     * @return array{posts:int,archives:int,specialties:int,queue_types:string[],page_templates:string[],blocks:string[]}
     */
    public function preview_targeting_count(array $targeting): array {
        $normalized = $this->normalize_tools_targeting($targeting);
        $scope = $this->resolve_tools_queue_scope($normalized);
        $queue_count = $this->count_scoped(
            $scope['post_types'],
            $scope['page_templates'],
            $scope['blocks'],
            $scope['filters']
        );

        $specialty_keys = $normalized['placement'] === 'global'
            ? \NobleSchema\Targeting\Specialty_Context::all_targeting_keys()
            : $normalized['specialties'];

        $specialty_posts = [];
        $specialty_virtual = 0;
        foreach ($specialty_keys as $key) {
            if (\NobleSchema\Targeting\Specialty_Context::is_virtual($key)) {
                $specialty_virtual++;
                continue;
            }
            $resolved = \NobleSchema\Targeting\Specialty_Context::resolve_post_id($key);
            if ($resolved > 0) {
                $specialty_posts[] = $resolved;
            }
        }

        $posts = array_values(array_unique(array_merge($normalized['posts'], $specialty_posts)));
        $extra_ids = $this->posts_outside_scope(
            $posts,
            $scope['post_types'],
            $scope['page_templates'],
            $scope['blocks'],
            $scope['filters']
        );
        $extra = count($extra_ids);

        $archive_keys = $normalized['placement'] === 'global'
            ? \NobleSchema\Targeting\Archive_Context::all_targeting_keys()
            : $normalized['archives'];

        $languages = $this->count_languages_for_preview(
            $scope['post_types'],
            $scope['page_templates'],
            $scope['blocks'],
            $scope['filters'],
            $extra_ids
        );

        return [
            'posts'          => $queue_count + $extra,
            'archives'       => count($this->expand_archive_selections($archive_keys)),
            'specialties'    => $specialty_virtual,
            'languages'      => $languages,
            'queue_types'    => $scope['post_types'],
            'page_templates' => $scope['page_templates'],
            'blocks'         => $scope['blocks'],
        ];
    }

    /**
     * Enqueue regeneration from Schema Tools batch targeting.
     *
     * @param array{
     *   placement?: string,
     *   post_types?: string[],
     *   page_templates?: string[],
     *   blocks?: string[],
     *   posts?: int[],
     *   archives?: string[],
     *   specialties?: string[]
     * } $targeting
     * @return array{total:int,remaining:int,processed:int,percent:int,done:bool,cancelled:bool,batch_size:int,immediate_posts:int,archives_refreshed:int,specialties_refreshed:int}
     */
    public function enqueue_from_targeting(array $targeting): array {
        $normalized = $this->normalize_tools_targeting($targeting);

        $immediate_posts = $normalized['posts'];
        $specialties_refreshed = 0;
        $specialty_keys = $normalized['placement'] === 'global'
            ? \NobleSchema\Targeting\Specialty_Context::all_targeting_keys()
            : $normalized['specialties'];
        if ($specialty_keys !== []) {
            $cache = new \NobleSchema\Cache\Specialty_Cache();
            $generator = \NobleSchema\plugin()->generator();
            foreach ($specialty_keys as $key) {
                if (\NobleSchema\Targeting\Specialty_Context::is_virtual($key)) {
                    $cache->delete($key);
                    $generator->graph_for_specialty($key, true);
                    $specialties_refreshed++;
                    continue;
                }
                $resolved = \NobleSchema\Targeting\Specialty_Context::resolve_post_id($key);
                if ($resolved > 0) {
                    $immediate_posts[] = $resolved;
                }
            }
        }
        $immediate_posts = array_values(array_unique(array_filter(array_map('intval', $immediate_posts))));

        $immediate = 0;
        foreach ($immediate_posts as $post_id) {
            if ($post_id <= 0 || !get_post($post_id) instanceof \WP_Post) {
                continue;
            }
            \NobleSchema\plugin()->generator()->generate_for_post($post_id);
            $immediate++;
        }

        $archives_refreshed = 0;
        $archive_keys = $normalized['placement'] === 'global'
            ? \NobleSchema\Targeting\Archive_Context::all_targeting_keys()
            : $normalized['archives'];
        if ($archive_keys !== []) {
            $identities = $this->expand_archive_selections($archive_keys);
            if ($identities !== []) {
                $this->enqueue_archives($identities);
                $archives_refreshed = count($identities);
            }
        }

        $scope = $this->resolve_tools_queue_scope($normalized);
        if ($scope['post_types'] !== [] || $scope['page_templates'] !== [] || $scope['blocks'] !== [] || $scope['filters'] !== []) {
            $this->enqueue_scope($scope['post_types'], $scope['page_templates'], $scope['blocks'], $scope['filters']);
        }
        $this->credit_immediate_posts($this->posts_outside_scope(
            $immediate_posts,
            $scope['post_types'],
            $scope['page_templates'],
            $scope['blocks'],
            $scope['filters']
        ));

        $status = $this->get_status();
        $status['immediate_posts'] = $immediate;
        $status['archives_refreshed'] = $archives_refreshed;
        $status['specialties_refreshed'] = $specialties_refreshed;
        return $status;
    }

    /**
     * Distinct WPML languages represented by the previewed post set.
     *
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     * @param list<int> $extra_post_ids
     */
    private function count_languages_for_preview(
        array $post_types,
        array $page_templates,
        array $blocks,
        array $filters,
        array $extra_post_ids
    ): int {
        if (!\NobleSchema\Integrations\Plugin_Integrations::is_wpml_active()) {
            return 0;
        }

        $codes = $this->query_distinct_languages_scoped($post_types, $page_templates, $blocks, $filters);
        foreach ($extra_post_ids as $post_id) {
            $code = \NobleSchema\Integrations\Plugin_Integrations::post_language_code((int) $post_id);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return count($codes);
    }

    /**
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     * @return array<string, true>
     */
    private function query_distinct_languages_scoped(
        array $post_types,
        array $page_templates,
        array $blocks,
        array $filters = []
    ): array {
        global $wpdb;

        $post_types = array_values(array_filter($post_types));
        $page_templates = array_values(array_filter($page_templates));
        $blocks = array_values(array_filter($blocks));
        $filters = self::normalize_filter_groups($filters);
        if ($post_types === [] && $page_templates === [] && $blocks === [] && $filters === []) {
            return [];
        }

        $table = $wpdb->prefix . 'icl_translations';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return [];
        }

        if ($page_templates !== [] && in_array('page', $post_types, true)) {
            $page_templates = [];
        }

        $filter_built = $this->build_filter_groups_sql($filters);
        $lang_join = "INNER JOIN {$table} icl_lang
            ON icl_lang.element_id = p.ID
           AND icl_lang.element_type = CONCAT('post_', p.post_type)";

        if ($page_templates === [] && $blocks === []) {
            if ($post_types === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $params = array_merge($post_types, $filter_built['params']);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- join/filter fragments are builder-controlled.
            $sql = $wpdb->prepare(
                "SELECT DISTINCT icl_lang.language_code
                 FROM {$wpdb->posts} p
                 {$lang_join}
                 WHERE p.post_status = 'publish' AND p.post_type IN ($placeholders)
                 {$filter_built['sql']}",
                ...$params
            );
        } else {
            $built = $this->build_scope_or_query($post_types, $page_templates, $blocks);
            if ($built === null) {
                return [];
            }
            $params = array_merge($built['params'], $filter_built['params']);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- join/filter fragments are builder-controlled.
            $sql = $wpdb->prepare(
                "SELECT DISTINCT icl_lang.language_code
                 FROM {$wpdb->posts} p
                 {$built['join']}
                 {$lang_join}
                 WHERE p.post_status = 'publish'
                   AND ({$built['where']})
                   {$filter_built['sql']}",
                ...$params
            );
        }

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $code) {
            $code = strtolower(trim((string) $code));
            $code = preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
            if ($code !== '') {
                $out[$code] = true;
            }
        }

        return $out;
    }

    /**
     * Count specific post IDs that are not already covered by the queued scope.
     *
     * @param int[] $post_ids
     * @param string[] $queued_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     */
    private function count_posts_outside_scope(
        array $post_ids,
        array $queued_types,
        array $page_templates,
        array $blocks = [],
        array $filters = []
    ): int {
        return count($this->posts_outside_scope($post_ids, $queued_types, $page_templates, $blocks, $filters));
    }

    /**
     * Specific post IDs regenerated immediately that the queued scan will not revisit.
     *
     * @param int[] $post_ids
     * @param string[] $queued_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     * @return list<int>
     */
    private function posts_outside_scope(
        array $post_ids,
        array $queued_types,
        array $page_templates,
        array $blocks = [],
        array $filters = []
    ): array {
        $extra = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $post_ids)))) as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            if ($this->post_matches_scope($post, $queued_types, $page_templates, $blocks, $filters)) {
                continue;
            }
            $extra[] = $post_id;
        }

        return $extra;
    }

    /**
     * @param string[] $queued_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     */
    private function post_matches_scope(
        \WP_Post $post,
        array $queued_types,
        array $page_templates,
        array $blocks = [],
        array $filters = []
    ): bool {
        $matches_placement = false;

        if ($queued_types !== [] && in_array($post->post_type, $queued_types, true)) {
            $matches_placement = true;
        }

        if (!$matches_placement && $page_templates !== [] && $post->post_type === 'page') {
            $template = (string) (get_page_template_slug($post->ID) ?: '');
            if ($template !== '' && in_array($template, $page_templates, true)) {
                $matches_placement = true;
            }
        }

        if (!$matches_placement && $blocks !== []) {
            $found = array_map(
                'strtolower',
                \NobleSchema\Targeting\Matcher::extract_block_types_from_content((string) $post->post_content)
            );
            foreach ($blocks as $block) {
                if (in_array(strtolower((string) $block), $found, true)) {
                    $matches_placement = true;
                    break;
                }
            }
        }

        if (!$matches_placement) {
            return false;
        }

        if ($filters === []) {
            return true;
        }

        return $this->post_matches_filter_groups($post, $filters);
    }

    /**
     * @param list<list<array<string, mixed>>> $filter_groups
     */
    private function post_matches_filter_groups(\WP_Post $post, array $filter_groups): bool {
        $filter_groups = self::normalize_filter_groups($filter_groups);
        if ($filter_groups === []) {
            return true;
        }

        $context = \NobleSchema\Targeting\Matcher::context_from_post($post->ID, $post);
        foreach ($filter_groups as $group) {
            $group_ok = true;
            foreach ($group as $rule) {
                if (!$this->filter_rule_matches($rule, $context, $post)) {
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
     * @param array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string} $rule
     * @param array{post_id:int,post_type:string,page_template:string,block_types:string[],taxonomies:array<string,string[]>} $context
     */
    private function filter_rule_matches(array $rule, array $context, \WP_Post $post): bool {
        $operator = (string) ($rule['operator'] ?? 'is');
        $type = (string) ($rule['type'] ?? '');

        if ($type === 'taxonomy') {
            return \NobleSchema\Targeting\Taxonomy_Rule::matches_post(
                (int) $post->ID,
                (string) ($rule['taxonomy'] ?? ''),
                (int) ($rule['term_id'] ?? $rule['term'] ?? 0),
                $operator
            );
        }

        if ($type === 'has_block') {
            $block = strtolower((string) ($rule['block'] ?? ''));
            $content = (string) $post->post_content;
            $matched = str_contains($content, '<!-- wp:' . $block . ' ')
                || str_contains($content, '<!-- wp:' . $block . '-->');
            if (!$matched) {
                $matched = in_array($block, $context['block_types'] ?? [], true);
            }
            return $operator === 'is_not' ? !$matched : $matched;
        }

        if ($type === 'post_meta') {
            return \NobleSchema\Targeting\Post_Meta_Rule::matches_post(
                (int) $post->ID,
                (string) ($rule['key'] ?? ''),
                $operator,
                (string) ($rule['value'] ?? '')
            );
        }

        if ($type === 'language') {
            return \NobleSchema\Targeting\Language_Rule::matches_post(
                (int) $post->ID,
                (string) ($rule['language'] ?? ''),
                $operator
            );
        }

        return false;
    }

    /**
     * Fold already-regenerated posts into the active job so modal totals match estimates,
     * and record their IDs on the single regen log event.
     *
     * @param int[] $post_ids
     */
    private function credit_immediate_posts(array $post_ids): void {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if ($post_ids === []) {
            return;
        }

        $job = $this->get_job();
        if ($job === null) {
            // Immediate-only (no background queue): nothing for the modal to track.
            return;
        }

        $merged_ids = array_values(array_unique(array_merge(
            array_map('intval', (array) ($job['post_ids'] ?? [])),
            $post_ids
        )));
        $count = count($post_ids);
        $total = (int) $job['total'] + $count;
        $processed = (int) $job['processed'] + $count;
        $log_id = (int) ($job['log_id'] ?? 0);

        $this->save_job([
            'post_types'     => $job['post_types'],
            'page_templates' => $job['page_templates'],
            'blocks'         => $job['blocks'],
            'filters'        => $job['filters'] ?? [],
            'last_id'        => (int) $job['last_id'],
            'total'          => $total,
            'processed'      => $processed,
            'rescan_extra'   => (int) ($job['rescan_extra'] ?? 0),
            'log_id'         => $log_id,
            'post_ids'       => $merged_ids,
            'failed_ids'     => array_values(array_map('intval', (array) ($job['failed_ids'] ?? []))),
        ]);

        if ($log_id > 0 && \NobleSchema\Tools\Log::enabled()) {
            \NobleSchema\Tools\Log::update(
                $log_id,
                __('Schema regen in progress', 'noble-schema'),
                [
                    'category'  => 'regen_started',
                    'status'    => 'running',
                    'total'     => $total,
                    'processed' => $processed,
                    'post_ids'  => $merged_ids,
                ]
            );
        }
    }

    /**
     * @param int[] $post_ids
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     */
    private function count_regen_work(
        array $post_ids,
        array $post_types,
        array $page_templates,
        array $blocks = [],
        array $filters = []
    ): int {
        return $this->count_scoped($post_types, $page_templates, $blocks, $filters)
            + $this->count_posts_outside_scope($post_ids, $post_types, $page_templates, $blocks, $filters);
    }

    /**
     * @param array<int, object> $rows
     * @return int[]
     */
    private function post_ids_from_rows(array $rows): array {
        $post_ids = [];
        foreach ($rows as $row) {
            $type = (string) ($row->rule_type ?? '');
            if ($type === 'post_id' && (int) ($row->rule_value ?? 0) > 0) {
                $post_ids[] = (int) $row->rule_value;
                continue;
            }
            if ($type === 'specialty') {
                $key = sanitize_key((string) ($row->rule_value ?? ''));
                if ($key === '' || \NobleSchema\Targeting\Specialty_Context::is_virtual($key)) {
                    continue;
                }
                $resolved = \NobleSchema\Targeting\Specialty_Context::resolve_post_id($key);
                if ($resolved > 0) {
                    $post_ids[] = $resolved;
                }
            }
        }

        return array_values(array_unique($post_ids));
    }

    /**
     * @param array<int, object> $rows
     */
    private function refresh_specialty_caches_from_rows(array $rows): int {
        $keys = [];
        foreach ($rows as $row) {
            if ((string) ($row->rule_type ?? '') !== 'specialty') {
                continue;
            }
            $key = sanitize_key((string) ($row->rule_value ?? ''));
            if ($key === '' || !\NobleSchema\Targeting\Specialty_Context::is_virtual($key)) {
                continue;
            }
            $keys[] = $key;
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return 0;
        }

        $cache = new \NobleSchema\Cache\Specialty_Cache();
        $generator = \NobleSchema\plugin()->generator();
        $refreshed = 0;
        foreach ($keys as $key) {
            $cache->delete($key);
            $generator->graph_for_specialty($key, true);
            $refreshed++;
        }

        return $refreshed;
    }

    private function apply_rescan_extra(int $count): void {
        $job = $this->get_job();
        if ($job === null) {
            return;
        }

        $job['rescan_extra'] = max(0, $count);
        $this->save_job($job);
    }

    /**
     * @param array<string, mixed> $targeting
     * @return array{placement:string,post_types:string[],page_templates:string[],blocks:string[],filter_groups:list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>,posts:int[],archives:string[],specialties:string[]}
     */
    private function normalize_tools_targeting(array $targeting): array {
        $placement = (string) ($targeting['placement'] ?? 'targeted');
        if (!in_array($placement, ['global', 'targeted'], true)) {
            $placement = 'targeted';
        }

        $specialties = [];
        foreach ((array) ($targeting['specialties'] ?? []) as $key) {
            $key = sanitize_key((string) $key);
            if ($key !== '' && \NobleSchema\Targeting\Specialty_Context::is_known($key)) {
                $specialties[] = $key;
            }
        }

        return [
            'placement'      => $placement,
            'post_types'     => array_values(array_unique(array_filter(array_map('strval', (array) ($targeting['post_types'] ?? []))))),
            'page_templates' => array_values(array_unique(array_filter(array_map('strval', (array) ($targeting['page_templates'] ?? []))))),
            'blocks'         => array_values(array_unique(array_filter(array_map(
                static fn ($block) => strtolower(trim((string) $block)),
                (array) ($targeting['blocks'] ?? [])
            )))),
            'filter_groups'  => self::normalize_filter_groups((array) ($targeting['filter_groups'] ?? [])),
            'posts'          => array_values(array_unique(array_filter(array_map('intval', (array) ($targeting['posts'] ?? []))))),
            'archives'       => array_values(array_unique(array_filter(array_map('strval', (array) ($targeting['archives'] ?? []))))),
            'specialties'    => array_values(array_unique($specialties)),
        ];
    }

    /**
     * @param array{placement:string,post_types:string[],page_templates:string[],blocks:string[],filter_groups:list<list<array<string, mixed>>>,posts:int[],archives:string[]} $targeting
     * @return array{post_types:string[],page_templates:string[],blocks:string[],filters:list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>}
     */
    private function resolve_tools_queue_scope(array $targeting): array {
        if ($targeting['placement'] === 'global') {
            return [
                'post_types'     => array_values(get_post_types(['public' => true])),
                'page_templates' => [],
                'blocks'         => [],
                'filters'        => $targeting['filter_groups'],
            ];
        }

        return [
            'post_types'     => $targeting['post_types'],
            'page_templates' => $targeting['page_templates'],
            'blocks'         => [],
            'filters'        => $targeting['filter_groups'],
        ];
    }

    /**
     * @param string[] $post_types
     */
    /**
     * @param string[] $coarse_keys
     * @return list<string>
     */
    private function expand_archive_selections(array $coarse_keys): array {
        $identities = [];
        foreach ($coarse_keys as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            foreach (\NobleSchema\Targeting\Archive_Context::expand_to_identities($key) as $identity) {
                $identities[] = $identity;
            }
        }

        return array_values(array_unique($identities));
    }

    /**
     * Queue concrete archive identities into the cancelable regen job.
     *
     * @param string[] $identities
     */
    public function enqueue_archives(array $identities): void {
        $identities = array_values(array_unique(array_filter(array_map('strval', $identities))));
        if ($identities === []) {
            return;
        }

        $this->clear_cancelled_notice();
        $this->clear_legacy_options();

        $existing = $this->get_job();
        $archives = $identities;
        $archive_index = 0;
        $post_types = [];
        $page_templates = [];
        $blocks = [];
        $filters = [];
        $last_id = 0;
        $processed = 0;
        $rescan_extra = 0;
        $post_ids = [];
        $failed_ids = [];
        $log_id = 0;
        $post_total = 0;

        if ($existing !== null) {
            $archives = array_values(array_unique(array_merge(
                (array) ($existing['archives'] ?? []),
                $identities
            )));
            $archive_index = (int) ($existing['archive_index'] ?? 0);
            $post_types = (array) ($existing['post_types'] ?? []);
            $page_templates = (array) ($existing['page_templates'] ?? []);
            $blocks = (array) ($existing['blocks'] ?? []);
            $filters = (array) ($existing['filters'] ?? []);
            $last_id = (int) ($existing['last_id'] ?? 0);
            $processed = (int) ($existing['processed'] ?? 0);
            $rescan_extra = (int) ($existing['rescan_extra'] ?? 0);
            $post_ids = (array) ($existing['post_ids'] ?? []);
            $failed_ids = (array) ($existing['failed_ids'] ?? []);
            $log_id = (int) ($existing['log_id'] ?? 0);
        }

        $post_scope_total = 0;
        if ($post_types !== [] || $page_templates !== [] || $blocks !== [] || $filters !== []) {
            $post_scope_total = $this->count_scoped($post_types, $page_templates, $blocks, $filters);
        }
        $total = $post_scope_total + count($archives);
        if ($post_scope_total === 0) {
            // Archives-only: processed tracks archive_index progress.
            $processed = $archive_index;
        }

        $log_context = [
            'category' => 'regen_started',
            'status'   => 'running',
            'total'    => $total,
            'processed'=> $processed,
            'post_ids' => $post_ids,
        ];
        if ($log_id > 0) {
            \NobleSchema\Tools\Log::update(
                $log_id,
                __('Schema regen in progress', 'noble-schema'),
                $log_context
            );
        } else {
            $log_id = \NobleSchema\Tools\Log::insert(
                __('Schema regen in progress', 'noble-schema'),
                $log_context
            );
        }

        $this->save_job([
            'post_types'     => $post_types,
            'page_templates' => $page_templates,
            'blocks'         => $blocks,
            'filters'        => $filters,
            'archives'       => $archives,
            'archive_index'  => $archive_index,
            'last_id'        => $last_id,
            'total'          => $total,
            'processed'      => $processed,
            'rescan_extra'   => $rescan_extra,
            'log_id'         => $log_id,
            'post_ids'       => $post_ids,
            'failed_ids'     => $failed_ids,
        ]);
        $this->schedule_cron();
    }

    public function enqueue_post_types(array $post_types): void {
        $this->enqueue_scope($post_types, [], []);
    }

    /**
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     */
    public function enqueue_scope(
        array $post_types,
        array $page_templates = [],
        array $blocks = [],
        array $filters = []
    ): void {
        $post_types = array_values(array_unique(array_filter(array_map('strval', $post_types))));
        $page_templates = array_values(array_unique(array_filter(array_map('strval', $page_templates))));
        $blocks = array_values(array_unique(array_filter(array_map('strval', $blocks))));
        $filters = self::normalize_filter_groups($filters);
        $existing = $this->get_job();
        $existing_archives = $existing !== null ? (array) ($existing['archives'] ?? []) : [];
        $existing_archive_index = $existing !== null ? (int) ($existing['archive_index'] ?? 0) : 0;

        if ($post_types === [] && $page_templates === [] && $blocks === [] && $filters === [] && $existing_archives === []) {
            return;
        }

        $this->clear_cancelled_notice();
        $this->clear_legacy_options();

        $rescan_extra = 0;
        if ($existing !== null) {
            $post_types = array_values(array_unique(array_merge($existing['post_types'], $post_types)));
            $page_templates = array_values(array_unique(array_merge($existing['page_templates'], $page_templates)));
            $blocks = array_values(array_unique(array_merge($existing['blocks'], $blocks)));
            $filters = self::merge_filter_groups($existing['filters'] ?? [], $filters);
            $rescan_extra = (int) ($existing['rescan_extra'] ?? 0);
        }

        $total = $this->count_scoped($post_types, $page_templates, $blocks, $filters) + count($existing_archives);
        if ($total <= 0) {
            $this->clear_job('completed');
            return;
        }

        $log_id = $existing !== null ? (int) ($existing['log_id'] ?? 0) : 0;
        $processed = $existing !== null ? (int) ($existing['processed'] ?? 0) : 0;
        $last_id = $existing !== null ? (int) ($existing['last_id'] ?? 0) : 0;
        $post_ids = $existing !== null ? (array) ($existing['post_ids'] ?? []) : [];
        $failed_ids = $existing !== null ? (array) ($existing['failed_ids'] ?? []) : [];
        $log_context = [
            'category' => 'regen_started',
            'status'   => 'running',
            'total'    => $total,
            'processed'=> $processed,
            'post_ids' => $post_ids,
        ];
        if ($log_id > 0) {
            \NobleSchema\Tools\Log::update(
                $log_id,
                __('Schema regen in progress', 'noble-schema'),
                $log_context
            );
        } else {
            $log_id = \NobleSchema\Tools\Log::insert(
                __('Schema regen in progress', 'noble-schema'),
                $log_context
            );
        }

        $this->save_job([
            'post_types'     => $post_types,
            'page_templates' => $page_templates,
            'blocks'         => $blocks,
            'filters'        => $filters,
            'archives'       => array_values(array_filter(array_map('strval', $existing_archives))),
            'archive_index'  => $existing_archive_index,
            'last_id'        => $last_id,
            'total'          => $total,
            'processed'      => $processed,
            'rescan_extra'   => $rescan_extra,
            'log_id'         => $log_id,
            'post_ids'       => $post_ids,
            'failed_ids'     => $failed_ids,
        ]);
        $this->schedule_cron();
    }

    public function process_batch(): void {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        @set_time_limit(60);

        if ($this->is_cancel_requested()) {
            return;
        }

        $job = $this->get_job();
        if ($job === null) {
            return;
        }

        $remaining = max(0, (int) $job['total'] - (int) $job['processed']);
        $batch_size = $remaining > 0 && $remaining < self::BATCH_SIZE
            ? max(1, $remaining)
            : self::BATCH_SIZE;

        $ids = $this->fetch_next_ids(
            (array) ($job['post_types'] ?? []),
            (array) ($job['page_templates'] ?? []),
            (array) ($job['blocks'] ?? []),
            (array) ($job['filters'] ?? []),
            (int) $job['last_id'],
            $batch_size
        );
        if ($ids === []) {
            $this->process_archive_batch($job);
            return;
        }

        $generator = \NobleSchema\plugin()->generator();
        $last_id   = (int) $job['last_id'];
        $processed = (int) $job['processed'];
        $in_flight = 0;
        $post_ids = array_values(array_map('intval', (array) ($job['post_ids'] ?? [])));
        $failed_ids = array_values(array_map('intval', (array) ($job['failed_ids'] ?? [])));
        $log_id = (int) ($job['log_id'] ?? 0);

        // If a post fatals (e.g. memory exhaustion), advance the cursor so the queue
        // cannot get stuck retrying the same ID forever.
        register_shutdown_function(function () use (&$in_flight, &$last_id, &$processed, &$failed_ids, $job): void {
            if ($in_flight <= 0 || $this->is_cancel_requested()) {
                return;
            }
            $error = error_get_last();
            if ($error === null || !isset($error['message'])) {
                return;
            }
            if (!str_contains((string) $error['message'], 'Allowed memory size')) {
                return;
            }

            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $advanced_last = max($last_id, $in_flight);
            $advanced_processed = $processed + 1;
            $failed_ids[] = $in_flight;
            $failed_ids = array_values(array_unique(array_filter(array_map('intval', $failed_ids))));

            if (class_exists(\NobleSchema\Tools\Log::class)) {
                \NobleSchema\Tools\Log::error('Schema regen skipped post after memory exhaustion', [
                    'category' => 'regen_failed',
                    'post_id'  => $in_flight,
                ]);
            }

            if ($this->is_cancel_requested() || $this->get_job() === null) {
                return;
            }

            $next = $this->fetch_next_ids(
                $job['post_types'],
                $job['page_templates'],
                $job['blocks'],
                $job['filters'] ?? [],
                $advanced_last,
                1
            );
            if ($next === []) {
                // Do not clear a concurrent cancel notice when finishing the job.
                if (!$this->is_cancel_requested()) {
                    $this->save_job($this->job_with_archives($job, [
                        'post_types'     => $job['post_types'],
                        'page_templates' => $job['page_templates'],
                        'blocks'         => $job['blocks'],
                        'filters'        => $job['filters'] ?? [],
                        'last_id'        => $advanced_last,
                        'total'          => max((int) $job['total'], $advanced_processed),
                        'processed'      => $advanced_processed,
                        'rescan_extra'   => (int) ($job['rescan_extra'] ?? 0),
                        'log_id'         => (int) ($job['log_id'] ?? 0),
                        'post_ids'       => array_values(array_map('intval', (array) ($job['post_ids'] ?? []))),
                        'failed_ids'     => $failed_ids,
                    ]));
                    $fresh = $this->get_job();
                    if ($fresh !== null) {
                        $archives = (array) ($fresh['archives'] ?? []);
                        $archive_index = (int) ($fresh['archive_index'] ?? 0);
                        if ($archive_index < count($archives)) {
                            return;
                        }
                    }
                    $this->clear_job('completed');
                }
                return;
            }

            $this->save_job($this->job_with_archives($job, [
                'post_types'     => $job['post_types'],
                'page_templates' => $job['page_templates'],
                'blocks'         => $job['blocks'],
                'filters'        => $job['filters'] ?? [],
                'last_id'        => $advanced_last,
                'total'          => max((int) $job['total'], $advanced_processed),
                'processed'      => $advanced_processed,
                'rescan_extra'   => (int) ($job['rescan_extra'] ?? 0),
                'log_id'         => (int) ($job['log_id'] ?? 0),
                'post_ids'       => array_values(array_map('intval', (array) ($job['post_ids'] ?? []))),
                'failed_ids'     => $failed_ids,
            ]));
        });

        foreach ($ids as $post_id) {
            if ($this->is_cancel_requested()) {
                $this->write_regen_log_progress(
                    $log_id,
                    $this->job_snapshot($job, $last_id, $processed, $post_ids, $failed_ids),
                    $processed,
                    $post_ids,
                    'cancelled'
                );
                return;
            }

            $post_id = (int) $post_id;
            $in_flight = $post_id;

            if ($this->should_skip_post($post_id)) {
                $failed_ids[] = $post_id;
                if (class_exists(\NobleSchema\Tools\Log::class)) {
                    \NobleSchema\Tools\Log::error('Schema regen skipped oversized or unsafe post', [
                        'category' => 'regen_failed',
                        'post_id'  => $post_id,
                    ]);
                }
            } else {
                try {
                    $generator->generate_for_post($post_id);
                    $post_ids[] = $post_id;
                } catch (\Throwable $e) {
                    $failed_ids[] = $post_id;
                    if (class_exists(\NobleSchema\Tools\Log::class)) {
                        \NobleSchema\Tools\Log::error('Schema regen failed for post', [
                            'category' => 'regen_failed',
                            'post_id'  => $post_id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

            $last_id = max($last_id, $post_id);
            $processed++;
            $in_flight = 0;

            clean_post_cache($post_id);
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        $failed_ids = array_values(array_unique(array_filter(array_map('intval', $failed_ids))));

        // Another window may have cancelled while this batch was running — do not resurrect the job.
        if ($this->is_cancel_requested() || $this->get_job() === null) {
            $this->write_regen_log_progress(
                $log_id,
                $this->job_snapshot($job, $last_id, $processed, $post_ids, $failed_ids),
                $processed,
                $post_ids,
                'cancelled'
            );
            return;
        }

        $total = max((int) $job['total'], $processed);

        $this->write_regen_log_progress(
            $log_id,
            $this->job_snapshot($job, $last_id, $processed, $post_ids, $failed_ids),
            $processed,
            $post_ids,
            'running'
        );

        $next = $this->fetch_next_ids(
            $job['post_types'],
            $job['page_templates'],
            $job['blocks'],
            $job['filters'] ?? [],
            $last_id,
            1
        );
        if ($next === []) {
            // Re-check cancel: a concurrent cancel can land between the check above
            // and here; clear_job must not wipe that notice.
            if (!$this->is_cancel_requested()) {
                $this->save_job($this->job_with_archives($job, [
                    'post_types'     => $job['post_types'],
                    'page_templates' => $job['page_templates'],
                    'blocks'         => $job['blocks'],
                    'filters'        => $job['filters'] ?? [],
                    'last_id'        => $last_id,
                    'total'          => $total,
                    'processed'      => $processed,
                    'rescan_extra'   => (int) ($job['rescan_extra'] ?? 0),
                    'log_id'         => $log_id,
                    'post_ids'       => $post_ids,
                    'failed_ids'     => $failed_ids,
                ]));
                $fresh = $this->get_job();
                if ($fresh !== null) {
                    $this->process_archive_batch($fresh);
                }
            }
            return;
        }

        $this->save_job($this->job_with_archives($job, [
            'post_types'     => $job['post_types'],
            'page_templates' => $job['page_templates'],
            'blocks'         => $job['blocks'],
            'filters'        => $job['filters'] ?? [],
            'last_id'        => $last_id,
            'total'          => $total,
            'processed'      => $processed,
            'rescan_extra'   => (int) ($job['rescan_extra'] ?? 0),
            'log_id'         => $log_id,
            'post_ids'       => $post_ids,
            'failed_ids'     => $failed_ids,
        ]));
        $this->schedule_cron();
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function job_with_archives(array $job, array $fields): array {
        $fields['archives'] = array_values(array_filter(array_map('strval', (array) ($job['archives'] ?? []))));
        $fields['archive_index'] = max(0, (int) ($job['archive_index'] ?? 0));

        return $fields;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function process_archive_batch(array $job): void {
        if ($this->is_cancel_requested()) {
            return;
        }

        $archives = array_values(array_filter(array_map('strval', (array) ($job['archives'] ?? []))));
        $archive_index = max(0, (int) ($job['archive_index'] ?? 0));
        if ($archive_index >= count($archives)) {
            if (!$this->is_cancel_requested()) {
                $this->clear_job('completed');
            }
            return;
        }

        $remaining = max(0, (int) $job['total'] - (int) $job['processed']);
        $batch_size = $remaining > 0 && $remaining < self::BATCH_SIZE
            ? max(1, $remaining)
            : self::BATCH_SIZE;

        $slice = array_slice($archives, $archive_index, $batch_size);
        $generator = \NobleSchema\plugin()->generator();
        $cache = new \NobleSchema\Cache\Archive_Cache();
        $processed = (int) $job['processed'];
        $log_id = (int) ($job['log_id'] ?? 0);

        foreach ($slice as $identity) {
            if ($this->is_cancel_requested()) {
                return;
            }

            $cache->delete($identity);
            try {
                $generator->graph_for_archive($identity, true);
            } catch (\Throwable $e) {
                if (class_exists(\NobleSchema\Tools\Log::class)) {
                    \NobleSchema\Tools\Log::error('Schema regen failed for archive', [
                        'category' => 'regen_failed',
                        'archive'  => $identity,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $archive_index++;
            $processed++;
        }

        if ($this->is_cancel_requested() || $this->get_job() === null) {
            return;
        }

        $this->save_job($this->job_with_archives(array_merge($job, [
            'archive_index' => $archive_index,
        ]), [
            'post_types'     => $job['post_types'] ?? [],
            'page_templates' => $job['page_templates'] ?? [],
            'blocks'         => $job['blocks'] ?? [],
            'filters'        => $job['filters'] ?? [],
            'last_id'        => (int) ($job['last_id'] ?? 0),
            'total'          => max((int) $job['total'], $processed),
            'processed'      => $processed,
            'rescan_extra'   => (int) ($job['rescan_extra'] ?? 0),
            'log_id'         => $log_id,
            'post_ids'       => (array) ($job['post_ids'] ?? []),
            'failed_ids'     => (array) ($job['failed_ids'] ?? []),
            'archives'       => $archives,
            'archive_index'  => $archive_index,
        ]));

        if ($archive_index >= count($archives)) {
            if (!$this->is_cancel_requested()) {
                $this->clear_job('completed');
            }
            return;
        }

        $this->schedule_cron();
    }

    private function should_skip_post(int $post_id): bool {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return true;
        }

        $content = (string) $post->post_content;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return true;
        }

        $limit = $this->memory_limit_bytes();
        if ($limit > 0 && memory_get_usage(true) > (int) ($limit * 0.85)) {
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            if (memory_get_usage(true) > (int) ($limit * 0.85)) {
                return true;
            }
        }

        return false;
    }

    private function memory_limit_bytes(): int {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $limit,
        };
    }

    public function is_pending(): bool {
        return $this->get_job() !== null;
    }

    public function pending_count(): int {
        $status = $this->get_status();
        return (int) $status['remaining'];
    }

    public function get_total(): int {
        $job = $this->get_job();
        return $job ? (int) $job['total'] : 0;
    }

    /**
     * @return array{total:int,remaining:int,processed:int,percent:int,done:bool,cancelled:bool,batch_size:int,rescan_extra:int}
     */
    public function get_status(): array {
        $job = $this->get_job();
        if ($job === null) {
            $cancelled = $this->get_cancelled_notice();
            if ($cancelled !== null) {
                return $cancelled;
            }

            return [
                'total'        => 0,
                'remaining'    => 0,
                'processed'    => 0,
                'percent'      => 100,
                'done'         => true,
                'cancelled'    => false,
                'batch_size'   => self::BATCH_SIZE,
                'rescan_extra' => 0,
            ];
        }

        $total     = max(0, (int) $job['total']);
        $processed = max(0, min($total, (int) $job['processed']));
        $remaining = max(0, $total - $processed);
        $percent   = $total > 0 ? (int) min(100, round(($processed / $total) * 100)) : 100;

        return [
            'total'        => $total,
            'remaining'    => $remaining,
            'processed'    => $processed,
            'percent'      => $remaining === 0 ? 100 : $percent,
            'done'         => false,
            'cancelled'    => false,
            'batch_size'   => self::BATCH_SIZE,
            'rescan_extra' => max(0, (int) ($job['rescan_extra'] ?? 0)),
        ];
    }

    /**
     * @return array{total:int,remaining:int,processed:int,percent:int,done:bool,cancelled:bool,batch_size:int}
     */
    public function cancel(): array {
        $job = $this->get_job();
        $total = $job ? max(0, (int) $job['total']) : 0;
        $processed = $job ? max(0, min($total, (int) $job['processed'])) : 0;
        $percent = $total > 0 ? (int) min(100, round(($processed / $total) * 100)) : 0;
        $cancelled_by = $this->current_user_log_actor();

        $status = [
            'total'         => $total,
            'remaining'     => 0,
            'processed'     => $processed,
            'percent'       => $percent,
            'done'          => true,
            'cancelled'     => true,
            'batch_size'    => self::BATCH_SIZE,
            'at'            => time(),
            'cancelled_by'  => $cancelled_by,
        ];

        // Persist cancel notice first so an in-flight batch can read cancelled_by
        // and flush its latest cursor / failed_ids before we finalize the log.
        update_option(self::OPTION_CANCELLED, $status, false);

        $job = $this->get_job() ?? $job;
        if ($job !== null) {
            $this->finalize_regen_log($job, 'cancelled', $cancelled_by);
        }

        delete_option(self::OPTION_JOB);
        $this->clear_legacy_options();
        // Do not clear the cron hook here: if wp-cron.php is mid-run of this
        // hook, unscheduling races the cron option and logs could_not_set.
        // A leftover single event will no-op once it sees no job / cancelled.

        return $status;
    }

    public function acknowledge_cancelled(): void {
        $this->clear_cancelled_notice();
    }

    public function is_cancelled(): bool {
        return $this->is_cancel_requested();
    }

    /**
     * Expose the active cursor job for debug tooling (null when idle).
     *
     * @return array{post_types:string[],page_templates:string[],blocks:string[],filters:list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>,last_id:int,total:int,processed:int,rescan_extra:int}|null
     */
    public function get_job_debug(): ?array {
        return $this->get_job();
    }

    private function is_cancel_requested(): bool {
        return $this->get_cancelled_notice() !== null;
    }

    /**
     * @return array{total:int,remaining:int,processed:int,percent:int,done:bool,cancelled:bool,batch_size:int}|null
     */
    private function get_cancelled_notice(): ?array {
        $notice = get_option(self::OPTION_CANCELLED, null);
        if (!is_array($notice) || empty($notice['cancelled'])) {
            return null;
        }

        // Expire stale notices after 30 minutes.
        $at = (int) ($notice['at'] ?? 0);
        if ($at > 0 && (time() - $at) > 1800) {
            $this->clear_cancelled_notice();
            return null;
        }

        $total = max(0, (int) ($notice['total'] ?? 0));
        $processed = max(0, min($total, (int) ($notice['processed'] ?? 0)));
        $percent = $total > 0 ? (int) min(100, round(($processed / $total) * 100)) : 0;

        return [
            'total'      => $total,
            'remaining'  => 0,
            'processed'  => $processed,
            'percent'    => $percent,
            'done'       => true,
            'cancelled'  => true,
            'batch_size' => self::BATCH_SIZE,
        ];
    }

    private function clear_cancelled_notice(): void {
        delete_option(self::OPTION_CANCELLED);
    }

    /**
     * Clear the active job only. Cancelled notices are kept until acknowledge/expire
     * (or a new enqueue) so a finishing batch cannot erase a concurrent cancel.
     */
    private function clear_job(string $outcome = 'completed'): void {
        $job = $this->get_job();
        if ($job !== null) {
            $this->finalize_regen_log($job, $outcome);
        }
        delete_option(self::OPTION_JOB);
        $this->clear_legacy_options();
        $this->clear_schedule();
    }

    /**
     * @param array<string, mixed> $job
     * @param array{user_id?:int,user_login?:string,display_name?:string}|null $actor
     */
    private function finalize_regen_log(array $job, string $status, ?array $actor = null): void {
        $log_id = (int) ($job['log_id'] ?? 0);
        if ($log_id <= 0) {
            return;
        }

        $post_ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($job['post_ids'] ?? [])
        ))));
        $processed = max(0, (int) ($job['processed'] ?? count($post_ids)));
        $this->write_regen_log_progress($log_id, $job, $processed, $post_ids, $status, $actor);
    }

    /**
     * Keep a single regen log row in sync from start → progress → finish/cancel.
     *
     * @param array<string, mixed> $job
     * @param list<int> $post_ids
     * @param array{user_id?:int,user_login?:string,display_name?:string}|null $actor
     */
    private function write_regen_log_progress(
        int $log_id,
        array $job,
        int $processed,
        array $post_ids,
        string $status,
        ?array $actor = null
    ): void {
        if ($log_id <= 0 || !\NobleSchema\Tools\Log::enabled()) {
            return;
        }

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        $total = max(0, (int) ($job['total'] ?? 0), $processed);
        $context_status = match ($status) {
            'cancelled' => 'cancelled',
            'completed' => 'completed',
            default     => 'running',
        };
        $category = match ($status) {
            'cancelled' => 'regen_cancelled',
            'completed' => 'regen_completed',
            default     => 'regen_started',
        };

        if ($status === 'cancelled') {
            $actor = $this->normalize_log_actor($actor) ?? $this->cancelled_by_from_notice();
            $label = $this->actor_display_label($actor);
            $message = $label !== ''
                ? sprintf(
                    /* translators: %s: WordPress user display name or login */
                    __('Schema regen cancelled by %s', 'noble-schema'),
                    $label
                )
                : __('Schema regen cancelled', 'noble-schema');
        } else {
            $message = match ($status) {
                'completed' => __('Schema regen completed', 'noble-schema'),
                default     => __('Schema regen in progress', 'noble-schema'),
            };
        }

        $context = [
            'category'  => $category,
            'status'    => $context_status,
            'total'     => $total,
            'processed' => max(0, $processed),
            'updated'   => count($post_ids),
            'post_ids'  => $post_ids,
        ];
        if ($status === 'cancelled' && $actor !== null) {
            $context['cancelled_by'] = $actor;
        }

        if (in_array($status, ['cancelled', 'completed'], true)) {
            $unprocessed = $this->unprocessed_ids_for_log($job, $status);
            if ($unprocessed['ids'] !== []) {
                $context['unprocessed_ids'] = $unprocessed['ids'];
                $context['unprocessed'] = count($unprocessed['ids']);
            }
            if ($unprocessed['truncated']) {
                $context['unprocessed_truncated'] = true;
                $context['unprocessed_cap'] = self::UNPROCESSED_LOG_IDS_CAP;
            }
        } else {
            $failed = $this->normalize_id_list((array) ($job['failed_ids'] ?? []));
            if ($failed !== []) {
                $context['unprocessed_ids'] = $failed;
                $context['unprocessed'] = count($failed);
            }
        }

        \NobleSchema\Tools\Log::update(
            $log_id,
            $message,
            $context,
            $status === 'cancelled' ? 'warning' : 'info'
        );
    }

    /**
     * Persist latest batch progress (when the job still exists) and refresh the
     * cancel log with failed + remaining IDs.
     *
     * @param array<string, mixed> $job
     * @param list<int> $post_ids
     * @param list<int> $failed_ids
     */
    private function flush_cancel_progress(
        int $log_id,
        array $job,
        int $last_id,
        int $processed,
        array $post_ids,
        array $failed_ids
    ): void {
        $snapshot = $this->job_snapshot($job, $last_id, $processed, $post_ids, $failed_ids);
        // Only save when cancel() has not already deleted the job — avoid resurrecting it.
        if ($this->get_job() !== null) {
            $this->save_job($snapshot);
        }
        $this->write_regen_log_progress(
            $log_id,
            $snapshot,
            $processed,
            $post_ids,
            'cancelled'
        );
    }

    /**
     * @param array<string, mixed> $job
     * @param list<int> $post_ids
     * @param list<int> $failed_ids
     * @return array<string, mixed>
     */
    private function job_snapshot(
        array $job,
        int $last_id,
        int $processed,
        array $post_ids,
        array $failed_ids
    ): array {
        $job['last_id'] = $last_id;
        $job['processed'] = $processed;
        $job['post_ids'] = $this->normalize_id_list($post_ids);
        $job['failed_ids'] = $this->normalize_id_list($failed_ids);

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{ids:list<int>,truncated:bool}
     */
    private function unprocessed_ids_for_log(array $job, string $status): array {
        $failed = $this->normalize_id_list((array) ($job['failed_ids'] ?? []));
        if ($status !== 'cancelled') {
            return [
                'ids'       => array_slice($failed, 0, self::UNPROCESSED_LOG_IDS_CAP),
                'truncated' => count($failed) > self::UNPROCESSED_LOG_IDS_CAP,
            ];
        }

        $cap = self::UNPROCESSED_LOG_IDS_CAP;
        $ids = array_slice($failed, 0, $cap);
        $truncated = count($failed) > $cap;
        $remaining_room = $cap - count($ids);
        if ($remaining_room > 0) {
            $remaining = $this->collect_remaining_ids($job, $remaining_room + 1);
            $truncated = $truncated || count($remaining) > $remaining_room;
            foreach (array_slice($remaining, 0, $remaining_room) as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        } else {
            // Still detect whether anything remained past the failed-id cap.
            $probe = $this->collect_remaining_ids($job, 1);
            $truncated = $truncated || $probe !== [];
        }

        return [
            'ids'       => array_values($ids),
            'truncated' => $truncated,
        ];
    }

    /**
     * Remaining publishable IDs after the regen cursor (capped).
     *
     * @param array<string, mixed> $job
     * @return list<int>
     */
    private function collect_remaining_ids(array $job, int $limit): array {
        $limit = max(0, $limit);
        if ($limit <= 0) {
            return [];
        }

        $collected = [];
        $last_id = max(0, (int) ($job['last_id'] ?? 0));
        while (count($collected) < $limit) {
            $batch_limit = min(self::BATCH_SIZE * 10, $limit - count($collected));
            $batch = $this->fetch_next_ids(
                (array) ($job['post_types'] ?? []),
                (array) ($job['page_templates'] ?? []),
                (array) ($job['blocks'] ?? []),
                (array) ($job['filters'] ?? []),
                $last_id,
                $batch_limit
            );
            if ($batch === []) {
                break;
            }
            foreach ($batch as $id) {
                $id = (int) $id;
                $collected[] = $id;
                $last_id = max($last_id, $id);
                if (count($collected) >= $limit) {
                    break 2;
                }
            }
        }

        return $collected;
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function normalize_id_list(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @return array{user_id:int,user_login:string,display_name:string}|null
     */
    private function current_user_log_actor(): ?array {
        if (!function_exists('wp_get_current_user')) {
            return null;
        }

        $user = wp_get_current_user();
        if (!$user instanceof \WP_User || (int) $user->ID <= 0) {
            return null;
        }

        return $this->normalize_log_actor([
            'user_id'      => (int) $user->ID,
            'user_login'   => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
        ]);
    }

    /**
     * @return array{user_id:int,user_login:string,display_name:string}|null
     */
    private function cancelled_by_from_notice(): ?array {
        $notice = get_option(self::OPTION_CANCELLED, null);
        if (!is_array($notice) || empty($notice['cancelled_by']) || !is_array($notice['cancelled_by'])) {
            return null;
        }

        return $this->normalize_log_actor($notice['cancelled_by']);
    }

    /**
     * @param array<string, mixed>|null $actor
     * @return array{user_id:int,user_login:string,display_name:string}|null
     */
    private function normalize_log_actor(?array $actor): ?array {
        if ($actor === null) {
            return null;
        }

        $user_id = max(0, (int) ($actor['user_id'] ?? 0));
        $user_login = sanitize_user((string) ($actor['user_login'] ?? ''), true);
        $display_name = sanitize_text_field((string) ($actor['display_name'] ?? ''));
        if ($user_id <= 0 && $user_login === '' && $display_name === '') {
            return null;
        }

        return [
            'user_id'      => $user_id,
            'user_login'   => $user_login,
            'display_name' => $display_name,
        ];
    }

    /**
     * @param array{user_id?:int,user_login?:string,display_name?:string}|null $actor
     */
    private function actor_display_label(?array $actor): string {
        if ($actor === null) {
            return '';
        }

        $display = trim((string) ($actor['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }

        return trim((string) ($actor['user_login'] ?? ''));
    }

    private function doing_regen_cron(): bool {
        return function_exists('wp_doing_cron') && wp_doing_cron();
    }

    private function clear_legacy_options(): void {
        // Delete without get_option() — legacy queues can be huge enough to OOM on load.
        delete_option(self::OPTION_KEY);
        delete_option(self::OPTION_TOTAL);
    }

    /**
     * @return array{post_types:string[],page_templates:string[],blocks:string[],filters:list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>,last_id:int,total:int,processed:int,rescan_extra:int}|null
     */
    private function get_job(): ?array {
        $job = get_option(self::OPTION_JOB, null);
        if (!is_array($job)) {
            return null;
        }

        $post_types = isset($job['post_types']) && is_array($job['post_types'])
            ? array_values(array_filter(array_map('strval', $job['post_types'])))
            : [];
        $page_templates = isset($job['page_templates']) && is_array($job['page_templates'])
            ? array_values(array_filter(array_map('strval', $job['page_templates'])))
            : [];
        $blocks = isset($job['blocks']) && is_array($job['blocks'])
            ? array_values(array_filter(array_map('strval', $job['blocks'])))
            : [];
        $filters = isset($job['filters']) && is_array($job['filters'])
            ? self::normalize_filter_groups($job['filters'])
            : [];
        $archives = isset($job['archives']) && is_array($job['archives'])
            ? array_values(array_filter(array_map('strval', $job['archives'])))
            : [];
        $archive_index = max(0, (int) ($job['archive_index'] ?? 0));

        if ($post_types === [] && $page_templates === [] && $blocks === [] && $filters === [] && $archives === []) {
            return null;
        }

        return [
            'post_types'     => $post_types,
            'page_templates' => $page_templates,
            'blocks'         => $blocks,
            'filters'        => $filters,
            'archives'       => $archives,
            'archive_index'  => $archive_index,
            'last_id'        => max(0, (int) ($job['last_id'] ?? 0)),
            'total'          => max(0, (int) ($job['total'] ?? 0)),
            'processed'      => max(0, (int) ($job['processed'] ?? 0)),
            'rescan_extra'   => max(0, (int) ($job['rescan_extra'] ?? 0)),
            'log_id'         => max(0, (int) ($job['log_id'] ?? 0)),
            'post_ids'       => array_values(array_unique(array_filter(array_map(
                'intval',
                (array) ($job['post_ids'] ?? [])
            )))),
            'failed_ids'     => array_values(array_unique(array_filter(array_map(
                'intval',
                (array) ($job['failed_ids'] ?? [])
            )))),
        ];
    }

    /**
     * @param array{post_types:string[],page_templates?:string[],blocks?:string[],filters?:list<list<array<string, mixed>>>,last_id:int,total:int,processed:int,rescan_extra?:int,log_id?:int,post_ids?:list<int>,failed_ids?:list<int>} $job
     */
    private function save_job(array $job): void {
        $job['page_templates'] = array_values(array_filter(array_map(
            'strval',
            (array) ($job['page_templates'] ?? [])
        )));
        $job['post_types'] = array_values(array_filter(array_map(
            'strval',
            (array) ($job['post_types'] ?? [])
        )));
        $job['blocks'] = array_values(array_filter(array_map(
            'strval',
            (array) ($job['blocks'] ?? [])
        )));
        $job['filters'] = self::normalize_filter_groups((array) ($job['filters'] ?? []));
        $job['archives'] = array_values(array_filter(array_map('strval', (array) ($job['archives'] ?? []))));
        $job['archive_index'] = max(0, (int) ($job['archive_index'] ?? 0));
        $job['rescan_extra'] = max(0, (int) ($job['rescan_extra'] ?? 0));
        $job['log_id'] = max(0, (int) ($job['log_id'] ?? 0));
        $job['post_ids'] = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($job['post_ids'] ?? [])
        ))));
        $job['failed_ids'] = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($job['failed_ids'] ?? [])
        ))));

        // Preserve log_id when a mid-batch save omits it.
        if ($job['log_id'] <= 0) {
            $existing = get_option(self::OPTION_JOB, null);
            if (is_array($existing) && !empty($existing['log_id'])) {
                $job['log_id'] = max(0, (int) $existing['log_id']);
            }
        }

        update_option(self::OPTION_JOB, $job, false);
    }

    /**
     * @param array<int, object> $target_rows
     * @return array{post_types:string[],page_templates:string[],blocks:string[],filters:list<list<array{type:string,taxonomy?:string,term?:string,operator?:string,block?:string}>>}
     */
    private function resolve_config_scope(array $target_rows): array {
        $post_types = [];
        $page_templates = [];

        foreach ($target_rows as $row) {
            $type = (string) ($row->rule_type ?? '');
            $value = (string) ($row->rule_value ?? '');
            if ($value === '') {
                continue;
            }
            if ($type === 'post_type') {
                $post_types[] = $value;
            } elseif ($type === 'page_template') {
                $page_templates[] = $value;
            }
        }

        return [
            'post_types'     => array_values(array_unique($post_types)),
            'page_templates' => array_values(array_unique($page_templates)),
            'blocks'         => [],
            'filters'        => self::filter_groups_from_target_rows($target_rows),
        ];
    }

    /**
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     */
    private function count_scoped(
        array $post_types,
        array $page_templates,
        array $blocks = [],
        array $filters = []
    ): int {
        $post_types = array_values(array_filter($post_types));
        $page_templates = array_values(array_filter($page_templates));
        $blocks = array_values(array_filter($blocks));
        $filters = self::normalize_filter_groups($filters);
        if ($post_types === [] && $page_templates === [] && $blocks === [] && $filters === []) {
            return 0;
        }

        if ($page_templates !== [] && in_array('page', $post_types, true)) {
            $page_templates = [];
        }

        if ($filters !== []) {
            return $this->query_count_scoped($post_types, $page_templates, $blocks, $filters);
        }

        if ($blocks === []) {
            return $this->query_count_scoped($post_types, $page_templates, [], []);
        }

        if ($post_types === [] && $page_templates === []) {
            return (new \NobleSchema\Cache\Block_Usage_Cache())->count_published_with_blocks($blocks);
        }

        $cache = new \NobleSchema\Cache\Block_Usage_Cache();
        $key = 'scope:' . md5((string) wp_json_encode([$post_types, $page_templates, $blocks]));
        return $cache->remember($key, function () use ($post_types, $page_templates, $blocks): int {
            return $this->query_count_scoped($post_types, $page_templates, $blocks, []);
        });
    }

    /**
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     */
    private function query_count_scoped(
        array $post_types,
        array $page_templates,
        array $blocks,
        array $filters = []
    ): int {
        global $wpdb;

        $filter_built = $this->build_filter_groups_sql($filters);

        if ($page_templates === [] && $blocks === []) {
            if ($post_types === []) {
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $params = array_merge($post_types, $filter_built['params']);
            $sql = $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} p
                 WHERE p.post_status = 'publish' AND p.post_type IN ($placeholders)
                 {$filter_built['sql']}",
                ...$params
            );
            return max(0, (int) $wpdb->get_var($sql));
        }

        $built = $this->build_scope_or_query($post_types, $page_templates, $blocks);
        if ($built === null) {
            return 0;
        }

        $params = array_merge($built['params'], $filter_built['params']);
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             {$built['join']}
             WHERE p.post_status = 'publish'
               AND ({$built['where']})
               {$filter_built['sql']}",
            ...$params
        );

        return max(0, (int) $wpdb->get_var($sql));
    }

    /**
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @param list<list<array<string, mixed>>> $filters
     * @return array<int>
     */
    private function fetch_next_ids(
        array $post_types,
        array $page_templates,
        array $blocks,
        array $filters,
        int $after_id,
        int $limit
    ): array {
        global $wpdb;

        $post_types = array_values(array_filter($post_types));
        $page_templates = array_values(array_filter($page_templates));
        $blocks = array_values(array_filter($blocks));
        $filters = self::normalize_filter_groups($filters);
        if (($post_types === [] && $page_templates === [] && $blocks === [] && $filters === []) || $limit <= 0) {
            return [];
        }

        if ($page_templates !== [] && in_array('page', $post_types, true)) {
            $page_templates = [];
        }

        $filter_built = $this->build_filter_groups_sql($filters);

        if ($page_templates === [] && $blocks === []) {
            if ($post_types === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $params = array_merge($post_types, $filter_built['params'], [$after_id, $limit]);
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} p
                 WHERE p.post_status = 'publish'
                   AND p.post_type IN ($placeholders)
                   {$filter_built['sql']}
                   AND p.ID > %d
                 ORDER BY p.ID ASC
                 LIMIT %d",
                ...$params
            );
            $ids = $wpdb->get_col($sql);
            return is_array($ids) ? array_map('intval', $ids) : [];
        }

        $built = $this->build_scope_or_query($post_types, $page_templates, $blocks);
        if ($built === null) {
            return [];
        }

        $params = array_merge($built['params'], $filter_built['params'], [$after_id, $limit]);
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             {$built['join']}
             WHERE p.post_status = 'publish'
               AND ({$built['where']})
               {$filter_built['sql']}
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            ...$params
        );

        $ids = $wpdb->get_col($sql);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * @param list<list<array<string, mixed>>> $filter_groups
     * @return array{sql:string,params:list<mixed>}
     */
    private function build_filter_groups_sql(array $filter_groups): array {
        $filter_groups = self::normalize_filter_groups($filter_groups);
        if ($filter_groups === []) {
            return ['sql' => '', 'params' => []];
        }

        global $wpdb;

        $group_clauses = [];
        $params = [];

        foreach ($filter_groups as $group) {
            $rule_clauses = [];
            foreach ($group as $rule) {
                if (($rule['type'] ?? '') === 'taxonomy') {
                    $taxonomy = (string) ($rule['taxonomy'] ?? '');
                    $term_id = (int) ($rule['term_id'] ?? $rule['term'] ?? 0);
                    $operator = (string) ($rule['operator'] ?? 'is');
                    $term_ids = \NobleSchema\Targeting\Taxonomy_Rule::expanded_term_ids($term_id, $taxonomy);
                    if ($taxonomy === '' || $term_ids === []) {
                        continue;
                    }

                    $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
                    $exists_sql = "EXISTS (
                        SELECT 1 FROM {$wpdb->term_relationships} tr
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                        WHERE tr.object_id = p.ID
                          AND tt.taxonomy = %s
                          AND tt.term_id IN ({$placeholders})
                    )";
                    if ($operator === 'is_not') {
                        $rule_clauses[] = "NOT {$exists_sql}";
                    } else {
                        $rule_clauses[] = $exists_sql;
                    }
                    $params[] = $taxonomy;
                    foreach ($term_ids as $id) {
                        $params[] = $id;
                    }
                    continue;
                }

                if (($rule['type'] ?? '') === 'has_block') {
                    $block = (string) ($rule['block'] ?? '');
                    if ($block === '') {
                        continue;
                    }
                    $escaped = $wpdb->esc_like($block);
                    $rule_clauses[] = '(p.post_content LIKE %s OR p.post_content LIKE %s)';
                    $params[] = '%<!-- wp:' . $escaped . ' %';
                    $params[] = '%<!-- wp:' . $escaped . '-->%';
                    continue;
                }

                if (($rule['type'] ?? '') === 'post_meta') {
                    $built = $this->build_post_meta_rule_sql($rule);
                    if ($built['sql'] === '') {
                        continue;
                    }
                    $rule_clauses[] = $built['sql'];
                    foreach ($built['params'] as $param) {
                        $params[] = $param;
                    }
                    continue;
                }

                if (($rule['type'] ?? '') === 'language') {
                    $built = $this->build_language_rule_sql($rule);
                    if ($built['sql'] === '') {
                        continue;
                    }
                    $rule_clauses[] = $built['sql'];
                    foreach ($built['params'] as $param) {
                        $params[] = $param;
                    }
                }
            }

            if ($rule_clauses !== []) {
                $group_clauses[] = '(' . implode(' AND ', $rule_clauses) . ')';
            }
        }

        if ($group_clauses === []) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql'    => ' AND (' . implode(' OR ', $group_clauses) . ')',
            'params' => $params,
        ];
    }

    /**
     * @param array{type?:string,key?:string,value?:string,operator?:string} $rule
     * @return array{sql:string,params:list<mixed>}
     */
    private function build_post_meta_rule_sql(array $rule): array {
        global $wpdb;

        $key = (string) ($rule['key'] ?? '');
        $operator = \NobleSchema\Targeting\Post_Meta_Rule::sanitize_operator((string) ($rule['operator'] ?? 'is'));
        $value = (string) ($rule['value'] ?? '');
        if ($key === '') {
            return ['sql' => '', 'params' => []];
        }

        $pm = $wpdb->postmeta;
        $base = "EXISTS (
            SELECT 1 FROM {$pm} pm_ns
            WHERE pm_ns.post_id = p.ID
              AND pm_ns.meta_key = %s
        )";
        $base_eq = "EXISTS (
            SELECT 1 FROM {$pm} pm_ns
            WHERE pm_ns.post_id = p.ID
              AND pm_ns.meta_key = %s
              AND pm_ns.meta_value = %s
        )";
        $base_like = "EXISTS (
            SELECT 1 FROM {$pm} pm_ns
            WHERE pm_ns.post_id = p.ID
              AND pm_ns.meta_key = %s
              AND pm_ns.meta_value LIKE %s
        )";
        $base_non_empty = "EXISTS (
            SELECT 1 FROM {$pm} pm_ns
            WHERE pm_ns.post_id = p.ID
              AND pm_ns.meta_key = %s
              AND pm_ns.meta_value <> ''
        )";

        return match ($operator) {
            'exists' => ['sql' => $base, 'params' => [$key]],
            'not_exists' => ['sql' => 'NOT ' . $base, 'params' => [$key]],
            'is_empty' => ['sql' => $base_eq, 'params' => [$key, '']],
            'is_not_empty' => ['sql' => $base_non_empty, 'params' => [$key]],
            'is' => ['sql' => $base_eq, 'params' => [$key, $value]],
            'is_not' => ['sql' => 'NOT ' . $base_eq, 'params' => [$key, $value]],
            'contains' => [
                'sql'    => $base_like,
                'params' => [$key, '%' . $wpdb->esc_like($value) . '%'],
            ],
            'not_contains' => [
                'sql'    => 'NOT ' . $base_like,
                'params' => [$key, '%' . $wpdb->esc_like($value) . '%'],
            ],
            'gt', 'gte', 'lt', 'lte' => $this->build_post_meta_numeric_sql($key, $operator, $value),
            default => ['sql' => '', 'params' => []],
        };
    }

    /**
     * @param array{type?:string,language?:string,operator?:string} $rule
     * @return array{sql:string,params:list<mixed>}
     */
    private function build_language_rule_sql(array $rule): array {
        global $wpdb;

        if (!\NobleSchema\Integrations\Plugin_Integrations::is_wpml_active()) {
            return ['sql' => '', 'params' => []];
        }

        $language = \NobleSchema\Targeting\Language_Rule::sanitize_language((string) ($rule['language'] ?? ''));
        $operator = \NobleSchema\Targeting\Language_Rule::sanitize_operator((string) ($rule['operator'] ?? 'is'));
        if ($language === '') {
            return ['sql' => '', 'params' => []];
        }

        $table = $wpdb->prefix . 'icl_translations';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return ['sql' => '', 'params' => []];
        }

        $sql = "EXISTS (
            SELECT 1 FROM {$table} icl_ns
            WHERE icl_ns.element_id = p.ID
              AND icl_ns.element_type = CONCAT('post_', p.post_type)
              AND icl_ns.language_code = %s
        )";

        if ($operator === 'is_not') {
            return ['sql' => 'NOT ' . $sql, 'params' => [$language]];
        }

        return ['sql' => $sql, 'params' => [$language]];
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    private function build_post_meta_numeric_sql(string $key, string $operator, string $value): array {
        global $wpdb;

        if (!is_numeric($value)) {
            // Fail closed: never matches.
            return ['sql' => '0', 'params' => []];
        }

        $cmp = match ($operator) {
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            default => '',
        };
        if ($cmp === '') {
            return ['sql' => '', 'params' => []];
        }

        $sql = "EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} pm_ns
            WHERE pm_ns.post_id = p.ID
              AND pm_ns.meta_key = %s
              AND pm_ns.meta_value REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
              AND CAST(pm_ns.meta_value AS DECIMAL(20,6)) {$cmp} %f
        )";

        return [
            'sql'    => $sql,
            'params' => [$key, (float) $value],
        ];
    }

    /**
     * @param string[] $post_types
     * @param string[] $page_templates
     * @param string[] $blocks
     * @return array{join:string,where:string,params:array<int,mixed>}|null
     */
    private function build_scope_or_query(array $post_types, array $page_templates, array $blocks): ?array {
        global $wpdb;

        $clauses = [];
        $params = [];
        $join = '';

        if ($post_types !== []) {
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $clauses[] = "p.post_type IN ($placeholders)";
            $params = array_merge($params, $post_types);
        }

        if ($page_templates !== []) {
            $join = "LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID AND pm.meta_key = '_wp_page_template'";
            $tpl_placeholders = implode(',', array_fill(0, count($page_templates), '%s'));
            $clauses[] = "(p.post_type = 'page' AND pm.meta_value IN ($tpl_placeholders))";
            $params = array_merge($params, $page_templates);
        }

        if ($blocks !== []) {
            $like = \NobleSchema\Cache\Block_Usage_Cache::content_like_clause('p.post_content', $blocks);
            if ($like !== null) {
                $clauses[] = $like['sql'];
                $params = array_merge($params, $like['params']);
            }
        }

        if ($clauses === []) {
            return null;
        }

        return [
            'join'   => $join,
            'where'  => implode(' OR ', $clauses),
            'params' => $params,
        ];
    }

    /**
     * Ensure a single delayed fallback event exists. Admin AJAX drives immediate
     * progress; cron only resumes work if the browser stops sending batches.
     */
    private function schedule_cron(): void {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        // Delay so we never write a due-now event that wp-cron.php then fails to
        // unschedule after this callback (or a concurrent AJAX batch) mutates cron.
        $delay = $this->doing_regen_cron() ? 30 : 60;
        wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
    }

    private function clear_schedule(): void {
        // wp-cron.php unschedules the current event after the callback returns.
        // Clearing the same hook here removes it first; the later unschedule then
        // fails with could_not_set and spams debug.log.
        if ($this->doing_regen_cron()) {
            return;
        }

        if (false === wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
