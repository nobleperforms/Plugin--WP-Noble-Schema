<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $data */
$data = $data ?? [];
extract($data, EXTR_SKIP);

$list_url = \NobleSchema\Admin\Menu::page_url();
$enabled  = $config && (string) ($config->status ?? '') === 'active';
?>
<div class="wrap ns-wizard-wrap">
    <div class="ns-wizard__heading">
        <a
            href="<?php echo esc_url($list_url); ?>"
            class="ns-wizard__heading-back"
            aria-label="<?php esc_attr_e('Back to Schema Config list', 'noble-schema'); ?>"
        >
            <svg class="ns-wizard__nav-icon" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                <path d="M12 5 7 10l5 5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <h1>
            <?php echo $is_edit
                ? esc_html__('Edit Schema Config', 'noble-schema')
                : esc_html__('Add Schema Config', 'noble-schema'); ?>
        </h1>
    </div>
    <hr class="wp-header-end">

    <?php if (!empty($wpml_notice)) : ?>
        <?php /* Fallback for when the blocking dialog cannot run. */ ?>
        <div class="notice notice-warning inline ns-wizard__language-notice">
            <p><?php echo esc_html($wpml_notice['message']); ?></p>
            <p>
                <a href="<?php echo esc_url($wpml_notice['switchUrl']); ?>" class="button button-primary">
                    <?php echo esc_html($wpml_notice['button']); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <form
        id="ns-wizard-form"
        class="ns-wizard"
        method="post"
        action="<?php echo esc_url($save_url); ?>"
        novalidate
    >
        <input type="hidden" name="action" value="ns_save_config">
        <input type="hidden" name="config_id" value="<?php echo esc_attr((string) $config_id); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

        <nav class="ns-wizard__progress" aria-label="<?php esc_attr_e('Wizard progress', 'noble-schema'); ?>">
            <ol class="ns-wizard__steps" role="list">
                <li class="ns-wizard__step is-active" data-step="1">
                    <button type="button" class="ns-wizard__step-btn" aria-current="step">
                        <span class="ns-wizard__step-num" aria-hidden="true">
                            <span class="ns-wizard__step-num-text">1</span>
                            <span class="ns-wizard__step-status" hidden></span>
                        </span>
                        <span class="ns-wizard__step-label"><?php esc_html_e('Schema', 'noble-schema'); ?></span>
                    </button>
                </li>
                <li class="ns-wizard__step" data-step="2">
                    <button type="button" class="ns-wizard__step-btn" disabled>
                        <span class="ns-wizard__step-num" aria-hidden="true">
                            <span class="ns-wizard__step-num-text">2</span>
                            <span class="ns-wizard__step-status" hidden></span>
                        </span>
                        <span class="ns-wizard__step-label"><?php esc_html_e('Targeting', 'noble-schema'); ?></span>
                    </button>
                </li>
                <li class="ns-wizard__step" data-step="3">
                    <button type="button" class="ns-wizard__step-btn" disabled>
                        <span class="ns-wizard__step-num" aria-hidden="true">
                            <span class="ns-wizard__step-num-text">3</span>
                            <span class="ns-wizard__step-status" hidden></span>
                        </span>
                        <span class="ns-wizard__step-label"><?php esc_html_e('Fields', 'noble-schema'); ?></span>
                    </button>
                </li>
            </ol>
            <?php
            $show_regen = $is_edit && $config && ($config->status ?? '') === 'active';
            $show_clear_archive = $is_edit && !empty($has_archive_cache);
            if ($show_regen || $show_clear_archive) :
                ?>
                <p class="ns-regen-actions">
                    <?php if ($show_regen) : ?>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=ns_regen_config&config_id=' . (int) $config_id),
                                'ns_regen_config_' . (int) $config_id
                            )); ?>"
                            class="button"
                        >
                            <?php esc_html_e('Regenerate affected posts', 'noble-schema'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($show_clear_archive) : ?>
                        <button type="button" class="button" id="ns-clear-archive-cache">
                            <?php esc_html_e('Clear archive cache', 'noble-schema'); ?>
                        </button>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </nav>

        <div class="ns-wizard__panels">
            <?php include __DIR__ . '/partials/step-schema.php'; ?>
            <?php include __DIR__ . '/partials/step-targeting.php'; ?>
            <?php include __DIR__ . '/partials/step-fields.php'; ?>
        </div>

        <div class="ns-wizard__footer">
            <div class="ns-wizard__footer-left">
                <a href="<?php echo esc_url($list_url); ?>" class="button ns-wizard__cancel">
                    <?php esc_html_e('Cancel', 'noble-schema'); ?>
                </a>
            </div>
            <div class="ns-wizard__footer-center">
                <button type="button" class="button ns-wizard__back" disabled aria-label="<?php esc_attr_e('Back', 'noble-schema'); ?>">
                    <svg class="ns-wizard__nav-icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                        <path d="M10.5 3.5 6 8l4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button type="button" class="button button-primary ns-wizard__continue" aria-label="<?php esc_attr_e('Continue', 'noble-schema'); ?>">
                    <svg class="ns-wizard__nav-icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                        <path d="M5.5 3.5 10 8l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            <div class="ns-wizard__footer-right">
                <label class="ns-wizard__enabled">
                    <span class="ns-wizard__enabled-label"><?php esc_html_e('Enabled', 'noble-schema'); ?></span>
                    <span class="ns-wizard__switch">
                        <input
                            type="checkbox"
                            name="enabled"
                            value="1"
                            id="ns-config-enabled"
                            <?php checked($enabled); ?>
                        >
                        <span class="ns-wizard__switch-ui" aria-hidden="true"></span>
                    </span>
                </label>
                <button type="submit" class="button button-primary ns-wizard__save">
                    <?php
                    echo $is_edit
                        ? esc_html__('Save', 'noble-schema')
                        : esc_html__('Create', 'noble-schema');
                    ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script type="application/json" id="ns-wizard-data"><?php
    echo wp_json_encode([
        'templates'         => $templates,
        'fieldMaps'         => $field_maps,
        'placementTargets'  => $placement_targets,
        'coreFields'        => $core_fields,
        'siteInfoFields'    => $site_info_fields ?? [],
        'yoastFields'       => $yoast_fields ?? [],
        'eventsCalendarFields' => $events_calendar_fields ?? [],
        'sourceTypes'       => $source_types,
        'postTypeLabels'    => array_map(static fn ($pt) => $pt->labels->singular_name, $post_types),
        'pageTemplates'     => $page_templates,
        'blockTypes'        => $block_types,
        'blockAttributes'   => $block_attributes ?? [],
        'archivePages'      => $archive_pages ?? [],
        'selectedPostOptions'=> $selected_post_options ?? [],
        'taxonomyLabels'    => array_map(static fn ($tx) => $tx->labels->singular_name, $taxonomies),
        'wpmlLanguages'     => $wpml_languages ?? [],
        'wpmlNotice'        => $wpml_notice ?? null,
        'callbackSlugs'     => $callback_slugs,
        'selectedTemplate'  => $config ? (string) $config->template_slug : '',
        'selectedPlacement' => match ($config ? (string) $config->placement : 'global') {
            'sitewide' => 'sitewide',
            'global' => 'global',
            default => 'targeted',
        },
        'configName'        => $config ? (string) $config->name : '',
        'configId'          => (int) $config_id,
        'enabled'           => $enabled,
    ]);
?></script>
