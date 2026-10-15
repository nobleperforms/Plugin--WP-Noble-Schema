<?php

if (!defined('ABSPATH')) {
    exit;
}

$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
$add_config_url = \NobleSchema\Admin\Menu::page_url(['action' => 'add']);
$factory = new \NobleSchema\SchemaOrg\Template_Factory(dirname(__DIR__, 2) . '/templates');
$custom_writable = $factory->custom_writable();
?>
<div
    class="wrap ns-tools-wrap"
    id="ns-schema-storefront"
    data-add-config-url="<?php echo esc_url($add_config_url); ?>"
    data-custom-writable="<?php echo $custom_writable ? '1' : '0'; ?>"
>
    <div class="ns-wizard__heading">
        <a
            href="<?php echo esc_url($hub_url); ?>"
            class="ns-wizard__heading-back"
            aria-label="<?php esc_attr_e('Back to Schema Tools', 'noble-schema'); ?>"
        >
            <svg class="ns-wizard__nav-icon" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                <path d="M12 5 7 10l5 5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <h1><?php esc_html_e('Schema Storefront', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">
    <?php if (!$custom_writable) : ?>
        <div class="notice notice-warning inline ns-storefront__writable-notice">
            <p>
                <?php esc_html_e('Custom templates can’t be added or deleted here because the uploads templates directory isn’t writable on this environment. You can still browse schema.org types.', 'noble-schema'); ?>
            </p>
        </div>
    <?php endif; ?>
    <p class="description">
        <?php esc_html_e('Browse schema.org types and add custom templates for the Schema Config wizard.', 'noble-schema'); ?>
    </p>

    <div class="ns-tools-card ns-storefront">
        <div class="ns-storefront__toolbar">
            <p id="ns-storefront-status" class="ns-storefront__status" aria-live="polite">
                <?php esc_html_e('Type not loaded yet.', 'noble-schema'); ?>
            </p>
            <div class="ns-storefront__toolbar-actions">
                <button type="button" class="button" id="ns-storefront-manage">
                    <?php esc_html_e('Manage Custom Templates', 'noble-schema'); ?>
                </button>
                <button type="button" class="button" id="ns-storefront-refresh">
                    <?php esc_html_e('Refresh types', 'noble-schema'); ?>
                </button>
            </div>
        </div>

        <div class="ns-storefront__layout">
            <div class="ns-storefront__browse">
                <label class="ns-storefront__label" for="ns-storefront-search">
                    <?php esc_html_e('Search types', 'noble-schema'); ?>
                </label>
                <input
                    type="search"
                    id="ns-storefront-search"
                    class="regular-text ns-storefront__search"
                    placeholder="<?php esc_attr_e('Search schema.org types…', 'noble-schema'); ?>"
                    autocomplete="off"
                    disabled
                >
                <ul id="ns-storefront-type-list" class="ns-storefront__type-list" role="listbox" aria-label="<?php esc_attr_e('Schema.org types', 'noble-schema'); ?>"></ul>
            </div>

            <div class="ns-storefront__pack" id="ns-storefront-pack" hidden>
                <div id="ns-storefront-type-header" class="ns-storefront__type-header"></div>

                <div class="ns-field-row">
                    <div class="ns-field-row__label">
                        <label for="ns-storefront-label"><?php esc_html_e('Template label', 'noble-schema'); ?></label>
                    </div>
                    <div class="ns-field-row__control">
                        <input type="text" id="ns-storefront-label" class="regular-text" autocomplete="off"<?php disabled(!$custom_writable); ?>>
                    </div>
                </div>

                <div class="ns-field-row">
                    <div class="ns-field-row__label">
                        <label for="ns-storefront-slug"><?php esc_html_e('Template slug', 'noble-schema'); ?></label>
                    </div>
                    <div class="ns-field-row__control">
                        <input type="text" id="ns-storefront-slug" class="regular-text" autocomplete="off" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"<?php disabled(!$custom_writable); ?>>
                        <p class="description"><?php
                            printf(
                                /* translators: %s: uploads path label */
                                esc_html__('Saved as %s/{slug}.json', 'noble-schema'),
                                esc_html(\NobleSchema\Support\Paths::custom_templates_dir_label())
                            );
                        ?></p>
                    </div>
                </div>

                <div class="ns-storefront__props-head">
                    <h3 class="ns-storefront__props-title"><?php esc_html_e('Properties', 'noble-schema'); ?></h3>
                    <div class="ns-storefront__props-actions">
                        <button type="button" class="button-link" id="ns-storefront-select-all"<?php disabled(!$custom_writable); ?>><?php esc_html_e('Select all', 'noble-schema'); ?></button>
                        <button type="button" class="button-link" id="ns-storefront-select-none"<?php disabled(!$custom_writable); ?>><?php esc_html_e('Select none', 'noble-schema'); ?></button>
                    </div>
                </div>
                <div id="ns-storefront-properties" class="ns-storefront__properties"></div>

                <p class="submit ns-storefront__submit">
                    <button
                        type="button"
                        class="button button-primary"
                        id="ns-storefront-save"
                        <?php disabled(!$custom_writable); ?>
                        <?php if (!$custom_writable) : ?>
                            title="<?php esc_attr_e('The uploads templates directory is not writable on this environment.', 'noble-schema'); ?>"
                        <?php endif; ?>
                    >
                        <?php esc_html_e('Save template', 'noble-schema'); ?>
                    </button>
                </p>
            </div>

            <div class="ns-storefront__empty" id="ns-storefront-empty">
                <p><?php esc_html_e('Select a schema.org type to choose properties and save a template.', 'noble-schema'); ?></p>
            </div>
        </div>
    </div>
</div>

<div
    id="ns-storefront-manage-modal"
    class="ns-storefront-manage-modal"
    hidden
    role="dialog"
    aria-modal="true"
    aria-labelledby="ns-storefront-manage-title"
>
    <div class="ns-storefront-manage-modal__backdrop" data-ns-storefront-manage-dismiss></div>
    <div class="ns-storefront-manage-modal__panel">
        <div class="ns-storefront-manage-modal__header">
            <h2 id="ns-storefront-manage-title" class="ns-storefront-manage-modal__title">
                <?php esc_html_e('Custom templates', 'noble-schema'); ?>
            </h2>
            <button
                type="button"
                class="ns-storefront-manage-modal__close"
                data-ns-storefront-manage-dismiss
                aria-label="<?php esc_attr_e('Close', 'noble-schema'); ?>"
            >&times;</button>
        </div>
        <div id="ns-storefront-manage-body" class="ns-storefront-manage-modal__body" aria-live="polite">
            <p class="ns-storefront-manage-modal__loading"><?php esc_html_e('Loading…', 'noble-schema'); ?></p>
        </div>
        <div class="ns-storefront-manage-modal__actions">
            <button type="button" class="button button-primary" data-ns-storefront-manage-dismiss>
                <?php esc_html_e('Close', 'noble-schema'); ?>
            </button>
        </div>
    </div>
</div>
