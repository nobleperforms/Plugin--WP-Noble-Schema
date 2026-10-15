<?php

declare(strict_types=1);

namespace NobleSchema\Transfer;

use NobleSchema\Admin\Settings_Page;
use NobleSchema\Cache\Sitewide_Cache;
use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\SchemaOrg\Template_Factory;
use NobleSchema\Targeting\Sitewide_Sources;
use NobleSchema\Templates\Template_Loader;

/**
 * Build, preview, and apply noble-schema-export packs.
 */
class Pack {
    public const FORMAT = 'noble-schema-export';
    public const VERSION = 1;

    private ?Config_Repository $configs_repo = null;
    private ?Target_Repository $targets_repo = null;
    private ?Field_Repository $fields_repo = null;
    private ?Template_Factory $templates_factory = null;
    private string $plugin_dir;

    public function __construct(
        ?Config_Repository $configs = null,
        ?Target_Repository $targets = null,
        ?Field_Repository $fields = null,
        ?Template_Factory $templates = null
    ) {
        $this->plugin_dir = dirname(__DIR__, 2);
        $this->configs_repo = $configs;
        $this->targets_repo = $targets;
        $this->fields_repo = $fields;
        $this->templates_factory = $templates;
    }

    private function configs(): Config_Repository {
        return $this->configs_repo ??= new Config_Repository();
    }

    private function targets(): Target_Repository {
        return $this->targets_repo ??= new Target_Repository();
    }

    private function fields(): Field_Repository {
        return $this->fields_repo ??= new Field_Repository();
    }

    private function templates(): Template_Factory {
        return $this->templates_factory ??= new Template_Factory($this->plugin_dir . '/templates');
    }

    /**
     * @return array<string, mixed>
     */
    public function export_full(): array {
        $pack = $this->wrap('full', [
            'settings'          => $this->export_settings_stub(),
            'custom_templates'  => $this->export_custom_templates(),
            'configs'           => $this->export_all_configs(),
        ]);
        \NobleSchema\Tools\Log::info(__('Export pack created', 'noble-schema'), [
            'category'  => 'export_completed',
            'scope'     => 'full',
            'configs'   => count($pack['configs'] ?? []),
            'templates' => count($pack['custom_templates'] ?? []),
        ]);

        return $pack;
    }

    /**
     * @return array<string, mixed>
     */
    public function export_settings(): array {
        $pack = $this->wrap('settings', [
            'settings'         => $this->export_settings_stub(),
            'custom_templates' => $this->export_custom_templates(),
            'configs'          => [],
        ]);
        \NobleSchema\Tools\Log::info(__('Settings export pack created', 'noble-schema'), [
            'category' => 'export_completed',
            'scope'    => 'settings',
        ]);

        return $pack;
    }

    /**
     * @return array<string, mixed>
     */
    public function export_config(int $config_id): array {
        $config = $this->configs()->find($config_id);
        if ($config === null) {
            throw new \InvalidArgumentException('Config not found.');
        }

        $pack = $this->wrap('config', [
            'settings'         => $this->export_settings_stub(),
            'custom_templates' => [],
            'configs'          => [$this->serialize_config($config)],
        ]);
        \NobleSchema\Tools\Log::info(__('Config export pack created', 'noble-schema'), [
            'category'  => 'export_completed',
            'scope'     => 'config',
            'config_id' => $config_id,
        ]);

        return $pack;
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    public function preview(array $pack): array {
        $normalized = $this->normalize($pack);
        $writable = $this->templates()->custom_writable();

        $templates = [];
        foreach ($normalized['custom_templates'] as $template) {
            $slug = (string) ($template['slug'] ?? '');
            $conflict = $slug !== '' && $this->templates()->custom_exists($slug);
            $templates[] = [
                'slug'     => $slug,
                'label'    => (string) ($template['label'] ?? $slug),
                'conflict' => $conflict,
            ];
        }

        $configs = [];
        foreach ($normalized['configs'] as $index => $config) {
            $name = (string) ($config['name'] ?? '');
            $existing = $name !== '' ? $this->configs()->find_by_name($name) : null;
            $configs[] = [
                'index'         => $index,
                'name'          => $name,
                'template_slug' => (string) ($config['template_slug'] ?? ''),
                'schema_type'   => (string) ($config['schema_type'] ?? ''),
                'status'        => (string) ($config['status'] ?? ''),
                'conflict'      => $existing !== null,
                'existing_id'   => $existing ? (int) $existing->id : null,
            ];
        }

        $settings_preview = Settings_Page::preview_settings((array) ($normalized['settings'] ?? []));

        return [
            'scope'            => $normalized['scope'],
            'exported_at'      => $normalized['exported_at'],
            'settings'         => $settings_preview,
            'custom_templates' => $templates,
            'configs'          => $configs,
            'custom_writable'  => $writable,
            'warnings'         => !$writable && $templates !== []
                ? [__('Custom templates cannot be written on this environment (uploads/noble-schema/templates is not writable).', 'noble-schema')]
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @param array{
     *   templates?: array<string, string>,
     *   configs?: array<string, string>
     * } $decisions Map of slug|index => overwrite|skip|create
     * @return array{imported: array<string, int>, skipped: array<string, int>, messages: list<string>}
     */
    public function apply(array $pack, array $decisions = []): array {
        $normalized = $this->normalize($pack);
        $template_decisions = (array) ($decisions['templates'] ?? []);
        $config_decisions = (array) ($decisions['configs'] ?? []);

        $imported = ['settings' => 0, 'templates' => 0, 'configs' => 0];
        $skipped = ['settings' => 0, 'templates' => 0, 'configs' => 0];
        $messages = [];

        // Project settings (GitHub update options; token imported when present).
        $settings = (array) ($normalized['settings'] ?? []);
        if ($settings === []) {
            $skipped['settings'] = 0;
        } else {
            $imported['settings'] = Settings_Page::import_settings($settings);
            if ($imported['settings'] === 0) {
                $skipped['settings'] = count($settings);
            }
        }

        $writable = $this->templates()->custom_writable();
        foreach ($normalized['custom_templates'] as $template) {
            $slug = (string) ($template['slug'] ?? '');
            if ($slug === '') {
                $skipped['templates']++;
                continue;
            }
            $conflict = $this->templates()->custom_exists($slug);
            $action = $template_decisions[$slug] ?? ($conflict ? 'skip' : 'create');
            if (!in_array($action, ['overwrite', 'skip', 'create'], true)) {
                $action = $conflict ? 'skip' : 'create';
            }

            if ($action === 'skip') {
                $skipped['templates']++;
                continue;
            }

            if (!$writable) {
                $skipped['templates']++;
                $messages[] = sprintf(
                    /* translators: %s: template slug */
                    __('Skipped template “%s” because the uploads templates directory is not writable.', 'noble-schema'),
                    $slug
                );
                continue;
            }

            $to_write = $template;
            if ($action === 'create' && $conflict) {
                $to_write['slug'] = $this->unique_template_slug($slug);
                $to_write['label'] = (string) ($to_write['label'] ?? $slug) . ' (imported)';
            }

            try {
                $loader = new Template_Loader(dirname(__DIR__, 2) . '/templates');
                if (!$loader->validate($to_write)) {
                    throw new \RuntimeException('Template failed validation.');
                }
                $this->templates()->write($to_write);
                $imported['templates']++;
            } catch (\Throwable $e) {
                $skipped['templates']++;
                $messages[] = sprintf(
                    /* translators: 1: slug, 2: error */
                    __('Could not import template “%1$s”: %2$s', 'noble-schema'),
                    $slug,
                    $e->getMessage()
                );
            }
        }

        foreach ($normalized['configs'] as $index => $config) {
            $name = (string) ($config['name'] ?? '');
            $existing = $name !== '' ? $this->configs()->find_by_name($name) : null;
            $conflict = $existing !== null;
            $key = (string) $index;
            $action = $config_decisions[$key] ?? ($conflict ? 'skip' : 'create');
            if (!in_array($action, ['overwrite', 'skip', 'create'], true)) {
                $action = $conflict ? 'skip' : 'create';
            }

            if ($action === 'skip') {
                $skipped['configs']++;
                continue;
            }

            try {
                $prepared = $this->prepare_import_config($config);
                foreach ($prepared['warnings'] as $warning) {
                    $messages[] = $warning;
                }

                if ($action === 'overwrite' && $existing) {
                    $id = $this->overwrite_config((int) $existing->id, $prepared['config'], $prepared['targets'], $prepared['fields']);
                } else {
                    $payload = $prepared['config'];
                    if ($action === 'create' && $conflict) {
                        $payload['name'] = $this->unique_config_name($name);
                    }
                    $id = $this->insert_config($payload, $prepared['targets'], $prepared['fields']);
                }
                $this->sync_sitewide_cache_after_import($id);
                $imported['configs']++;
            } catch (\Throwable $e) {
                $skipped['configs']++;
                $messages[] = sprintf(
                    /* translators: 1: config name, 2: error */
                    __('Could not import config “%1$s”: %2$s', 'noble-schema'),
                    $name !== '' ? $name : '#' . $index,
                    $e->getMessage()
                );
            }
        }

        \NobleSchema\Tools\Log::info(__('Import pack applied', 'noble-schema'), [
            'category' => 'import_completed',
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
        if ($messages !== []) {
            \NobleSchema\Tools\Log::warning(__('Import completed with messages', 'noble-schema'), [
                'category' => 'import_completed',
                'messages' => $messages,
            ]);
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'messages' => $messages,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array {
        if (($raw['format'] ?? '') !== self::FORMAT) {
            throw new \InvalidArgumentException('Unrecognized export format.');
        }
        $version = (int) ($raw['version'] ?? 0);
        if ($version < 1 || $version > self::VERSION) {
            throw new \InvalidArgumentException('Unsupported export version.');
        }

        $scope = (string) ($raw['scope'] ?? 'full');
        if (!in_array($scope, ['full', 'settings', 'config'], true)) {
            $scope = 'full';
        }

        $configs = [];
        if (isset($raw['config']) && is_array($raw['config'])) {
            $configs[] = $raw['config'];
        }
        if (isset($raw['configs']) && is_array($raw['configs'])) {
            foreach ($raw['configs'] as $row) {
                if (is_array($row)) {
                    $configs[] = $row;
                }
            }
        }

        $templates = [];
        if (isset($raw['custom_templates']) && is_array($raw['custom_templates'])) {
            foreach ($raw['custom_templates'] as $row) {
                if (is_array($row) && !empty($row['slug'])) {
                    $templates[] = $row;
                }
            }
        }

        return [
            'format'           => self::FORMAT,
            'version'          => $version,
            'scope'            => $scope,
            'exported_at'      => (string) ($raw['exported_at'] ?? ''),
            'settings'         => is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
            'custom_templates' => $templates,
            'configs'          => $configs,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function wrap(string $scope, array $body): array {
        return array_merge([
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => gmdate('c'),
            'scope'       => $scope,
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function export_settings_stub(): array {
        return Settings_Page::exportable_settings();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function export_custom_templates(): array {
        $items = [];
        foreach ($this->templates()->list_custom() as $meta) {
            $path = $this->templates()->custom_path($meta['slug']);
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $items[] = $data;
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function export_all_configs(): array {
        $out = [];
        foreach ($this->configs()->all(['orderby' => 'name', 'order' => 'ASC']) as $config) {
            $out[] = $this->serialize_config($config);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize_config(object $config): array {
        $id = (int) $config->id;
        $targets = array_map(static function ($row): array {
            $payload = [
                'rule_type'  => (string) ($row->rule_type ?? ''),
                'rule_key'   => $row->rule_key !== null ? (string) $row->rule_key : null,
                'rule_value' => (string) ($row->rule_value ?? ''),
                'operator'   => (string) ($row->operator ?? 'is'),
                'rule_group' => (int) ($row->rule_group ?? 0),
            ];

            if ($payload['rule_type'] === 'taxonomy') {
                $term_id = (int) $payload['rule_value'];
                $taxonomy = (string) ($payload['rule_key'] ?? '');
                $term = $term_id > 0 ? get_term($term_id, $taxonomy) : null;
                if ($term && !is_wp_error($term)) {
                    $payload['term_slug'] = (string) ($term->slug ?? '');
                    $payload['term_name'] = html_entity_decode((string) ($term->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            return $payload;
        }, $this->targets()->for_config($id));

        $fields = array_map(static function ($row): array {
            $source_config = json_decode((string) ($row->source_config ?? ''), true);
            if (!is_array($source_config)) {
                $source_config = [];
            }

            return [
                'field_path'        => (string) ($row->field_path ?? ''),
                'source_type'       => (string) ($row->source_type ?? ''),
                'source_config'     => $source_config,
                'required_override' => $row->required_override === null ? null : (int) $row->required_override,
            ];
        }, $this->fields()->for_config($id));

        return [
            'name'               => (string) ($config->name ?? ''),
            'template_slug'      => (string) ($config->template_slug ?? ''),
            'schema_type'        => (string) ($config->schema_type ?? ''),
            'placement'          => (string) ($config->placement ?? 'global'),
            'filter_logic'       => (string) ($config->filter_logic ?? 'and'),
            'status'             => (string) ($config->status ?? 'disabled'),
            'priority'           => (int) ($config->priority ?? 10),
            'strict_validation'  => !isset($config->strict_validation) || (int) $config->strict_validation === 1 ? 1 : 0,
            'targets'            => $targets,
            'fields'             => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   config: array<string, mixed>,
     *   targets: list<array<string, mixed>>,
     *   fields: list<array<string, mixed>>,
     *   warnings: list<string>
     * }
     */
    private function prepare_import_config(array $config): array {
        $targets = $this->normalize_targets($config['targets'] ?? []);
        $fields = $this->normalize_fields($config['fields'] ?? []);
        $placement = $this->resolve_import_placement(
            $targets,
            (string) ($config['placement'] ?? 'global')
        );
        $config['placement'] = $placement;

        $warnings = [];
        if ($placement === 'sitewide') {
            // Sitewide does not support additional filters.
            $targets = [];

            $blocked = Sitewide_Sources::blocked_in_maps($fields);
            if ($blocked !== []) {
                $config['status'] = 'disabled';
                $warnings[] = sprintf(
                    /* translators: 1: config name, 2: comma-separated source types */
                    __('Imported “%1$s” as disabled: Sitewide only allows Site info or Static sources (%2$s).', 'noble-schema'),
                    (string) ($config['name'] ?? __('config', 'noble-schema')),
                    implode(', ', $blocked)
                );
            }
        }

        return [
            'config'   => $config,
            'targets'  => $targets,
            'fields'   => $fields,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $targets
     * @param list<array<string, mixed>> $fields
     */
    private function insert_config(array $config, array $targets, array $fields): int {
        $id = $this->configs()->insert($this->config_row($config));
        if ($id <= 0) {
            throw new \RuntimeException('Could not insert config.');
        }
        $this->targets()->replace($id, $targets);
        $this->fields()->replace($id, $fields);

        return $id;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $targets
     * @param list<array<string, mixed>> $fields
     */
    private function overwrite_config(int $id, array $config, array $targets, array $fields): int {
        $this->configs()->update($id, $this->config_row($config));
        $this->targets()->replace($id, $targets);
        $this->fields()->replace($id, $fields);

        return $id;
    }

    private function sync_sitewide_cache_after_import(int $config_id): void {
        if ($config_id <= 0) {
            return;
        }

        $config = $this->configs()->find($config_id);
        if (!$config || (string) ($config->placement ?? '') !== 'sitewide') {
            (new Sitewide_Cache())->delete($config_id);

            return;
        }

        if ((string) ($config->status ?? '') !== 'active') {
            (new Sitewide_Cache())->delete($config_id);

            return;
        }

        if (function_exists('\\NobleSchema\\plugin')) {
            \NobleSchema\plugin()->generator()->rebuild_sitewide_config($config_id);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function config_row(array $config): array {
        return [
            'name'               => (string) ($config['name'] ?? 'Imported config'),
            'template_slug'      => (string) ($config['template_slug'] ?? ''),
            'schema_type'        => (string) ($config['schema_type'] ?? ''),
            'placement'          => (string) ($config['placement'] ?? 'global'),
            'filter_logic'       => (string) ($config['filter_logic'] ?? 'and'),
            'status'             => (string) ($config['status'] ?? 'disabled'),
            'priority'           => (int) ($config['priority'] ?? 10),
            'strict_validation'  => !array_key_exists('strict_validation', $config) || !empty($config['strict_validation']) ? 1 : 0,
        ];
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function normalize_targets($rows): array {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['rule_type'] ?? '');
            $key = array_key_exists('rule_key', $row) ? $row['rule_key'] : null;
            if ($type === 'has_block') {
                $key = 'filter';
            }

            $value = (string) ($row['rule_value'] ?? '');
            if ($type === 'taxonomy') {
                $resolved = \NobleSchema\Targeting\Taxonomy_Rule::resolve_imported_term_id($row);
                if ($resolved <= 0) {
                    continue;
                }
                $value = (string) $resolved;
            }

            $out[] = [
                'rule_type'  => $type,
                'rule_key'   => $key,
                'rule_value' => $value,
                'operator'   => (string) ($row['operator'] ?? 'is'),
                'rule_group' => (int) ($row['rule_group'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function resolve_import_placement(array $targets, string $requested): string {
        if ($requested === 'global') {
            return 'global';
        }

        if ($requested === 'sitewide') {
            return 'sitewide';
        }

        $has_post_type = false;
        $has_template = false;
        $has_archive = false;
        $has_post_id = false;
        $has_specialty = false;
        foreach ($targets as $row) {
            $type = (string) ($row['rule_type'] ?? '');
            if ($type === 'post_type') {
                $has_post_type = true;
            } elseif ($type === 'page_template') {
                $has_template = true;
            } elseif ($type === 'archive') {
                $has_archive = true;
            } elseif ($type === 'post_id') {
                $has_post_id = true;
            } elseif ($type === 'specialty') {
                $has_specialty = true;
            }
        }

        $count = (int) $has_post_type + (int) $has_template + (int) $has_archive + (int) $has_post_id + (int) $has_specialty;
        if ($count === 0) {
            // Placement-only block packs become Global + filter Has block.
            return 'global';
        }
        if ($count > 1) {
            return 'mixed';
        }
        if ($has_post_type) {
            return 'post_type';
        }
        if ($has_template) {
            return 'page_template';
        }
        if ($has_archive) {
            return 'archive';
        }
        if ($has_specialty) {
            return 'specialty';
        }

        return 'post_id';
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function normalize_fields($rows): array {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'field_path'        => (string) ($row['field_path'] ?? ''),
                'source_type'       => (string) ($row['source_type'] ?? ''),
                'source_config'     => $row['source_config'] ?? [],
                'required_override' => array_key_exists('required_override', $row) ? $row['required_override'] : null,
            ];
        }

        return $out;
    }

    private function unique_template_slug(string $slug): string {
        $base = $slug;
        $i = 2;
        while ($this->templates()->custom_exists($slug) || $this->templates()->builtin_exists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 100) {
                $slug = $base . '-' . wp_generate_password(6, false, false);
                break;
            }
        }

        return $slug;
    }

    private function unique_config_name(string $name): string {
        $base = $name !== '' ? $name : 'Imported config';
        $candidate = $base . ' (imported)';
        $i = 2;
        while ($this->configs()->find_by_name($candidate) !== null) {
            $candidate = $base . ' (imported ' . $i . ')';
            $i++;
            if ($i > 100) {
                $candidate = $base . ' (imported ' . wp_generate_password(4, false, false) . ')';
                break;
            }
        }

        return $candidate;
    }
}
