<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\Conflict\Detector;
use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Manual_Value_Repository;
use NobleSchema\Db\Snapshot_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Generator\Regen_Queue;
use NobleSchema\Targeting\Matcher;
use NobleSchema\Tools\Log;

/**
 * Read-only debug report for a single post's schema state.
 */
class Schema_Inspector {
    private Config_Repository $configs;
    private Target_Repository $targets;
    private Snapshot_Repository $snapshots;
    private Manual_Value_Repository $manual_values;
    private Matcher $matcher;
    private Regen_Queue $regen;

    public function __construct(
        ?Config_Repository $configs = null,
        ?Target_Repository $targets = null,
        ?Snapshot_Repository $snapshots = null,
        ?Manual_Value_Repository $manual_values = null,
        ?Matcher $matcher = null,
        ?Regen_Queue $regen = null
    ) {
        $this->configs       = $configs ?? new Config_Repository();
        $this->targets       = $targets ?? new Target_Repository();
        $this->snapshots     = $snapshots ?? new Snapshot_Repository();
        $this->manual_values = $manual_values ?? new Manual_Value_Repository();
        $this->matcher       = $matcher ?? new Matcher();
        $this->regen         = $regen ?? new Regen_Queue();
    }

    /**
     * @return array<string, mixed>|null Null when the post does not exist.
     */
    public function inspect(int $post_id): ?array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return null;
        }

        $context  = Matcher::context_from_post($post_id, $post);
        $snapshot = $this->snapshots->get($post_id);
        $configs  = $this->build_config_rows($context);
        $matched_ids = array_values(array_map(
            static fn(array $row): int => (int) $row['id'],
            array_filter($configs, static fn(array $row): bool => !empty($row['matched']))
        ));

        return [
            'post'           => $this->build_post_section($post),
            'context'        => $context,
            'configs'        => $configs,
            'matched_config_ids' => $matched_ids,
            'generation'     => \NobleSchema\plugin()->generator()->diagnose_for_post($post_id),
            'snapshot'       => $this->build_snapshot_section($snapshot),
            'frontend'       => $this->build_frontend_section($snapshot),
            'manual_values'  => $this->build_manual_values($post_id),
            'regen'          => $this->build_regen_section($post),
            'conflicts'      => Detector::detect(),
            'logs'           => $this->build_logs_section($post_id),
        ];
    }

    /**
     * Compact snapshot payload for before/after comparison.
     *
     * @return array{present:bool,is_complete:bool,generated_at:?string,content_hash:?string,config_ids:array<int,int>,applied_configs:list<array{id:int,name:string,edit_url:string}>,graph_json:?string}
     */
    public function snapshot_payload(int $post_id): array {
        return $this->build_snapshot_section($this->snapshots->get($post_id));
    }

    /**
     * Combined Page Debugger payload (sitewide + page/archive/specialty scripts).
     *
     * @param array{
     *   type:string,
     *   label:string,
     *   url:string,
     *   post_id?:int,
     *   archive_key?:string,
     *   specialty_key?:string
     * } $debug_context
     * @return array<string, mixed>
     */
    public function viewer_for_context(array $debug_context, string $source = 'stored'): array {
        $source = in_array($source, ['stored', 'live'], true) ? $source : 'stored';
        $type = (string) ($debug_context['type'] ?? '');
        $force_live = $source === 'live';

        $page_script = null;
        $match_context = [
            'post_id'       => 0,
            'post_type'     => '',
            'page_template' => '',
            'block_types'   => [],
            'taxonomies'    => [],
        ];
        $post_section = null;
        $config_ids = [];
        $present = false;
        $is_complete = false;
        $content_hash = null;
        $generated_at = null;
        $page_graph = null;

        if ($type === 'singular') {
            $post_id = (int) ($debug_context['post_id'] ?? 0);
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                throw new \InvalidArgumentException(__('Post not found.', 'noble-schema'));
            }
            $post_section = $this->build_post_section($post);
            $match_context = Matcher::context_from_post($post_id, $post);

            if ($force_live) {
                $preview = \NobleSchema\plugin()->generator()->preview_for_post($post_id);
                $present = !empty($preview['present']);
                $is_complete = !empty($preview['is_complete']);
                $page_graph = is_string($preview['graph_json'] ?? null) ? (string) $preview['graph_json'] : null;
                $config_ids = array_values(array_map('intval', (array) ($preview['config_ids'] ?? [])));
                $content_hash = $preview['content_hash'] ?? null;
            } else {
                $snap = $this->build_snapshot_section($this->snapshots->get($post_id));
                $present = !empty($snap['present']);
                $is_complete = !empty($snap['is_complete']);
                $page_graph = is_string($snap['graph_json'] ?? null) ? (string) $snap['graph_json'] : null;
                $config_ids = array_values(array_map('intval', (array) ($snap['config_ids'] ?? [])));
                $content_hash = $snap['content_hash'] ?? null;
                $generated_at = $snap['generated_at'] ?? null;
            }

            if (is_string($page_graph) && $page_graph !== '') {
                $page_script = [
                    'tag'        => 'page',
                    'label'      => __('Page', 'noble-schema'),
                    'graph_json' => $page_graph,
                    'config_ids' => $config_ids,
                ];
            }
        } elseif ($type === 'archive') {
            $identity = (string) ($debug_context['archive_key'] ?? '');
            $match_context = \NobleSchema\Targeting\Archive_Context::context_from_identity($identity);
            $json = \NobleSchema\plugin()->generator()->graph_for_archive($identity, $force_live);
            if (is_string($json) && $json !== '') {
                $present = true;
                $is_complete = true;
                $page_graph = $json;
                $page_script = [
                    'tag'        => 'archive',
                    'label'      => __('Archive', 'noble-schema'),
                    'graph_json' => $json,
                    'config_ids' => [],
                ];
            }
        } elseif ($type === 'specialty') {
            $key = (string) ($debug_context['specialty_key'] ?? '');
            $match_context = \NobleSchema\Targeting\Specialty_Context::context_from_key($key);
            $json = \NobleSchema\plugin()->generator()->graph_for_specialty($key, $force_live);
            if (is_string($json) && $json !== '') {
                $present = true;
                $is_complete = true;
                $page_graph = $json;
                $page_script = [
                    'tag'        => 'specialty',
                    'label'      => __('Specialty', 'noble-schema'),
                    'graph_json' => $json,
                    'config_ids' => [],
                ];
            }
        }

        $sitewide_scripts = $this->build_sitewide_scripts($match_context, $force_live);
        $scripts = $sitewide_scripts;
        if ($page_script !== null) {
            $scripts[] = $page_script;
        }

        $all_config_ids = $config_ids;
        foreach ($sitewide_scripts as $script) {
            $cid = (int) ($script['config_id'] ?? 0);
            if ($cid > 0) {
                $all_config_ids[] = $cid;
            }
        }
        $all_config_ids = array_values(array_unique(array_filter(array_map('intval', $all_config_ids))));

        $has_any = $scripts !== [];
        $primary_graph = $page_graph;
        if (($primary_graph === null || $primary_graph === '') && $sitewide_scripts !== []) {
            $primary_graph = (string) ($sitewide_scripts[0]['graph_json'] ?? '');
        }

        return [
            'context'         => $debug_context,
            'post'            => $post_section,
            'source'          => $source,
            'present'         => $has_any,
            'is_complete'     => $has_any && ($page_script === null || $is_complete || $sitewide_scripts !== []),
            'graph_json'      => $primary_graph !== '' ? $primary_graph : null,
            'scripts'         => $scripts,
            'config_ids'      => $all_config_ids,
            'applied_configs' => $this->resolve_applied_configs($all_config_ids),
            'content_hash'    => $content_hash,
            'generated_at'    => $generated_at,
            'validation'      => \NobleSchema\Schema\Schema_Validator::validate(
                is_string($primary_graph) && $primary_graph !== '' ? $primary_graph : null
            ),
        ];
    }

    /**
     * @param array{post_id:int,post_type:string,page_template:string,block_types:string[],taxonomies:array<string,string[]>} $match_context
     * @return list<array{tag:string,label:string,graph_json:string,config_id:int,config_name:string,placement:string}>
     */
    private function build_sitewide_scripts(array $match_context, bool $force_refresh): array {
        $scripts = [];
        $configs = $this->configs->all([
            'status'  => 'active',
            'orderby' => 'priority',
            'order'   => 'ASC',
        ]);

        foreach ($configs as $config) {
            if ((string) ($config->placement ?? '') !== 'sitewide') {
                continue;
            }
            $config_id = (int) $config->id;
            $targets = $this->targets->for_config($config_id);
            if (!$this->matcher->matches($config, $targets, $match_context)) {
                continue;
            }

            $json = null;
            if (!$force_refresh) {
                $hit = (new \NobleSchema\Cache\Sitewide_Cache())->get($config_id);
                $json = is_array($hit) ? ($hit['graph_json'] ?? null) : null;
            }
            if (!is_string($json) || $json === '') {
                $json = \NobleSchema\plugin()->generator()->rebuild_sitewide_config($config_id);
            }
            if (!is_string($json) || $json === '') {
                continue;
            }

            $name = trim((string) ($config->name ?? ''));
            if ($name === '') {
                $name = sprintf('#%d', $config_id);
            }

            $scripts[] = [
                'tag'         => 'sitewide',
                'label'       => __('Sitewide', 'noble-schema'),
                'graph_json'  => $json,
                'config_id'   => $config_id,
                'config_name' => $name,
                'placement'   => 'sitewide',
            ];
        }

        return $scripts;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_post_section(\WP_Post $post): array {
        $type = get_post_type_object($post->post_type);
        $type_label = $type ? (string) ($type->labels->singular_name ?? $post->post_type) : $post->post_type;
        $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title === '') {
            $title = sprintf(__('(no title) #%d', 'noble-schema'), (int) $post->ID);
        }

        $edit_link = get_edit_post_link($post->ID, 'raw');

        $payload = [
            'id'            => (int) $post->ID,
            'title'         => $title,
            'post_type'     => $post->post_type,
            'post_type_label' => $type_label,
            'status'        => $post->post_status,
            'permalink'     => get_permalink($post) ?: '',
            'edit_url'      => is_string($edit_link) ? $edit_link : '',
            'page_template' => get_page_template_slug($post->ID) ?: '',
            'content_bytes' => strlen((string) $post->post_content),
        ];

        if (\NobleSchema\Integrations\Plugin_Integrations::is_wpml_active()) {
            $code = \NobleSchema\Integrations\Plugin_Integrations::post_language_code((int) $post->ID);
            $languages = \NobleSchema\Integrations\Plugin_Integrations::wpml_active_languages();
            $payload['language_code'] = $code;
            $payload['language_label'] = $code !== ''
                ? ($languages[$code] ?? $code)
                : __('Unknown', 'noble-schema');
            $payload['other_languages'] = $this->build_other_languages((int) $post->ID, $languages);
        }

        return $payload;
    }

    /**
     * @param array<string, string> $languages
     * @return list<array{id:int,language_code:string,language_label:string,title:string,post_type:string,post_type_label:string}>
     */
    private function build_other_languages(int $post_id, array $languages): array {
        $siblings = \NobleSchema\Integrations\Plugin_Integrations::post_translations($post_id);
        if ($siblings === []) {
            return [];
        }

        $out = [];
        foreach ($siblings as $sibling) {
            $id = (int) ($sibling['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $sibling_post = get_post($id);
            if (!$sibling_post instanceof \WP_Post) {
                continue;
            }

            $lang = (string) ($sibling['language_code'] ?? '');
            if ($lang === '') {
                $lang = \NobleSchema\Integrations\Plugin_Integrations::post_language_code($id);
            }

            $type = get_post_type_object($sibling_post->post_type);
            $type_label = $type
                ? (string) ($type->labels->singular_name ?? $sibling_post->post_type)
                : $sibling_post->post_type;
            $title = html_entity_decode(get_the_title($sibling_post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '') {
                $title = sprintf(__('(no title) #%d', 'noble-schema'), $id);
            }

            $out[] = [
                'id'              => $id,
                'language_code'   => $lang,
                'language_label'  => $lang !== '' ? ($languages[$lang] ?? $lang) : __('Unknown', 'noble-schema'),
                'title'           => $title,
                'post_type'       => $sibling_post->post_type,
                'post_type_label' => $type_label,
            ];
        }

        usort(
            $out,
            static fn(array $a, array $b): int => strcasecmp(
                (string) ($a['language_label'] ?? ''),
                (string) ($b['language_label'] ?? '')
            )
        );

        return $out;
    }

    /**
     * @param array{post_id:int,post_type:string,page_template:string,block_types:string[],taxonomies:array<string,string[]>} $context
     * @return array<int, array<string, mixed>>
     */
    private function build_config_rows(array $context): array {
        $rows = [];
        $configs = $this->configs->all([
            'orderby' => 'priority',
            'order'   => 'ASC',
        ]);

        foreach ($configs as $config) {
            $targets = $this->targets->for_config((int) $config->id);
            $status  = (string) ($config->status ?? '');
            $matched = $this->matcher->matches($config, $targets, $context);
            $reason  = null;

            if (!$matched) {
                if ($status !== 'active') {
                    $reason = __('Config is not active.', 'noble-schema');
                } else {
                    $reason = __('Placement or filter rules do not match this post.', 'noble-schema');
                }
            }

            $rows[] = [
                'id'             => (int) $config->id,
                'name'           => (string) ($config->name ?? ''),
                'status'         => $status,
                'placement'      => (string) ($config->placement ?? ''),
                'schema_type'    => (string) ($config->schema_type ?? ''),
                'template_slug'  => (string) ($config->template_slug ?? ''),
                'priority'       => (int) ($config->priority ?? 0),
                'matched'        => $matched,
                'reason'         => $reason,
                'edit_url'       => Menu::edit_url((int) $config->id),
                'target_count'   => count($targets),
            ];
        }

        return $rows;
    }

    /**
     * @return array{present:bool,is_complete:bool,generated_at:?string,content_hash:?string,config_ids:array<int,int>,applied_configs:list<array{id:int,name:string,edit_url:string}>,graph_json:?string}
     */
    private function build_snapshot_section(?object $snapshot): array {
        if ($snapshot === null) {
            return [
                'present'          => false,
                'is_complete'      => false,
                'generated_at'     => null,
                'content_hash'     => null,
                'config_ids'       => [],
                'applied_configs'  => [],
                'graph_json'       => null,
            ];
        }

        $config_ids = json_decode((string) ($snapshot->config_ids ?? '[]'), true);
        if (!is_array($config_ids)) {
            $config_ids = [];
        }
        $config_ids = array_values(array_map('intval', $config_ids));

        return [
            'present'         => true,
            'is_complete'     => !empty($snapshot->is_complete),
            'generated_at'    => $this->format_local_mysql_datetime((string) ($snapshot->generated_at ?? '')),
            'content_hash'    => (string) ($snapshot->content_hash ?? ''),
            'config_ids'      => $config_ids,
            'applied_configs' => $this->resolve_applied_configs($config_ids),
            'graph_json'      => (string) ($snapshot->graph_json ?? ''),
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,name:string,edit_url:string}>
     */
    public function resolve_applied_configs(array $ids): array {
        $rows = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $config = $this->configs->find($id);
            $name = $config ? trim((string) ($config->name ?? '')) : '';
            if ($name === '') {
                $name = sprintf('#%d', $id);
            }
            $rows[] = [
                'id'       => $id,
                'name'     => $name,
                'edit_url' => Menu::edit_url($id),
            ];
        }

        return $rows;
    }

    /**
     * Snapshot times are stored as site-local MySQL datetimes via current_time('mysql').
     * Display matches system logs / scan timestamps, e.g. "January 1, 2026 1:00pm PST".
     */
    private function format_local_mysql_datetime(string $mysql_local): ?string {
        $mysql_local = trim($mysql_local);
        if ($mysql_local === '' || $mysql_local === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $dt = new \DateTimeImmutable($mysql_local, $tz);
        } catch (\Exception $e) {
            return $mysql_local;
        }

        $format = 'F j, Y g:ia T';
        $timestamp = $dt->getTimestamp();
        if (function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp);
        }
        if (function_exists('date_i18n')) {
            return (string) date_i18n($format, $timestamp);
        }

        return $dt->format($format);
    }

    /**
     * @return array{would_print:bool,reason:string}
     */
    private function build_frontend_section(?object $snapshot): array {
        if ($snapshot === null) {
            return [
                'would_print' => false,
                'reason'      => __('No snapshot stored for this post.', 'noble-schema'),
            ];
        }

        $graph = trim((string) ($snapshot->graph_json ?? ''));
        if (empty($snapshot->is_complete) || $graph === '') {
            return [
                'would_print' => false,
                'reason'      => __('Snapshot exists but is incomplete or empty, so frontend output is skipped.', 'noble-schema'),
            ];
        }

        return [
            'would_print' => true,
            'reason'      => __('Frontend would print this snapshot’s JSON-LD on singular views.', 'noble-schema'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function build_manual_values(int $post_id): array {
        $rows = $this->manual_values->for_post($post_id);
        $out  = [];

        foreach ($rows as $row) {
            $value = (string) ($row->value ?? '');
            if (strlen($value) > 200) {
                $value = substr($value, 0, 200) . '…';
            }
            $out[] = [
                'config_id'       => (int) ($row->config_id ?? 0),
                'field_path'      => (string) ($row->field_path ?? ''),
                'block_client_id' => $row->block_client_id ?? null,
                'value'           => $value,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_regen_section(\WP_Post $post): array {
        $status = $this->regen->get_status();
        $job    = $this->regen->get_job_debug();
        $pending = false;
        $note    = __('No active regeneration job.', 'noble-schema');

        if ($job !== null) {
            $in_types = in_array($post->post_type, $job['post_types'], true);
            $is_publish = $post->post_status === 'publish';
            if ($in_types && $is_publish && (int) $post->ID > (int) $job['last_id']) {
                $pending = true;
                $note = __('This post is still pending in the regeneration cursor queue.', 'noble-schema');
            } elseif ($in_types && $is_publish) {
                $note = __('This post’s ID is at or behind the regen cursor (already processed or skipped in the current job).', 'noble-schema');
            } else {
                $note = __('A regeneration job is running, but this post is outside its post-type scope or not published.', 'noble-schema');
            }
        } elseif (!empty($status['cancelled'])) {
            $note = __('A regeneration was cancelled recently; no active job.', 'noble-schema');
        }

        return [
            'status'  => $status,
            'job'     => $job,
            'pending' => $pending,
            'note'    => $note,
        ];
    }

    /**
     * @return array{enabled:bool,rows:list<array<string,mixed>>,logs_url:string}
     */
    private function build_logs_section(int $post_id): array {
        $logs_url = Tools_Page::page_url(['tool' => 'logs']);

        if (!Log::enabled()) {
            return [
                'enabled'  => false,
                'rows'     => [],
                'logs_url' => $logs_url,
            ];
        }

        return [
            'enabled'  => true,
            'rows'     => Log::for_post($post_id, 20),
            'logs_url' => $logs_url,
        ];
    }
}
