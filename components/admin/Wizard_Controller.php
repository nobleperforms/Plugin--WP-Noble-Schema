<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Templates\Template_Loader;

class Wizard_Controller {
    private Template_Loader $templates;
    private Config_Repository $configs;
    private Target_Repository $targets;
    private Field_Repository $fields;

    public function __construct(string $plugin_dir) {
        $this->templates = new Template_Loader($plugin_dir . '/templates');
        $this->configs   = new Config_Repository();
        $this->targets   = new Target_Repository();
        $this->fields    = new Field_Repository();
    }

    /**
     * @return array<string, mixed>
     */
    public function view_data(int $config_id = 0): array {
        $config  = null;
        $targets = [];
        $maps    = [];

        if ($config_id > 0) {
            $config = $this->configs->find($config_id);
            if ($config) {
                $targets = $this->targets->for_config($config_id);
                $maps    = $this->fields->for_config($config_id);
            }
        }

        $field_maps = [];
        foreach ($maps as $row) {
            $decoded = json_decode((string) $row->source_config, true);
            $field_maps[$row->field_path] = [
                'source_type'   => $row->source_type,
                'source_config' => is_array($decoded) ? $decoded : [],
            ];
        }

        $placement_targets = [
            'post_types'     => [],
            'page_templates' => [],
            'archives'       => [],
            'specialties'    => [],
            'posts'          => [],
            'filter_groups'  => [],
        ];

        $filter_groups = [];
        foreach ($targets as $row) {
            switch ($row->rule_type) {
                case 'post_type':
                    $placement_targets['post_types'][] = $row->rule_value;
                    break;
                case 'page_template':
                    $placement_targets['page_templates'][] = $row->rule_value;
                    break;
                case 'archive':
                    $placement_targets['archives'][] = $row->rule_value;
                    break;
                case 'specialty':
                    $placement_targets['specialties'][] = $row->rule_value;
                    break;
                case 'post_id':
                    $placement_targets['posts'][] = (string) (int) $row->rule_value;
                    break;
                case 'has_block':
                    $group = (int) ($row->rule_group ?? 0);
                    $filter_groups[$group][] = [
                        'type'  => 'has_block',
                        'block' => $row->rule_value,
                    ];
                    break;
                case 'taxonomy':
                    $group = (int) ($row->rule_group ?? 0);
                    $term_id = (int) ($row->rule_value ?? 0);
                    $taxonomy = (string) ($row->rule_key ?? '');
                    $filter_groups[$group][] = [
                        'type'       => 'taxonomy',
                        'taxonomy'   => $taxonomy,
                        'term_id'    => $term_id,
                        'term_label' => \NobleSchema\Targeting\Taxonomy_Rule::term_label($term_id, $taxonomy),
                        'operator'   => $row->operator,
                    ];
                    break;
                case 'post_meta':
                    $group = (int) ($row->rule_group ?? 0);
                    $filter_groups[$group][] = [
                        'type'     => 'post_meta',
                        'key'      => (string) ($row->rule_key ?? ''),
                        'value'    => (string) ($row->rule_value ?? ''),
                        'operator' => (string) ($row->operator ?? 'is'),
                    ];
                    break;
                case 'language':
                    $group = (int) ($row->rule_group ?? 0);
                    $filter_groups[$group][] = [
                        'type'     => 'language',
                        'language' => (string) ($row->rule_value ?? ''),
                        'operator' => (string) ($row->operator ?? 'is'),
                    ];
                    break;
            }
        }

        if ($filter_groups !== []) {
            ksort($filter_groups);
            $placement_targets['filter_groups'] = array_values($filter_groups);
        }

        return [
            'config_id'         => $config_id,
            'is_edit'           => $config_id > 0 && $config !== null,
            'config'            => $config,
            'templates'         => $this->templates->all(),
            'templates_json'    => wp_json_encode($this->templates->all()),
            'field_maps'        => $field_maps,
            'placement_targets' => $placement_targets,
            'post_types'        => get_post_types(['public' => true], 'objects'),
            'page_templates'    => wp_get_theme()->get_page_templates(),
            'block_types'       => $this->block_types(),
            'block_attributes'  => \NobleSchema\Mapping\Block_Attribute_Catalog::all_registered(),
            'archive_pages'     => \NobleSchema\Targeting\Archive_Context::available_archives(),
            'specialty_pages'   => \NobleSchema\Targeting\Specialty_Context::available(),
            'selected_post_options' => $this->selected_post_options($placement_targets['posts'] ?? []),
            'has_archive_cache' => $config_id > 0
                && (new \NobleSchema\Cache\Archive_Cache())->count_for_config($config_id) > 0,
            'taxonomies'        => \NobleSchema\Targeting\Taxonomy_Rule::selectable_taxonomies(),
            'wpml_languages'    => \NobleSchema\Integrations\Plugin_Integrations::is_wpml_active()
                ? \NobleSchema\Integrations\Plugin_Integrations::wpml_active_languages()
                : [],
            'wpml_notice'       => $this->wpml_language_notice($config_id),
            'source_types'      => $this->source_types(),
            'core_fields'       => $this->core_fields(),
            'site_info_fields'  => \NobleSchema\Mapping\Site_Info_Resolver::fields(),
            'yoast_fields'      => \NobleSchema\Integrations\Plugin_Integrations::is_yoast_active()
                ? \NobleSchema\Integrations\Plugin_Integrations::yoast_fields()
                : [],
            'events_calendar_fields' => \NobleSchema\Integrations\Plugin_Integrations::is_events_calendar_active()
                ? \NobleSchema\Integrations\Plugin_Integrations::events_calendar_fields()
                : [],
            'callback_slugs'    => \NobleSchema\plugin()->resolvers()->callback_slugs(),
            'save_url'          => admin_url('admin-post.php'),
            'nonce'             => wp_create_nonce('ns_save_config'),
        ];
    }

    /**
     * @return array{title:string,message:string,button:string,switchUrl:string}|null
     */
    private function wpml_language_notice(int $config_id): ?array {
        $args = ['action' => $config_id > 0 ? 'edit' : 'add'];

        if ($config_id > 0) {
            $args['config_id'] = $config_id;
        }

        return Language_Guard::wizard_payload(Menu::page_url($args));
    }

    /**
     * @param string[] $post_ids
     * @return array<int, array{value: string, label: string}>
     */
    private function selected_post_options(array $post_ids): array {
        $options = [];
        foreach ($post_ids as $id) {
            $post_id = (int) $id;
            if ($post_id <= 0) {
                continue;
            }
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                $options[] = [
                    'value' => (string) $post_id,
                    'label' => sprintf(
                        /* translators: %d: post ID */
                        __('Missing post #%d', 'noble-schema'),
                        $post_id
                    ),
                ];
                continue;
            }

            $type = get_post_type_object($post->post_type);
            $type_label = $type ? (string) ($type->labels->singular_name ?? $post->post_type) : $post->post_type;
            $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '') {
                $title = sprintf(
                    /* translators: %d: post ID */
                    __('(no title) #%d', 'noble-schema'),
                    $post_id
                );
            }

            $options[] = [
                'value' => (string) $post_id,
                'label' => sprintf('%s (%s · #%d)', $title, $type_label, $post_id),
            ];
        }

        return $options;
    }

    /**
     * @return string[]
     */
    private function block_types(): array {
        $types = [];

        if (class_exists('WP_Block_Type_Registry')) {
            $registry = \WP_Block_Type_Registry::get_instance();
            foreach ($registry->get_all_registered() as $block) {
                if (!empty($block->name)) {
                    $types[] = $block->name;
                }
            }
        }

        sort($types);

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function source_types(): array {
        $types = [
            'core'           => __('WordPress Core Field', 'noble-schema'),
            'site_info'      => __('Site Info', 'noble-schema'),
            'post_meta'      => __('Post Meta', 'noble-schema'),
        ];

        if (\NobleSchema\Integrations\Plugin_Integrations::is_acf_active()) {
            $types['acf'] = __('ACF Field', 'noble-schema');
        }

        if (\NobleSchema\Integrations\Plugin_Integrations::is_yoast_active()) {
            $types['yoast'] = __('Yoast SEO', 'noble-schema');
        }

        if (\NobleSchema\Integrations\Plugin_Integrations::is_events_calendar_active()) {
            $types['events_calendar'] = __('Events Calendar', 'noble-schema');
        }

        $types += [
            'static'         => __('Static Value', 'noble-schema'),
            'manual_post'    => __('Manual (Post Editor)', 'noble-schema'),
            'manual_block'   => __('Manual (Block Editor)', 'noble-schema'),
            'taxonomy'       => __('Taxonomy Term', 'noble-schema'),
            'block_attr'     => __('Block Attribute', 'noble-schema'),
            'block_content'  => __('Block Content', 'noble-schema'),
            'callback'       => __('Custom Callback', 'noble-schema'),
        ];

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function core_fields(): array {
        return [
            'title'           => __('Post Title', 'noble-schema'),
            'excerpt'         => __('Excerpt', 'noble-schema'),
            'permalink'       => __('Permalink', 'noble-schema'),
            'featured_image'  => __('Featured Image URL', 'noble-schema'),
            'author_name'     => __('Author Name', 'noble-schema'),
            'date_published'  => __('Date Published', 'noble-schema'),
            'date_modified'   => __('Date Modified', 'noble-schema'),
        ];
    }
}
