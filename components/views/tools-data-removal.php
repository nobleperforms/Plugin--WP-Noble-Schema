<?php

if (!defined('ABSPATH')) {
    exit;
}

$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
$factory = new \NobleSchema\SchemaOrg\Template_Factory(dirname(__DIR__, 2) . '/templates');
$custom_writable = $factory->custom_writable();
$plugins_writable = \NobleSchema\Offboard\Offboard::plugins_dir_writable();
?>
<div
    class="wrap ns-tools-wrap"
    id="ns-schema-data-removal"
    data-custom-writable="<?php echo $custom_writable ? '1' : '0'; ?>"
    data-plugins-writable="<?php echo $plugins_writable ? '1' : '0'; ?>"
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
        <h1><?php esc_html_e('Data Removal', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">
    <?php if (!$custom_writable) : ?>
        <div class="notice notice-warning inline ns-storefront__writable-notice">
            <p>
                <?php esc_html_e('Custom templates can’t be deleted here because the uploads templates directory isn’t writable on this environment. Other data removal options still work.', 'noble-schema'); ?>
            </p>
        </div>
    <?php endif; ?>
    <p class="description">
        <?php esc_html_e('Permanently delete plugin settings, custom templates, configs or generated snapshots.', 'noble-schema'); ?>
    </p>

    <div class="ns-tools-card">
        <h2 class="ns-tools-card__title"><?php esc_html_e('Select data for removal', 'noble-schema'); ?></h2>
        <p class="description">
            <?php esc_html_e('Select an option for data removal. Performing any of the below actions does not disable or uninstall the plugin.', 'noble-schema'); ?>
        </p>

        <fieldset class="ns-transfer-scope">
            <legend class="screen-reader-text"><?php esc_html_e('Data removal scope', 'noble-schema'); ?></legend>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_data_removal_scope" value="everything" checked>
                <span>
                    <strong><?php esc_html_e('Everything', 'noble-schema'); ?></strong>
                    <span class="description">
                        <?php if ($custom_writable) : ?>
                            <?php esc_html_e('Delete all plugin settings, custom templates, schema configs, snapshots/caches and system logs.', 'noble-schema'); ?>
                        <?php else : ?>
                            <?php esc_html_e('Delete all plugin settings, schema configs, snapshots/caches and system logs. Custom templates are skipped on this environment.', 'noble-schema'); ?>
                        <?php endif; ?>
                    </span>
                </span>
            </label>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_data_removal_scope" value="settings">
                <span>
                    <strong><?php esc_html_e('Plugin settings', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Delete all global plugin settings.', 'noble-schema'); ?></span>
                </span>
            </label>
            <label class="ns-transfer-scope__option<?php echo $custom_writable ? '' : ' is-disabled'; ?>">
                <input
                    type="radio"
                    name="ns_data_removal_scope"
                    value="templates"
                    <?php disabled(!$custom_writable); ?>
                    <?php if (!$custom_writable) : ?>
                        title="<?php esc_attr_e('The uploads templates directory is not writable on this environment.', 'noble-schema'); ?>"
                    <?php endif; ?>
                >
                <span>
                    <strong><?php esc_html_e('Custom templates', 'noble-schema'); ?></strong>
                    <span class="description">
                        <?php if ($custom_writable) : ?>
                            <?php esc_html_e('Delete all custom schema types located in the WordPress uploads directory.', 'noble-schema'); ?>
                        <?php else : ?>
                            <?php esc_html_e('Unavailable — the uploads templates directory isn’t writable on this environment.', 'noble-schema'); ?>
                        <?php endif; ?>
                    </span>
                </span>
            </label>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_data_removal_scope" value="configs">
                <span>
                    <strong><?php esc_html_e('All schema configs', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Delete all configs, targeting, field maps, manual values and snapshots/caches.', 'noble-schema'); ?></span>
                </span>
            </label>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_data_removal_scope" value="snapshots">
                <span>
                    <strong><?php esc_html_e('Generated snapshots / caches', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Delete all stored JSON-LD snapshots and runtime caches.', 'noble-schema'); ?></span>
                </span>
            </label>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_data_removal_scope" value="logs">
                <span>
                    <strong><?php esc_html_e('System logs', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Delete all stored system log entries.', 'noble-schema'); ?></span>
                </span>
            </label>
        </fieldset>

        <p class="submit ns-data-removal__actions">
            <button type="button" class="button button-primary ns-data-removal__submit is-destructive" id="ns-data-removal-btn">
                <?php esc_html_e('Delete selected data', 'noble-schema'); ?>
            </button>
            <button
                type="button"
                class="button ns-btn-danger-secondary"
                id="ns-offboard-btn"
                <?php disabled(!$plugins_writable); ?>
                <?php if (!$plugins_writable) : ?>
                    title="<?php esc_attr_e('Requires a writable wp-content/plugins directory.', 'noble-schema'); ?>"
                <?php endif; ?>
            >
                <?php esc_html_e('Perform offboarding', 'noble-schema'); ?>
            </button>
        </p>
        <?php if (!$plugins_writable) : ?>
            <p class="description">
                <?php esc_html_e('Perform offboarding is unavailable because wp-content/plugins is not writable on this environment. Use a mutable deploy (or local) to install Schema Lite.', 'noble-schema'); ?>
            </p>
        <?php endif; ?>
        <div id="ns-data-removal-status" class="ns-transfer-status" hidden></div>
    </div>
</div>
