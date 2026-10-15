<?php

declare(strict_types=1);

use NobleSchema\Admin\Menu;
use NobleSchema\Db\Config_Repository;
use NobleSchema\SchemaOrg\Template_Factory;
use NobleSchema\SchemaOrg\Vocabulary;

add_action('wp_ajax_ns_schema_tools_storefront_vocab', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    try {
        $vocab = new Vocabulary();
        $status = $vocab->status();
        if (!$status['loaded']) {
            $vocab->get_index(true);
            $status = $vocab->status();
        }

        wp_send_json_success([
            'status' => $status,
            'types'  => $vocab->list_types(),
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not load schema.org vocabulary: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_storefront_refresh', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    try {
        $vocab = new Vocabulary();
        $index = $vocab->refresh();
        if ($index === null) {
            throw new RuntimeException(__('Type refresh failed.', 'noble-schema'));
        }

        wp_send_json_success([
            'status' => $vocab->status(),
            'types'  => $vocab->list_types(),
            'message'=> __('Type refreshed from schema.org.', 'noble-schema'),
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not refresh schema.org type: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_storefront_type', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $type_id = isset($_POST['type_id']) ? sanitize_text_field(wp_unslash((string) $_POST['type_id'])) : '';
    if ($type_id === '') {
        wp_send_json_error(['message' => __('Type is required.', 'noble-schema')], 400);
    }

    try {
        $detail = (new Vocabulary())->get_type($type_id);
        if ($detail === null) {
            wp_send_json_error(['message' => __('Unknown schema.org type.', 'noble-schema')], 404);
        }

        $factory = new Template_Factory(dirname(__DIR__, 2) . '/templates');
        $slug = Template_Factory::slugify($detail['id']);

        wp_send_json_success([
            'type'            => $detail,
            'default_slug'    => $slug,
            'custom_exists'   => $factory->custom_exists($slug),
            'builtin_exists'  => $factory->builtin_exists($slug),
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not load type: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_storefront_save', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $type_id = isset($_POST['type_id']) ? sanitize_text_field(wp_unslash((string) $_POST['type_id'])) : '';
    $slug = isset($_POST['slug']) ? strtolower(sanitize_text_field(wp_unslash((string) $_POST['slug']))) : '';
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash((string) $_POST['label'])) : '';
    $properties = isset($_POST['properties']) ? (array) wp_unslash($_POST['properties']) : [];
    $properties = array_values(array_filter(array_map('sanitize_text_field', $properties)));
    $confirm = !empty($_POST['confirm_overwrite']);

    if ($type_id === '') {
        wp_send_json_error(['message' => __('Type is required.', 'noble-schema')], 400);
    }

    try {
        $vocab = new Vocabulary();
        $detail = $vocab->get_type($type_id);
        if ($detail === null) {
            wp_send_json_error(['message' => __('Unknown schema.org type.', 'noble-schema')], 404);
        }

        $templates_dir = dirname(__DIR__, 2) . '/templates';
        $factory = new Template_Factory($templates_dir);
        if (!$factory->custom_writable()) {
            wp_send_json_error([
                'code'    => 'not_writable',
                'message' => __('Custom templates can’t be saved because the uploads templates directory is not writable on this environment.', 'noble-schema'),
            ], 403);
        }
        $template = $factory->build($detail, $properties, $slug, $label);
        $final_slug = (string) $template['slug'];

        $custom_exists = $factory->custom_exists($final_slug);
        $builtin_exists = $factory->builtin_exists($final_slug);

        if (($custom_exists || $builtin_exists) && !$confirm) {
            wp_send_json_error([
                'code'           => 'needs_confirm',
                'custom_exists'  => $custom_exists,
                'builtin_exists' => $builtin_exists,
                'slug'           => $final_slug,
                'message'        => $custom_exists
                    ? __('A custom template with this slug already exists. Overwrite it?', 'noble-schema')
                    : __('This slug matches a built-in template and will shadow it in the wizard. Continue?', 'noble-schema'),
            ], 409);
        }

        $written = $factory->write($template);
        $add_url = Menu::page_url([
            'action' => 'add',
        ]);

        wp_send_json_success([
            'slug'     => $final_slug,
            'path'     => $written['path'],
            'overwrote'=> $written['overwrote'],
            'add_url'  => $add_url,
            'message'  => sprintf(
                /* translators: %s: template label */
                __('“%s” saved to custom templates.', 'noble-schema'),
                (string) $template['label']
            ),
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not save template: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_storefront_list_custom', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    try {
        $factory = new Template_Factory(dirname(__DIR__, 2) . '/templates');
        wp_send_json_success([
            'templates' => $factory->list_custom(),
            'writable'  => $factory->custom_writable(),
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not list custom templates: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});

add_action('wp_ajax_ns_schema_tools_storefront_delete_custom', static function (): void {
    \NobleSchema\Admin\Access::require_manage_schema();
    check_ajax_referer('ns_schema_tools', 'nonce');

    $slug = isset($_POST['slug']) ? strtolower(sanitize_text_field(wp_unslash((string) $_POST['slug']))) : '';
    if (!Template_Factory::is_valid_slug($slug)) {
        wp_send_json_error(['message' => __('Invalid template slug.', 'noble-schema')], 400);
    }

    try {
        $factory = new Template_Factory(dirname(__DIR__, 2) . '/templates');
        if (!$factory->custom_writable()) {
            wp_send_json_error([
                'code'    => 'not_writable',
                'message' => __('Custom templates can’t be deleted because the uploads templates directory is not writable on this environment.', 'noble-schema'),
            ], 403);
        }
        if (!$factory->custom_exists($slug)) {
            wp_send_json_error(['message' => __('Custom template not found.', 'noble-schema')], 404);
        }

        $label = $slug;
        foreach ($factory->list_custom() as $item) {
            if ($item['slug'] === $slug) {
                $label = $item['label'] !== '' ? $item['label'] : $slug;
                break;
            }
        }

        $configs = (new Config_Repository())->find_by_template_slug($slug);
        if ($configs !== []) {
            $items = array_map(static function ($row): array {
                $id = (int) ($row->id ?? 0);
                return [
                    'id'       => $id,
                    'name'     => (string) ($row->name ?? ''),
                    'edit_url' => $id > 0 ? Menu::edit_url($id) : '',
                ];
            }, $configs);

            $names = array_values(array_filter(array_map(
                static fn (array $item): string => $item['name'],
                $items
            )));
            $names_list = $names !== [] ? implode(', ', $names) : __('(unnamed configs)', 'noble-schema');

            wp_send_json_error([
                'code'     => 'in_use',
                'configs'  => $items,
                'message'  => sprintf(
                    /* translators: 1: template label, 2: comma-separated config names */
                    __('Cannot delete “%1$s”. It is used by: %2$s.', 'noble-schema'),
                    $label,
                    $names_list
                ),
            ], 409);
        }

        $factory->delete_custom($slug);

        wp_send_json_success([
            'slug'    => $slug,
            'message' => sprintf(
                /* translators: %s: template label */
                __('“%s” deleted.', 'noble-schema'),
                $label
            ),
        ]);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: error message */
                __('Could not delete template: %s', 'noble-schema'),
                $e->getMessage()
            ),
        ], 500);
    }
});
