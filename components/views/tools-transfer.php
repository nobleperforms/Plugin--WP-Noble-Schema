<?php

if (!defined('ABSPATH')) {
    exit;
}

$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
$configs = (new \NobleSchema\Db\Config_Repository())->all(['orderby' => 'name', 'order' => 'ASC']);
?>
<div class="wrap ns-tools-wrap" id="ns-schema-transfer">
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
        <h1><?php esc_html_e('Export / Import', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">
    <p class="description">
        <?php esc_html_e('Export or import schema configs, plugin settings and/or custom templates.', 'noble-schema'); ?>
    </p>

    <div class="ns-transfer-tabs" role="tablist" aria-label="<?php esc_attr_e('Export or Import', 'noble-schema'); ?>">
        <button type="button" class="button ns-transfer-tabs__btn is-active" role="tab" aria-selected="true" data-transfer-tab="export">
            <?php esc_html_e('Export', 'noble-schema'); ?>
        </button>
        <button type="button" class="button ns-transfer-tabs__btn" role="tab" aria-selected="false" data-transfer-tab="import">
            <?php esc_html_e('Import', 'noble-schema'); ?>
        </button>
    </div>

    <div class="ns-tools-card ns-transfer-panel" id="ns-transfer-export" data-transfer-panel="export" role="tabpanel">
        <h2 class="ns-tools-card__title"><?php esc_html_e('Export', 'noble-schema'); ?></h2>
        <p class="description">
            <?php esc_html_e('Select what you’d like included in your export file:', 'noble-schema'); ?>
        </p>

        <fieldset class="ns-transfer-scope">
            <legend class="screen-reader-text"><?php esc_html_e('Export scope', 'noble-schema'); ?></legend>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_transfer_scope" value="full" checked>
                <span>
                    <strong><?php esc_html_e('Everything', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Export all plugin settings, custom templates and schema configs.', 'noble-schema'); ?></span>
                </span>
            </label>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_transfer_scope" value="settings">
                <span>
                    <strong><?php esc_html_e('Plugin settings', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Export all plugin settings (including custom templates installed).', 'noble-schema'); ?></span>
                </span>
            </label>
            <label class="ns-transfer-scope__option">
                <input type="radio" name="ns_transfer_scope" value="config">
                <span>
                    <strong><?php esc_html_e('Single schema config', 'noble-schema'); ?></strong>
                    <span class="description"><?php esc_html_e('Export a single schema config with its targeting rules and field mappings.', 'noble-schema'); ?></span>
                </span>
            </label>
        </fieldset>

        <div class="ns-transfer-config-pick" id="ns-transfer-config-pick" hidden>
            <label for="ns-transfer-config-id"><strong><?php esc_html_e('Schema config', 'noble-schema'); ?></strong></label>
            <select id="ns-transfer-config-id" class="regular-text">
                <option value=""><?php esc_html_e('Select a config…', 'noble-schema'); ?></option>
                <?php foreach ($configs as $config) : ?>
                    <option value="<?php echo esc_attr((string) $config->id); ?>">
                        <?php echo esc_html((string) $config->name); ?>
                        (#<?php echo (int) $config->id; ?><?php echo (string) ($config->status ?? '') === 'active' ? ', ' . esc_html__('active', 'noble-schema') : ''; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <p class="submit">
            <button type="button" class="button button-primary" id="ns-transfer-export-btn">
                <?php esc_html_e('Download export', 'noble-schema'); ?>
            </button>
        </p>
        <div id="ns-transfer-export-status" class="ns-transfer-status" hidden></div>
    </div>

    <div class="ns-tools-card ns-transfer-panel" id="ns-transfer-import" data-transfer-panel="import" role="tabpanel" hidden>
        <h2 class="ns-tools-card__title"><?php esc_html_e('Import', 'noble-schema'); ?></h2>
        <p class="description">
            <?php esc_html_e('Upload a Noble Schema export file. You’ll review what will be imported and resolve conflicts before completing an import.', 'noble-schema'); ?>
        </p>

        <div id="ns-transfer-import-step-upload" class="ns-transfer-import-step">
            <label class="ns-transfer-file">
                <span class="ns-transfer-file__label"><?php esc_html_e('Export file (.json)', 'noble-schema'); ?></span>
                <input type="file" id="ns-transfer-file" accept="application/json,.json">
            </label>
            <p class="submit">
                <button type="button" class="button button-primary" id="ns-transfer-preview-btn" disabled>
                    <?php esc_html_e('Review import', 'noble-schema'); ?>
                </button>
            </p>
        </div>

        <div id="ns-transfer-import-step-preview" class="ns-transfer-import-step" hidden>
            <div id="ns-transfer-preview" class="ns-transfer-preview"></div>
            <p class="submit ns-transfer-import-actions">
                <button type="button" class="button" id="ns-transfer-import-back">
                    <?php esc_html_e('Back', 'noble-schema'); ?>
                </button>
                <button type="button" class="button button-primary" id="ns-transfer-apply-btn">
                    <?php esc_html_e('Confirm import', 'noble-schema'); ?>
                </button>
            </p>
        </div>

        <div id="ns-transfer-import-status" class="ns-transfer-status" hidden></div>
    </div>
</div>
