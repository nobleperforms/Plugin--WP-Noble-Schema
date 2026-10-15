<?php

declare(strict_types=1);

namespace NobleSchema\Blocks;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Templates\Template_Loader;
use NobleSchema\Tools;

class Schema_Block {
    public const SCRIPT_HANDLE = 'noble-schema-block-editor';
    public const VALUES_ATTRIBUTE = 'nobleSchemaManual';

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $editor_blocks = null;

    public function __construct(
        private Config_Repository $configs,
        private Field_Repository $fields,
        private Template_Loader $templates,
    ) {}

    public function register(): void {
        add_filter('register_block_type_args', [$this, 'register_manual_attributes'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets'], 1);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function register_manual_attributes(array $args, string $block_name): array {
        unset($block_name);

        $attributes = is_array($args['attributes'] ?? null) ? $args['attributes'] : [];
        $attributes[self::VALUES_ATTRIBUTE] = [
            'type'    => 'object',
            'default' => (object) [],
        ];
        $args['attributes'] = $attributes;

        return $args;
    }

    public function enqueue_editor_assets(): void {
        $plugin_dir = dirname(__DIR__, 2);
        $script_path = $plugin_dir . '/dist/scripts/schema-block.js';

        if (!is_readable($script_path) || $this->editor_blocks() === []) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('dist/scripts/schema-block.js', $plugin_dir . '/index.php'),
            ['wp-blocks', 'wp-hooks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            Tools\get_version(),
            true
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'nsSchemaBlockData', [
            'blocks' => $this->editor_blocks(),
        ]);
    }

    /**
     * Return active manual-block mappings indexed by target block name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function editor_blocks(): array {
        if ($this->editor_blocks !== null) {
            return $this->editor_blocks;
        }

        $blocks = [];

        foreach ($this->configs->all(['status' => 'active', 'orderby' => 'priority', 'order' => 'ASC']) as $config) {
            $config_id = (int) ($config->id ?? 0);
            if ($config_id <= 0) {
                continue;
            }

            $template = $this->templates->get((string) ($config->template_slug ?? ''));
            $field_defs = [];
            if (is_array($template)) {
                foreach ($template['fields'] ?? [] as $def) {
                    if (is_array($def) && !empty($def['path'])) {
                        $field_defs[(string) $def['path']] = $def;
                    }
                }
            }

            $config_label = trim((string) ($config->name ?? ''));
            if ($config_label === '') {
                $config_label = (string) ($config->schema_type ?? __('Schema', 'noble-schema'));
            }

            foreach ($this->fields->for_config($config_id) as $map) {
                if (($map->source_type ?? '') !== 'manual_block') {
                    continue;
                }

                $source_config = json_decode((string) ($map->source_config ?? ''), true);
                if (!is_array($source_config)) {
                    continue;
                }

                $block_name = trim((string) ($source_config['block_name'] ?? ''));
                $value_path = trim((string) ($source_config['field_path'] ?? ''));
                $template_path = (string) ($map->field_path ?? '');
                if ($block_name === '' || $value_path === '' || $template_path === '') {
                    continue;
                }

                $def = $field_defs[$template_path] ?? ['path' => $template_path];
                if ($map->required_override !== null) {
                    $def['required'] = (bool) (int) $map->required_override;
                }

                $blocks[$block_name][] = [
                    'config_id'    => $config_id,
                    'config_label' => $config_label,
                    'key'          => "{$config_id}|{$value_path}",
                    'path'         => $value_path,
                    'label'        => (string) ($def['label'] ?? $template_path),
                    'help'         => (string) ($def['help'] ?? ''),
                    'required'     => (bool) ($def['required'] ?? false),
                    'value_type'   => (string) ($def['value_type'] ?? 'text'),
                ];
            }
        }

        ksort($blocks, SORT_STRING);
        $this->editor_blocks = $blocks;

        return $this->editor_blocks;
    }
}
