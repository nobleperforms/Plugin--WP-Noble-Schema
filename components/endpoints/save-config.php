<?php

declare(strict_types=1);

namespace NobleSchema\Endpoints;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Generator\Regen_Queue;
use NobleSchema\Templates\Template_Loader;

add_action('admin_post_ns_save_config', static function (): void {
    handle_save_config(false);
});

add_action('wp_ajax_ns_save_config', static function (): void {
    handle_save_config(true);
});

/**
 * @return never
 */
function handle_save_config(bool $ajax): void {
    if (!\NobleSchema\Admin\Access::can_manage_schema()) {
        if ($ajax) {
            wp_send_json_error(['message' => __('Unauthorized', 'noble-schema')], 403);
        }
        wp_die(esc_html__('Unauthorized', 'noble-schema'));
    }

    if ($ajax) {
        check_ajax_referer('ns_save_config', '_wpnonce');
    } else {
        check_admin_referer('ns_save_config');
    }

    $config_id = isset($_POST['config_id']) ? (int) $_POST['config_id'] : 0;
    $want_enabled = !empty($_POST['enabled']);
    $name      = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
    $template  = isset($_POST['template_slug']) ? sanitize_key(wp_unslash((string) $_POST['template_slug'])) : '';
    $placement_mode = isset($_POST['placement']) ? sanitize_key(wp_unslash((string) $_POST['placement'])) : 'global';
    if (!in_array($placement_mode, ['global', 'sitewide', 'targeted'], true)) {
        $placement_mode = 'targeted';
    }
    $filter_logic = isset($_POST['filter_logic']) ? sanitize_key(wp_unslash((string) $_POST['filter_logic'])) : 'and';
    if ($filter_logic !== 'or') {
        $filter_logic = 'and';
    }
    $strict_validation = !empty($_POST['strict_validation']);

    $respond = static function (bool $ok, string $message, array $extra = []) use ($ajax): void {
        if ($ajax) {
            $payload = array_merge([
                'message' => $message,
            ], $extra);
            if ($ok) {
                wp_send_json_success($payload);
            }
            wp_send_json_error($payload);
        }

        $id = (int) ($extra['config_id'] ?? 0);
        $args = [
            'page'       => 'schema-config',
            'ns_notice'  => $ok ? 'success' : 'error',
            'ns_message' => rawurlencode($message),
        ];
        if ($id > 0) {
            $args['action']    = 'edit';
            $args['config_id'] = $id;
        }
        if (!empty($extra['regen']['remaining'])) {
            $args['ns_regen_modal'] = '1';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    };

    if ($name === '') {
        $respond(false, __('Config name is required.', 'noble-schema'));
    }

    if ($template === '') {
        $respond(false, __('Please select a schema template.', 'noble-schema'));
    }

    $plugin_dir   = dirname(__DIR__, 2);
    $loader       = new Template_Loader($plugin_dir . '/templates');
    $template_def = $loader->get($template);

    if ($template_def === null) {
        $respond(false, __('Invalid schema template.', 'noble-schema'));
    }

    $target_rows = build_target_rows($placement_mode);
    $placement   = resolve_placement($placement_mode, $target_rows);
    $field_rows  = build_field_rows($template_def, $_POST['fields'] ?? []);

    if ($placement === 'sitewide') {
        $blocked = \NobleSchema\Targeting\Sitewide_Sources::blocked_in_maps($field_rows);
        if ($blocked !== []) {
            $respond(false, sprintf(
                /* translators: %s: comma-separated source type slugs */
                __('Sitewide configs can only use site info or static field sources. Switch to All pages for page-specific sources (%s).', 'noble-schema'),
                implode(', ', $blocked)
            ));
        }
    }

    $was_active = false;
    $previous_config = null;
    $previous_targets = [];
    if ($config_id > 0) {
        $previous_config = (new Config_Repository())->find($config_id);
        $was_active = $previous_config && (string) ($previous_config->status ?? '') === 'active';
        if ($previous_config) {
            $previous_targets = (new Target_Repository())->for_config($config_id);
        }
    }

    $callback_error = validate_callback_slugs($field_rows);
    if ($callback_error !== null) {
        $existing_id = persist_config($config_id, $name, $template, $template_def, $placement, $filter_logic, 'disabled', $strict_validation, $target_rows, $field_rows);
        enqueue_regen_after_save($existing_id, 'disabled', $was_active, $previous_config, $previous_targets);
        $respond(false, $callback_error, [
            'config_id' => $existing_id,
            'status'    => 'disabled',
            'regen'     => (new Regen_Queue())->get_status(),
        ]);
    }

    $mapping_error = validate_required_source_configs($template_def, $field_rows);
    if ($mapping_error !== null) {
        $respond(false, $mapping_error);
    }

    $enable_error = validate_enable($template_def, $field_rows, $placement, $target_rows);
    $status       = ($want_enabled && $enable_error === null) ? 'active' : 'disabled';
    $id           = persist_config($config_id, $name, $template, $template_def, $placement, $filter_logic, $status, $strict_validation, $target_rows, $field_rows);

    if ($id <= 0) {
        $respond(false, __('Could not save config.', 'noble-schema'));
    }

    (new \NobleSchema\Cache\Archive_Cache())->clear_for_config($id);
    (new \NobleSchema\Cache\Specialty_Cache())->clear_for_config($id);
    if ($placement !== 'sitewide') {
        (new \NobleSchema\Cache\Sitewide_Cache())->delete($id);
    }
    enqueue_regen_after_save($id, $status, $was_active, $previous_config, $previous_targets);
    $regen = (new Regen_Queue())->get_status();

    if ($want_enabled && $enable_error !== null) {
        $respond(false, $enable_error, [
            'config_id' => $id,
            'status'    => 'disabled',
            'edit_url'  => \NobleSchema\Admin\Menu::edit_url($id),
            'regen'     => $regen,
        ]);
    }

    $message = $status === 'active'
        ? __('Config saved and enabled.', 'noble-schema')
        : __('Config saved.', 'noble-schema');

    $respond(true, $message, [
        'config_id' => $id,
        'status'    => $status,
        'edit_url'  => \NobleSchema\Admin\Menu::edit_url($id),
        'regen'     => $regen,
    ]);
}
/**
 * @param array<int, array<string, string|null>> $target_rows
 */
function resolve_placement(string $placement_mode, array $target_rows): string {
    if ($placement_mode === 'global') {
        return 'global';
    }

    if ($placement_mode === 'sitewide') {
        return 'sitewide';
    }

    $has_post_type = has_rule_type($target_rows, 'post_type');
    $has_template  = has_rule_type($target_rows, 'page_template');
    $has_archive   = has_rule_type($target_rows, 'archive');
    $has_post_id   = has_rule_type($target_rows, 'post_id');
    $has_specialty = has_rule_type($target_rows, 'specialty');

    $count = (int) $has_post_type + (int) $has_template + (int) $has_archive + (int) $has_post_id + (int) $has_specialty;
    if ($count === 0) {
        return 'mixed';
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
 * @return array<int, array<string, string|int|null>>
 */
function build_target_rows(string $placement_mode): array {
    $rows = [];

    if ($placement_mode !== 'global' && $placement_mode !== 'sitewide') {
        $types = array_map('sanitize_key', array_filter((array) ($_POST['target_post_types'] ?? [])));
        foreach ($types as $type) {
            $rows[] = [
                'rule_type'  => 'post_type',
                'rule_key'   => null,
                'rule_value' => $type,
                'operator'   => 'is',
                'rule_group' => 0,
            ];
        }

        $templates = array_map(static fn ($v) => sanitize_text_field(wp_unslash((string) $v)), (array) ($_POST['target_page_templates'] ?? []));
        foreach (array_filter($templates) as $tpl) {
            $rows[] = [
                'rule_type'  => 'page_template',
                'rule_key'   => null,
                'rule_value' => $tpl,
                'operator'   => 'is',
                'rule_group' => 0,
            ];
        }

        $archives = array_map(
            static fn ($v) => sanitize_text_field(wp_unslash((string) $v)),
            (array) ($_POST['target_archives'] ?? [])
        );
        foreach (array_unique(array_filter($archives)) as $archive) {
            if (!\NobleSchema\Targeting\Archive_Context::is_valid_targeting_key($archive)) {
                continue;
            }
            $rows[] = [
                'rule_type'  => 'archive',
                'rule_key'   => null,
                'rule_value' => $archive,
                'operator'   => 'is',
                'rule_group' => 0,
            ];
        }

        $specialties = array_map(
            static fn ($v) => sanitize_key(wp_unslash((string) $v)),
            (array) ($_POST['target_specialties'] ?? [])
        );
        foreach (array_unique(array_filter($specialties)) as $specialty) {
            if (!\NobleSchema\Targeting\Specialty_Context::is_known($specialty)) {
                continue;
            }
            $rows[] = [
                'rule_type'  => 'specialty',
                'rule_key'   => null,
                'rule_value' => $specialty,
                'operator'   => 'is',
                'rule_group' => 0,
            ];
        }

        $post_ids = array_map('intval', (array) ($_POST['target_posts'] ?? []));
        foreach (array_unique(array_filter($post_ids)) as $post_id) {
            $rows[] = [
                'rule_type'  => 'post_id',
                'rule_key'   => null,
                'rule_value' => (string) $post_id,
                'operator'   => 'is',
                'rule_group' => 0,
            ];
        }
    }

    // Sitewide is always-on site schema; additional filters belong to All pages / Targeted only.
    if ($placement_mode === 'sitewide') {
        return $rows;
    }

    $filter_groups = (array) ($_POST['filter_groups'] ?? []);
    $group_index   = 0;
    foreach ($filter_groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $group_has_rule = false;
        foreach ($group as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $type = sanitize_key((string) ($rule['type'] ?? ''));
            if ($type === 'taxonomy') {
                $normalized = \NobleSchema\Targeting\Taxonomy_Rule::normalize_rule([
                    'taxonomy' => (string) ($rule['taxonomy'] ?? ''),
                    'term_id'  => $rule['term_id'] ?? $rule['term'] ?? 0,
                    'operator' => (string) ($rule['operator'] ?? 'is'),
                ]);
                if ($normalized === null) {
                    continue;
                }
                $rows[] = [
                    'rule_type'  => 'taxonomy',
                    'rule_key'   => $normalized['taxonomy'],
                    'rule_value' => (string) $normalized['term_id'],
                    'operator'   => $normalized['operator'],
                    'rule_group' => $group_index,
                ];
                $group_has_rule = true;
                continue;
            }

            if ($type === 'has_block') {
                $block = sanitize_text_field(wp_unslash((string) ($rule['block'] ?? '')));
                if ($block === '') {
                    continue;
                }
                $rows[] = [
                    'rule_type'  => 'has_block',
                    'rule_key'   => 'filter',
                    'rule_value' => $block,
                    'operator'   => 'is',
                    'rule_group' => $group_index,
                ];
                $group_has_rule = true;
                continue;
            }

            if ($type === 'post_meta') {
                $normalized = \NobleSchema\Targeting\Post_Meta_Rule::normalize_rule([
                    'key'      => wp_unslash((string) ($rule['key'] ?? '')),
                    'value'    => wp_unslash((string) ($rule['value'] ?? '')),
                    'operator' => (string) ($rule['operator'] ?? 'is'),
                ]);
                if ($normalized === null) {
                    continue;
                }
                $rows[] = [
                    'rule_type'  => 'post_meta',
                    'rule_key'   => $normalized['key'],
                    'rule_value' => $normalized['value'],
                    'operator'   => $normalized['operator'],
                    'rule_group' => $group_index,
                ];
                $group_has_rule = true;
                continue;
            }

            if ($type === 'language') {
                $normalized = \NobleSchema\Targeting\Language_Rule::normalize_rule([
                    'language' => wp_unslash((string) ($rule['language'] ?? '')),
                    'operator' => (string) ($rule['operator'] ?? 'is'),
                ]);
                if ($normalized === null) {
                    continue;
                }
                $rows[] = [
                    'rule_type'  => 'language',
                    'rule_key'   => '',
                    'rule_value' => $normalized['language'],
                    'operator'   => $normalized['operator'],
                    'rule_group' => $group_index,
                ];
                $group_has_rule = true;
            }
        }

        if ($group_has_rule) {
            $group_index++;
        }
    }

    return $rows;
}

/**
 * @param array<string, mixed> $template_def
 * @param mixed $posted_fields
 * @return array<int, array<string, mixed>>
 */
function build_field_rows(array $template_def, mixed $posted_fields): array {
    if (!is_array($posted_fields)) {
        return [];
    }

    $rows = [];

    foreach ($template_def['fields'] as $field_def) {
        $path = (string) ($field_def['path'] ?? '');
        if ($path === '' || !isset($posted_fields[$path]) || !is_array($posted_fields[$path])) {
            continue;
        }

        $posted = $posted_fields[$path];
        $source_type = sanitize_key((string) ($posted['source_type'] ?? ''));
        if ($source_type === '') {
            continue;
        }

        $source_config = normalize_source_config($source_type, $posted['source_config'] ?? []);

        $rows[] = [
            'field_path'    => $path,
            'source_type'   => $source_type,
            'source_config' => $source_config,
        ];
    }

    return $rows;
}

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function normalize_source_config(string $source_type, mixed $raw): array {
    if (!is_array($raw)) {
        return [];
    }

    return match ($source_type) {
        'core' => ['field' => sanitize_key((string) ($raw['field'] ?? ''))],
        'site_info' => ['field' => sanitize_key((string) ($raw['field'] ?? ''))],
        'post_meta' => ['key' => sanitize_text_field((string) ($raw['key'] ?? ''))],
        'acf' => ['name' => sanitize_text_field((string) ($raw['name'] ?? ''))],
        'yoast', 'events_calendar' => ['field' => sanitize_key((string) ($raw['field'] ?? ''))],
        'static' => ['value' => wp_unslash((string) ($raw['value'] ?? ''))],
        'manual_post' => ['field_path' => sanitize_text_field((string) ($raw['field_path'] ?? ''))],
        'manual_block' => [
            'block_name' => sanitize_text_field((string) ($raw['block_name'] ?? '')),
            'field_path' => sanitize_text_field((string) ($raw['field_path'] ?? '')),
        ],
        'taxonomy' => [
            'taxonomy' => sanitize_key((string) ($raw['taxonomy'] ?? '')),
            'return'   => sanitize_key((string) ($raw['return'] ?? 'names')),
        ],
        'block_attr' => [
            'block_name' => sanitize_text_field((string) ($raw['block_name'] ?? '')),
            'attr'       => sanitize_text_field((string) ($raw['attr'] ?? '')),
        ],
        'block_content' => [
            'block_name' => sanitize_text_field((string) ($raw['block_name'] ?? '')),
            'mode'       => sanitize_key((string) ($raw['mode'] ?? 'faq_from_inner_blocks')),
        ],
        'callback' => ['slug' => sanitize_key((string) ($raw['slug'] ?? ''))],
        default => [],
    };
}

/**
 * @param array<int, array<string, mixed>> $field_rows
 */
function validate_callback_slugs(array $field_rows): ?string {
    $allowed = \NobleSchema\plugin()->resolvers()->callback_slugs();

    foreach ($field_rows as $row) {
        if (($row['source_type'] ?? '') !== 'callback') {
            continue;
        }

        $slug = (string) ($row['source_config']['slug'] ?? '');
        if ($slug === '') {
            continue;
        }

        if (!in_array($slug, $allowed, true)) {
            return sprintf(
                /* translators: %s: callback slug */
                __('Invalid callback slug: %s', 'noble-schema'),
                $slug
            );
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $template_def
 * @param array<int, array<string, mixed>> $field_rows
 * @param array<int, array<string, string|null>> $target_rows
 */
function enqueue_regen_after_save(
    int $config_id,
    string $status,
    bool $was_active,
    ?object $previous_config = null,
    array $previous_targets = []
): void {
    if ($config_id <= 0) {
        return;
    }

    if ($status === 'active' || ($was_active && $status !== 'active')) {
        $config = (new Config_Repository())->find($config_id);
        if (!$config) {
            return;
        }

        $new_targets = (new Target_Repository())->for_config($config_id);
        (new Regen_Queue())->enqueue_for_config_targets(
            $config,
            $new_targets,
            $previous_config,
            $previous_targets
        );
    }
}

/**
 * Required fields must have a source selected and a complete source config.
 * An unselected source is treated as empty.
 *
 * @param array<string, mixed> $template_def
 * @param array<int, array<string, mixed>> $field_rows
 */
function validate_required_source_configs(array $template_def, array $field_rows): ?string {
    $mapped = [];
    foreach ($field_rows as $row) {
        $mapped[(string) ($row['field_path'] ?? '')] = $row;
    }

    foreach ($template_def['fields'] as $field_def) {
        if (empty($field_def['required'])) {
            continue;
        }

        $path = (string) ($field_def['path'] ?? '');
        if ($path === '') {
            continue;
        }

        if (!isset($mapped[$path])) {
            return sprintf(
                /* translators: %s: field label */
                __('Required field “%s” needs a source selected.', 'noble-schema'),
                (string) ($field_def['label'] ?? $path)
            );
        }

        $row = $mapped[$path];
        if (source_config_complete((string) ($row['source_type'] ?? ''), (array) ($row['source_config'] ?? []))) {
            continue;
        }

        return sprintf(
            /* translators: %s: field label */
            __('Required field “%s” needs a complete source configuration (empty values are not allowed).', 'noble-schema'),
            (string) ($field_def['label'] ?? $path)
        );
    }

    return null;
}

/**
 * @param array<string, mixed> $template_def
 * @param array<int, array<string, mixed>> $field_rows
 * @param array<int, array<string, string|null>> $target_rows
 */
function validate_enable(
    array $template_def,
    array $field_rows,
    string $placement,
    array $target_rows
): ?string {
    if ($placement !== 'global' && $placement !== 'sitewide') {
        $has_placement = has_rule_type($target_rows, 'post_type')
            || has_rule_type($target_rows, 'page_template')
            || has_rule_type($target_rows, 'archive')
            || has_rule_type($target_rows, 'specialty')
            || has_rule_type($target_rows, 'post_id');
        if (!$has_placement) {
            return __('Enabling requires at least one placement target (post type, page template, archive, specialty page, or post).', 'noble-schema');
        }
    }

    if ($placement === 'sitewide') {
        $blocked = \NobleSchema\Targeting\Sitewide_Sources::blocked_in_maps($field_rows);
        if ($blocked !== []) {
            return sprintf(
                /* translators: %s: comma-separated source type slugs */
                __('Sitewide configs can only use site info or static field sources. Switch to All pages for page-specific sources (%s).', 'noble-schema'),
                implode(', ', $blocked)
            );
        }
    }

    $mapped = [];
    foreach ($field_rows as $row) {
        $mapped[$row['field_path']] = $row;
    }

    foreach ($template_def['fields'] as $field_def) {
        if (empty($field_def['required'])) {
            continue;
        }

        $path = (string) ($field_def['path'] ?? '');
        if ($path === '' || !isset($mapped[$path])) {
            return sprintf(
                /* translators: %s: field label */
                __('Enabling requires mapping for required field: %s', 'noble-schema'),
                (string) ($field_def['label'] ?? $path)
            );
        }

        $row = $mapped[$path];
        if (!source_config_complete((string) $row['source_type'], (array) $row['source_config'])) {
            return sprintf(
                /* translators: %s: field label */
                __('Enabling requires a complete source configuration for: %s', 'noble-schema'),
                (string) ($field_def['label'] ?? $path)
            );
        }

        if (($row['source_type'] ?? '') === 'callback') {
            $slug = (string) ($row['source_config']['slug'] ?? '');
            $allowed = \NobleSchema\plugin()->resolvers()->callback_slugs();
            if ($slug === '' || !in_array($slug, $allowed, true)) {
                return sprintf(
                    /* translators: %s: field label */
                    __('Enabling requires a registered callback for: %s', 'noble-schema'),
                    (string) ($field_def['label'] ?? $path)
                );
            }
        }
    }

    return null;
}

/**
 * @param array<int, array<string, string|null>> $target_rows
 */
function has_rule_type(array $target_rows, string $rule_type): bool {
    foreach ($target_rows as $row) {
        if (($row['rule_type'] ?? '') === $rule_type) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $config
 */
function source_config_complete(string $source_type, array $config): bool {
    return match ($source_type) {
        'core' => ($config['field'] ?? '') !== '',
        'site_info' => ($config['field'] ?? '') !== '',
        'post_meta' => ($config['key'] ?? '') !== '',
        'acf' => ($config['name'] ?? '') !== '',
        'yoast', 'events_calendar' => ($config['field'] ?? '') !== '',
        'static' => trim((string) ($config['value'] ?? '')) !== '',
        'manual_post' => ($config['field_path'] ?? '') !== '',
        'manual_block' => ($config['block_name'] ?? '') !== '' && ($config['field_path'] ?? '') !== '',
        'taxonomy' => ($config['taxonomy'] ?? '') !== '',
        'block_attr' => ($config['block_name'] ?? '') !== '' && ($config['attr'] ?? '') !== '',
        'block_content' => ($config['block_name'] ?? '') !== '',
        'callback' => ($config['slug'] ?? '') !== '',
        default => false,
    };
}

/**
 * @param array<string, mixed> $template_def
 * @param array<int, array<string, string|null>> $target_rows
 * @param array<int, array<string, mixed>> $field_rows
 */
function persist_config(
    int $config_id,
    string $name,
    string $template,
    array $template_def,
    string $placement,
    string $filter_logic,
    string $status,
    bool $strict_validation,
    array $target_rows,
    array $field_rows
): int {
    $repo = new Config_Repository();

    $data = [
        'name'               => $name,
        'template_slug'      => $template,
        'schema_type'        => (string) $template_def['schema_type'],
        'placement'          => $placement,
        'filter_logic'       => $filter_logic === 'or' ? 'or' : 'and',
        'status'             => $status,
        'strict_validation'  => $strict_validation ? 1 : 0,
    ];

    if ($config_id > 0 && $repo->find($config_id)) {
        $repo->update($config_id, $data);
        $id = $config_id;
    } else {
        $id = $repo->insert($data);
    }

    if ($id <= 0) {
        return 0;
    }

    (new Target_Repository())->replace($id, $target_rows);
    (new Field_Repository())->replace($id, $field_rows);

    return $id;
}
