<?php

declare(strict_types=1);

namespace NobleSchema\Generator;

use NobleSchema\Cache\Archive_Cache;
use NobleSchema\Cache\Sitewide_Cache;
use NobleSchema\Cache\Specialty_Cache;
use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Manual_Value_Repository;
use NobleSchema\Db\Snapshot_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Mapping\Resolver_Registry;
use NobleSchema\Targeting\Archive_Context;
use NobleSchema\Targeting\Matcher;
use NobleSchema\Targeting\Sitewide_Sources;
use NobleSchema\Targeting\Specialty_Context;
use NobleSchema\Templates\Template_Loader;

class Generator {
    public function __construct(
        private Config_Repository $configs,
        private Target_Repository $targets,
        private Field_Repository $fields,
        private Manual_Value_Repository $manuals,
        private Snapshot_Repository $snapshots,
        private Template_Loader $templates,
        private Matcher $matcher,
        private Resolver_Registry $resolvers,
        private Graph_Builder $graph,
    ) {}

    public function generate_for_post(int $post_id): void {
        $before = $this->snapshots->get($post_id);
        $before_ids = \NobleSchema\Tools\Log::config_ids_from_snapshot_row($before);
        $before_graph_json = $before !== null ? (string) ($before->graph_json ?? '') : '';
        $before_types = \NobleSchema\Tools\Log::types_from_graph_json(
            $before_graph_json !== '' ? $before_graph_json : null
        );
        $before_hash = $before !== null ? (string) ($before->content_hash ?? '') : '';

        $built = $this->build_for_post($post_id);
        if ($built === null) {
            $this->snapshots->delete($post_id);
            if ($before !== null) {
                \NobleSchema\Tools\Log::schema_change(
                    $post_id,
                    'cleared',
                    $before_ids,
                    $before_types,
                    [],
                    [],
                    $before_graph_json !== '' ? $before_graph_json : null,
                    null
                );
            }

            return;
        }

        $this->snapshots->upsert($post_id, $built['graph_json'], $built['config_ids'], true);

        $after_ids = array_values(array_map('intval', $built['config_ids']));
        $after_types = \NobleSchema\Tools\Log::types_from_graph_json($built['graph_json']);
        $after_hash = hash('sha256', $built['graph_json']);

        if ($before === null) {
            \NobleSchema\Tools\Log::schema_change(
                $post_id,
                'created',
                [],
                [],
                $after_ids,
                $after_types,
                null,
                $built['graph_json']
            );

            return;
        }

        if ($before_hash !== $after_hash || $before_ids !== $after_ids) {
            \NobleSchema\Tools\Log::schema_change(
                $post_id,
                'updated',
                $before_ids,
                $before_types,
                $after_ids,
                $after_types,
                $before_graph_json !== '' ? $before_graph_json : null,
                $built['graph_json']
            );
        }
    }

    /**
     * Build JSON-LD for a post without writing the snapshot (Schema Viewer live preview).
     *
     * @return array{
     *   present: bool,
     *   is_complete: bool,
     *   graph_json: ?string,
     *   config_ids: list<int>,
     *   content_hash: ?string
     * }
     */
    public function preview_for_post(int $post_id): array {
        $built = $this->build_for_post($post_id);
        if ($built === null) {
            return [
                'present'      => false,
                'is_complete'  => false,
                'graph_json'   => null,
                'config_ids'   => [],
                'content_hash' => null,
            ];
        }

        return [
            'present'      => true,
            'is_complete'  => true,
            'graph_json'   => $built['graph_json'],
            'config_ids'   => $built['config_ids'],
            'content_hash' => hash('sha256', $built['graph_json']),
        ];
    }

    /**
     * Explain which configs would generate for a post and why others are skipped.
     *
     * @return array{
     *   would_generate: bool,
     *   included_config_ids: list<int>,
     *   summary: string,
     *   configs: list<array<string, mixed>>
     * }
     */
    public function diagnose_for_post(int $post_id): array {
        $post = get_post($post_id);
        $content = $post instanceof \WP_Post ? (string) $post->post_content : '';
        $context = Matcher::context_from_post($post_id, $post, null);

        $configs = $this->configs->all([
            'status'  => 'active',
            'orderby' => 'priority',
            'order'   => 'ASC',
        ]);

        $needs_blocks = false;
        $matched = [];
        foreach ($configs as $config) {
            if ((string) ($config->placement ?? '') === 'sitewide') {
                continue;
            }
            $config_id = (int) $config->id;
            $target_rows = $this->targets->for_config($config_id);
            if (!$this->matcher->matches($config, $target_rows, $context)) {
                continue;
            }
            $field_maps = $this->fields->for_config($config_id);
            if ($this->field_maps_need_blocks($field_maps)) {
                $needs_blocks = true;
            }
            $matched[] = [$config, $field_maps];
        }

        $blocks = [];
        if ($matched !== []) {
            $needs_full_parse = $needs_blocks;
            if (!$needs_full_parse) {
                foreach ($matched as [$config]) {
                    if ((string) ($config->placement ?? '') === 'block') {
                        $needs_full_parse = true;
                        break;
                    }
                }
            }
            if ($needs_full_parse && $post instanceof \WP_Post && strlen($content) <= 800000) {
                $blocks = parse_blocks($content);
            }
        }

        $rows = [];
        $included_ids = [];

        foreach ($matched as [$config, $field_maps]) {
            $config_id = (int) $config->id;

            $row = [
                'id'               => $config_id,
                'name'             => (string) ($config->name ?? ''),
                'status'           => 'active',
                'matched'          => true,
                'included'         => false,
                'outcome'          => 'skipped',
                'outcome_label'    => '',
                'reason'           => '',
                'missing_required' => [],
                'fields'           => [],
                'edit_url'         => add_query_arg(
                    [
                        'page'      => 'schema-config',
                        'action'    => 'edit',
                        'config_id' => $config_id,
                    ],
                    admin_url('options-general.php')
                ),
            ];

            $template = $this->templates->get((string) $config->template_slug);
            if ($template === null) {
                $row['outcome'] = 'missing_template';
                $row['outcome_label'] = __('Missing template', 'noble-schema');
                $row['reason'] = sprintf(
                    /* translators: %s: template slug */
                    __('Template “%s” could not be loaded.', 'noble-schema'),
                    (string) ($config->template_slug ?? '')
                );
                $rows[] = $row;
                continue;
            }

            $base_resolve_context = [
                ...$context,
                'config_id'     => (string) $config_id,
                'post'          => $post,
                'blocks'        => $blocks,
                'manual_values' => $this->build_manual_values_map($post_id, $config_id),
            ];

            $missing = [];
            $field_rows = [];
            foreach ($field_maps as $field_map) {
                $field_path = (string) $field_map->field_path;
                $field_def = $this->find_template_field($template, $field_path);
                $source_type = (string) $field_map->source_type;
                $source_config = $this->decode_source_config((string) $field_map->source_config);
                $value = $this->resolvers->resolve(
                    $source_type,
                    $source_config,
                    $base_resolve_context
                );
                $required = $this->is_required($field_def, $field_map);
                $missing_value = $this->is_field_missing($value, $field_def ?? []);
                $label = (string) ($field_def['label'] ?? $field_path);

                $field_rows[] = [
                    'path'          => $field_path,
                    'label'         => $label,
                    'source_type'   => $source_type,
                    'required'      => $required,
                    'resolved'      => !$missing_value,
                    'value_preview' => $missing_value ? null : $this->preview_resolved_value($value),
                ];

                if ($required && $missing_value) {
                    $missing[] = [
                        'path'        => $field_path,
                        'label'       => $label,
                        'source_type' => $source_type,
                    ];
                }
            }

            $row['fields'] = $field_rows;
            $row['missing_required'] = $missing;
            $strict = $this->uses_strict_validation($config);

            if ($missing !== [] && $strict) {
                $names = array_map(
                    static fn(array $item): string => (string) ($item['label'] ?: $item['path']),
                    $missing
                );
                $row['outcome'] = 'missing_required';
                $row['outcome_label'] = __('Skipped — missing required fields', 'noble-schema');
                $row['reason'] = sprintf(
                    /* translators: %s: comma-separated field labels */
                    __('Required fields resolved empty: %s.', 'noble-schema'),
                    implode(', ', $names)
                );
                $rows[] = $row;
                continue;
            }

            $row['included'] = true;
            $row['outcome'] = 'included';
            if ($missing !== [] && !$strict) {
                $names = array_map(
                    static fn(array $item): string => (string) ($item['label'] ?: $item['path']),
                    $missing
                );
                $row['outcome_label'] = __('Would generate (non-strict)', 'noble-schema');
                $row['reason'] = sprintf(
                    /* translators: %s: comma-separated field labels */
                    __('Strict validation is off; outputting available fields. Missing required fields omitted: %s.', 'noble-schema'),
                    implode(', ', $names)
                );
            } else {
                $row['outcome_label'] = __('Would generate', 'noble-schema');
                $row['reason'] = __('All required fields resolved; this config would be included in the snapshot.', 'noble-schema');
            }
            $included_ids[] = $config_id;
            $rows[] = $row;
        }

        $would_generate = $included_ids !== [];
        if ($would_generate) {
            $summary = sprintf(
                /* translators: %d: number of configs */
                _n(
                    '%d matched config would generate schema for this post.',
                    '%d matched configs would generate schema for this post.',
                    count($included_ids),
                    'noble-schema'
                ),
                count($included_ids)
            );
        } elseif ($rows !== []) {
            $summary = __('Configs match this post, but none would generate because required fields are missing or templates failed to load.', 'noble-schema');
        } else {
            $summary = __('No active configs match this post, so no schema would be generated.', 'noble-schema');
        }

        return [
            'would_generate'      => $would_generate,
            'included_config_ids' => $included_ids,
            'summary'             => $summary,
            'configs'             => $rows,
        ];
    }

    private function preview_resolved_value(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $trim = trim($value);
            if (strlen($trim) > 120) {
                return substr($trim, 0, 117) . '…';
            }

            return $trim;
        }
        if (is_array($value)) {
            $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || $json === '') {
                return '[array]';
            }
            if (strlen($json) > 120) {
                return substr($json, 0, 117) . '…';
            }

            return $json;
        }

        return '';
    }

    /**
     * @return array{graph_json: string, config_ids: list<int>}|null
     */
    public function build_for_post(int $post_id): ?array {
        $post = get_post($post_id);
        $content = $post instanceof \WP_Post ? (string) $post->post_content : '';
        // Matcher only needs block *names*; avoid parse_blocks until a mapping needs block data.
        $context = Matcher::context_from_post($post_id, $post, null);

        $configs = $this->configs->all([
            'status' => 'active',
            'orderby' => 'priority',
            'order' => 'ASC',
        ]);

        $needs_blocks = false;
        $matched = [];
        foreach ($configs as $config) {
            if ((string) ($config->placement ?? '') === 'sitewide') {
                continue;
            }
            $config_id = (int) $config->id;
            $target_rows = $this->targets->for_config($config_id);
            if (!$this->matcher->matches($config, $target_rows, $context)) {
                continue;
            }
            $field_maps = $this->fields->for_config($config_id);
            if ($this->field_maps_need_blocks($field_maps)) {
                $needs_blocks = true;
            }
            $matched[] = [$config, $field_maps];
        }

        $blocks = [];
        if ($matched !== []) {
            $needs_full_parse = $needs_blocks;
            if (!$needs_full_parse) {
                foreach ($matched as [$config]) {
                    if ((string) ($config->placement ?? '') === 'block') {
                        $needs_full_parse = true;
                        break;
                    }
                }
            }
            if ($needs_full_parse && strlen($content) <= 800000) {
                $blocks = parse_blocks($content);
            }
        }

        $nodes = [];
        $config_ids = [];

        foreach ($matched as [$config, $field_maps]) {
            $config_id = (int) $config->id;

            $template = $this->templates->get((string) $config->template_slug);
            if ($template === null) {
                continue;
            }

            $base_resolve_context = [
                ...$context,
                'config_id' => (string) $config_id,
                'post' => $post,
                'blocks' => $blocks,
                'manual_values' => $this->build_manual_values_map($post_id, $config_id),
            ];

            $node = $template['node'] ?? ['@type' => (string) $config->schema_type];
            if (!is_array($node)) {
                $node = ['@type' => (string) $config->schema_type];
            }

            $failed = false;
            $strict = $this->uses_strict_validation($config);

            foreach ($field_maps as $field_map) {
                $field_path = (string) $field_map->field_path;
                $field_def = $this->find_template_field($template, $field_path);
                $source_type = (string) $field_map->source_type;
                $source_config = $this->decode_source_config((string) $field_map->source_config);
                $value = $this->resolvers->resolve(
                    $source_type,
                    $source_config,
                    $base_resolve_context
                );

                if (
                    $strict
                    && $this->is_required($field_def, $field_map)
                    && $this->is_field_missing($value, $field_def ?? [])
                ) {
                    $failed = true;
                    break;
                }

                if (!$this->is_field_missing($value, $field_def ?? [])) {
                    $transformed = $this->transform_value($value, $field_def ?? [], $field_path);
                    $node = $this->set_path($node, $field_path, $transformed);
                }
            }

            if ($failed) {
                continue;
            }

            $permalink = get_permalink($post_id);
            if (is_string($permalink) && $permalink !== '') {
                $node['@id'] = $permalink . '#' . $config->schema_type;
            }

            $nodes[] = $node;
            $config_ids[] = $config_id;
        }

        unset($blocks, $matched, $content);

        if ($nodes === []) {
            return null;
        }

        $document = $this->graph->build($nodes);
        $json = wp_json_encode(
            $document,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return null;
        }

        return [
            'graph_json' => $json,
            'config_ids' => $config_ids,
        ];
    }

    /**
     * @param array<int, object> $field_maps
     */
    private function field_maps_need_blocks(array $field_maps): bool {
        foreach ($field_maps as $field_map) {
            $type = (string) ($field_map->source_type ?? '');
            if (in_array($type, ['block_attr', 'block_content', 'manual_block'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build (and cache) JSON-LD for a native archive view.
     */
    public function graph_for_archive(string $archive_key, bool $force_refresh = false): ?string {
        $identity = trim($archive_key);
        if ($identity === '') {
            return null;
        }

        $cache = new Archive_Cache();
        if (!$force_refresh) {
            $hit = $cache->get($identity);
            if ($hit !== null) {
                return $hit['graph_json'];
            }
        }

        $context = Archive_Context::context_from_identity($identity);
        $configs = $this->configs->all([
            'status'  => 'active',
            'orderby' => 'priority',
            'order'   => 'ASC',
        ]);

        $nodes = [];
        $config_ids = [];

        foreach ($configs as $config) {
            if ((string) ($config->placement ?? '') === 'sitewide') {
                continue;
            }
            $config_id = (int) $config->id;
            $target_rows = $this->targets->for_config($config_id);

            if (!$this->matcher->matches($config, $target_rows, $context)) {
                continue;
            }

            $template = $this->templates->get((string) $config->template_slug);
            if ($template === null) {
                continue;
            }

            $field_maps = $this->fields->for_config($config_id);
            $base_resolve_context = array_merge($context, [
                'config_id'     => (string) $config_id,
                'post'          => null,
                'blocks'        => [],
                'manual_values' => [],
            ]);

            $node = $template['node'] ?? ['@type' => (string) $config->schema_type];
            if (!is_array($node)) {
                $node = ['@type' => (string) $config->schema_type];
            }

            foreach ($field_maps as $field_map) {
                $field_path = (string) $field_map->field_path;
                $field_def = $this->find_template_field($template, $field_path);
                $source_type = (string) $field_map->source_type;
                $source_config = $this->decode_source_config((string) $field_map->source_config);
                $value = $this->resolvers->resolve(
                    $source_type,
                    $source_config,
                    array_merge($base_resolve_context, ['block_client_id' => ''])
                );

                // Archives have no singular post: skip required-field failure and keep resolvable values.
                if (!$this->is_field_missing($value, $field_def ?? [])) {
                    $transformed = $this->transform_value($value, $field_def ?? [], $field_path);
                    $node = $this->set_path($node, $field_path, $transformed);
                }
            }

            $archive_title = (string) ($context['archive_title'] ?? '');
            $archive_url = (string) ($context['archive_url'] ?? '');
            if ($archive_title !== '' && $this->is_field_missing($node['name'] ?? null, [])) {
                $node['name'] = $archive_title;
            }
            if ($archive_url !== '' && $this->is_field_missing($node['url'] ?? null, [])) {
                $node['url'] = $archive_url;
            }
            if ($archive_url !== '') {
                $node['@id'] = $archive_url . '#' . ($config->schema_type ?? 'schema');
            }

            $nodes[] = $node;
            $config_ids[] = $config_id;
        }

        if ($nodes === []) {
            $cache->delete($identity);

            return null;
        }

        $document = $this->graph->build($nodes);
        $json = wp_json_encode(
            $document,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return null;
        }

        $cache->set($identity, $json, $config_ids);

        return $json;
    }

    public function graph_for_specialty(string $specialty_key, bool $force_refresh = false): ?string {
        $cache = new Specialty_Cache();
        if (!$force_refresh) {
            $hit = $cache->get($specialty_key);
            if ($hit !== null) {
                return $hit['graph_json'];
            }
        }

        $context = Specialty_Context::context_from_key($specialty_key);
        $configs = $this->configs->all([
            'status'  => 'active',
            'orderby' => 'priority',
            'order'   => 'ASC',
        ]);

        $nodes = [];
        $config_ids = [];

        foreach ($configs as $config) {
            if ((string) ($config->placement ?? '') === 'sitewide') {
                continue;
            }
            $config_id = (int) $config->id;
            $target_rows = $this->targets->for_config($config_id);

            if (!$this->matcher->matches($config, $target_rows, $context)) {
                continue;
            }

            $template = $this->templates->get((string) $config->template_slug);
            if ($template === null) {
                continue;
            }

            $field_maps = $this->fields->for_config($config_id);
            $base_resolve_context = array_merge($context, [
                'config_id'     => (string) $config_id,
                'post'          => null,
                'blocks'        => [],
                'manual_values' => [],
            ]);

            $node = $template['node'] ?? ['@type' => (string) $config->schema_type];
            if (!is_array($node)) {
                $node = ['@type' => (string) $config->schema_type];
            }

            foreach ($field_maps as $field_map) {
                $field_path = (string) $field_map->field_path;
                $field_def = $this->find_template_field($template, $field_path);
                $source_type = (string) $field_map->source_type;
                $source_config = $this->decode_source_config((string) $field_map->source_config);
                $value = $this->resolvers->resolve(
                    $source_type,
                    $source_config,
                    array_merge($base_resolve_context, ['block_client_id' => ''])
                );

                if (!$this->is_field_missing($value, $field_def ?? [])) {
                    $transformed = $this->transform_value($value, $field_def ?? [], $field_path);
                    $node = $this->set_path($node, $field_path, $transformed);
                }
            }

            $specialty_title = (string) ($context['specialty_title'] ?? '');
            $specialty_url = (string) ($context['specialty_url'] ?? '');
            if ($specialty_title !== '' && $this->is_field_missing($node['name'] ?? null, [])) {
                $node['name'] = $specialty_title;
            }
            if ($specialty_url !== '' && $this->is_field_missing($node['url'] ?? null, [])) {
                $node['url'] = $specialty_url;
            }
            if ($specialty_url !== '') {
                $node['@id'] = $specialty_url . '#' . ($config->schema_type ?? 'schema');
            }

            $nodes[] = $node;
            $config_ids[] = $config_id;
        }

        if ($nodes === []) {
            $cache->delete($specialty_key);

            return null;
        }

        $document = $this->graph->build($nodes);
        $json = wp_json_encode(
            $document,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return null;
        }

        $cache->set($specialty_key, $json, $config_ids);

        return $json;
    }

    /**
     * Rebuild shared JSON-LD for one sitewide config (no per-post snapshots).
     */
    public function rebuild_sitewide_config(int $config_id): ?string {
        $cache = new Sitewide_Cache();
        if ($config_id <= 0) {
            return null;
        }

        $config = $this->configs->find($config_id);
        if (
            !$config
            || (string) ($config->status ?? '') !== 'active'
            || (string) ($config->placement ?? '') !== 'sitewide'
        ) {
            $cache->delete($config_id);

            return null;
        }

        $template = $this->templates->get((string) $config->template_slug);
        if ($template === null) {
            $cache->delete($config_id);

            return null;
        }

        $field_maps = $this->fields->for_config($config_id);
        $blocked = Sitewide_Sources::blocked_in_maps($field_maps);
        if ($blocked !== []) {
            $cache->delete($config_id);

            return null;
        }

        $base_resolve_context = [
            'post_id'       => 0,
            'post_type'     => '',
            'page_template' => '',
            'block_types'   => [],
            'taxonomies'    => [],
            'config_id'     => (string) $config_id,
            'post'          => null,
            'blocks'        => [],
            'manual_values' => [],
        ];

        $node = $template['node'] ?? ['@type' => (string) $config->schema_type];
        if (!is_array($node)) {
            $node = ['@type' => (string) $config->schema_type];
        }

        foreach ($field_maps as $field_map) {
            $field_path = (string) $field_map->field_path;
            $field_def = $this->find_template_field($template, $field_path);
            $source_type = (string) $field_map->source_type;
            $source_config = $this->decode_source_config((string) $field_map->source_config);
            $value = $this->resolvers->resolve(
                $source_type,
                $source_config,
                array_merge($base_resolve_context, ['block_client_id' => ''])
            );

            if ($this->is_field_missing($value, $field_def ?? [])) {
                if ($this->is_required($field_def, $field_map) && !empty($config->strict_validation)) {
                    $cache->delete($config_id);

                    return null;
                }
                continue;
            }

            $transformed = $this->transform_value($value, $field_def ?? [], $field_path);
            $node = $this->set_path($node, $field_path, $transformed);
        }

        $home = home_url('/');
        if (is_string($home) && $home !== '') {
            $node['@id'] = $home . '#' . ($config->schema_type ?? 'schema');
        }

        $document = $this->graph->build([$node]);
        $json = wp_json_encode(
            $document,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            $cache->delete($config_id);

            return null;
        }

        $cache->set($config_id, $json);

        return $json;
    }

    /**
     * Active sitewide graphs that match the current front-end request filters.
     *
     * @return list<string>
     */
    public function sitewide_graphs_for_request(): array {
        $configs = $this->configs->all([
            'status'  => 'active',
            'orderby' => 'priority',
            'order'   => 'ASC',
        ]);

        $context = $this->request_context_for_sitewide();
        $cache = new Sitewide_Cache();
        $out = [];

        foreach ($configs as $config) {
            if ((string) ($config->placement ?? '') !== 'sitewide') {
                continue;
            }

            $config_id = (int) $config->id;
            $target_rows = $this->targets->for_config($config_id);
            if (!$this->matcher->matches($config, $target_rows, $context)) {
                continue;
            }

            $hit = $cache->get($config_id);
            $json = $hit['graph_json'] ?? null;
            if (!is_string($json) || $json === '') {
                $json = $this->rebuild_sitewide_config($config_id);
            }
            if (is_string($json) && $json !== '') {
                $out[] = $json;
            }
        }

        return $out;
    }

    /**
     * @return array{post_id: int, post_type: string, page_template: string, block_types: string[], taxonomies: array<string, string[]>}
     */
    private function request_context_for_sitewide(): array {
        if (is_singular()) {
            $post_id = (int) get_queried_object_id();
            if ($post_id > 0) {
                return Matcher::context_from_post($post_id);
            }
        }

        $archive_identity = Archive_Context::current_identity();
        if ($archive_identity !== null) {
            return Archive_Context::context_from_identity($archive_identity);
        }

        $specialty_key = Specialty_Context::current_key();
        if ($specialty_key !== null) {
            return Specialty_Context::context_from_key($specialty_key);
        }

        return [
            'post_id'       => 0,
            'post_type'     => '',
            'page_template' => '',
            'block_types'   => [],
            'taxonomies'    => [],
        ];
    }

    public function is_field_missing(mixed $value, array $field_def): bool {
        if ($value === null) {
            return true;
        }

        if ($value === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }

    /**
     * Default true when the column is absent (legacy rows before migration).
     */
    private function uses_strict_validation(object $config): bool {
        if (!isset($config->strict_validation)) {
            return true;
        }

        return (int) $config->strict_validation === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_manual_values_map(int $post_id, int $config_id): array {
        $map = [];

        foreach ($this->manuals->for_post($post_id, $config_id) as $row) {
            $block_client_id = (string) ($row->block_client_id ?? '');
            $key = "{$config_id}|{$row->field_path}|{$block_client_id}";
            $map[$key] = $this->decode_manual_value($row->value);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>|null
     */
    private function find_template_field(array $template, string $path): ?array {
        foreach ($template['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['path'] ?? '') === $path) {
                return $field;
            }
        }

        return null;
    }

    private function decode_manual_value(mixed $value): mixed {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_source_config(string $source_config): array {
        if ($source_config === '') {
            return [];
        }

        $decoded = json_decode($source_config, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $field_def
     */
    private function is_required(?array $field_def, object $field_map): bool {
        if ($field_map->required_override !== null) {
            return (bool) (int) $field_map->required_override;
        }

        return (bool) ($field_def['required'] ?? false);
    }

    private function transform_value(mixed $value, array $field_def, string $field_path): mixed {
        $value_type = (string) ($field_def['value_type'] ?? '');

        if (is_string($value) && $this->is_structured_value_type($value_type)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if ($value_type !== 'question_array' && $field_path !== 'mainEntity') {
            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $questions = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = (string) ($item['question'] ?? '');
            $answer = (string) ($item['answer'] ?? '');

            if ($question === '' && $answer === '') {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        return $questions;
    }

    private function is_structured_value_type(string $value_type): bool {
        if ($value_type === '') {
            return false;
        }

        if (in_array($value_type, ['question_array', 'geo', 'postal_address', 'place', 'person'], true)) {
            return true;
        }

        return str_ends_with($value_type, '_array');
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function set_path(array $node, string $path, mixed $value): array {
        if ($path === '') {
            return $node;
        }

        $keys = explode('.', $path);
        $last = array_pop($keys);
        $ref = &$node;

        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }

        $ref[$last] = $value;

        return $node;
    }
}
