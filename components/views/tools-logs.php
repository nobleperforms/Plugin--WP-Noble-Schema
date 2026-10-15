<?php

if (!defined('ABSPATH')) {
    exit;
}

$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
$settings_url = \NobleSchema\Admin\Settings_Page::page_url();
$logging_enabled = \NobleSchema\Tools\Log::enabled();
$retention = \NobleSchema\Tools\Log::retention_slug();
$retention_label = \NobleSchema\Tools\Log::retention_choices()[$retention] ?? $retention;
?>
<div
    class="wrap ns-tools-wrap"
    id="ns-schema-logs"
    data-logging-enabled="<?php echo $logging_enabled ? '1' : '0'; ?>"
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
        <h1><?php esc_html_e('Logs', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">

    <?php if (!$logging_enabled) : ?>
        <div class="ns-tools-card ns-logs-empty">
            <p class="ns-empty-state__message">
                <?php esc_html_e('System logging is turned off.', 'noble-schema'); ?>
            </p>
            <p class="ns-empty-state__hint">
                <?php esc_html_e('Enable logging in Schema Settings to record schema changes, imports, template actions and errors.', 'noble-schema'); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($settings_url); ?>">
                    <?php esc_html_e('Open Schema Settings', 'noble-schema'); ?>
                </a>
            </p>
        </div>
    <?php else : ?>
        <p class="description">
            <?php esc_html_e('Browse system logs for schema changes, imports, templates, errors and more.', 'noble-schema'); ?>
        </p>

        <div class="ns-tools-card ns-logs-filters">
            <div class="ns-logs-filters__top">
                <div class="ns-logs-filters__row">
                    <label class="ns-logs-filters__field">
                        <span class="ns-logs-filters__label"><?php esc_html_e('Level', 'noble-schema'); ?></span>
                        <select id="ns-logs-filter-level">
                            <option value=""><?php esc_html_e('All levels', 'noble-schema'); ?></option>
                        </select>
                    </label>
                    <label class="ns-logs-filters__field">
                        <span class="ns-logs-filters__label"><?php esc_html_e('Category', 'noble-schema'); ?></span>
                        <select id="ns-logs-filter-category">
                            <option value=""><?php esc_html_e('All categories', 'noble-schema'); ?></option>
                        </select>
                    </label>
                    <label class="ns-logs-filters__field">
                        <span class="ns-logs-filters__label"><?php esc_html_e('From', 'noble-schema'); ?></span>
                        <input type="date" id="ns-logs-filter-from" autocomplete="off">
                    </label>
                    <label class="ns-logs-filters__field">
                        <span class="ns-logs-filters__label"><?php esc_html_e('To', 'noble-schema'); ?></span>
                        <input type="date" id="ns-logs-filter-to" autocomplete="off">
                    </label>
                </div>
                <div class="ns-logs-live" id="ns-logs-live" data-state="live">
                    <span class="ns-logs-live__dot" aria-hidden="true"></span>
                    <span class="ns-logs-live__label" id="ns-logs-live-label"><?php esc_html_e('Live', 'noble-schema'); ?></span>
                    <button
                        type="button"
                        class="ns-icon-btn ns-logs-live__toggle"
                        id="ns-logs-live-toggle"
                        aria-pressed="false"
                        aria-label="<?php esc_attr_e('Pause', 'noble-schema'); ?>"
                        title="<?php esc_attr_e('Pause', 'noble-schema'); ?>"
                    >
                        <svg class="ns-logs-live__icon ns-logs-live__icon--pause" width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M6 3.75h2.5v12.5H6V3.75Zm5.5 0H14v12.5h-2.5V3.75Z"/>
                        </svg>
                        <svg class="ns-logs-live__icon ns-logs-live__icon--resume" width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M6.5 3.75v12.5L16 10 6.5 3.75Z"/>
                        </svg>
                    </button>
                    <button
                        type="button"
                        class="ns-icon-delete ns-logs-live__purge"
                        id="ns-logs-purge"
                        aria-label="<?php esc_attr_e('Delete all logs', 'noble-schema'); ?>"
                        title="<?php esc_attr_e('Delete all logs', 'noble-schema'); ?>"
                    >
                        <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M6.5 3.5A1.5 1.5 0 0 1 8 2h4a1.5 1.5 0 0 1 1.5 1.5V4H16a1 1 0 1 1 0 2h-.5l-.7 10.1A2 2 0 0 1 12.8 18H7.2a2 2 0 0 1-2-1.9L4.5 6H4a1 1 0 0 1 0-2h2.5V3.5ZM8 4h4V3.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5V4Zm-.4 3.1a1 1 0 0 1 1 .9l.3 7a1 1 0 1 1-2 .1l-.3-7a1 1 0 0 1 1-1Zm4.8 0a1 1 0 0 1 1 1l-.3 7a1 1 0 1 1-2-.1l.3-7a1 1 0 0 1 1-.9Z"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="ns-tools-card">
            <div id="ns-logs-loading" class="ns-logs-list__loading" role="status" aria-live="polite">
                <span class="ns-logs-row__spinner" aria-hidden="true"></span>
                <span><?php esc_html_e('Loading logs…', 'noble-schema'); ?></span>
            </div>
            <div id="ns-logs-list" class="ns-logs-list" aria-live="polite"></div>
            <p id="ns-logs-empty" class="ns-logs-list__empty" hidden>
                <?php esc_html_e('No log entries exist yet.', 'noble-schema'); ?>
            </p>
            <div class="ns-logs-more" id="ns-logs-more" hidden>
                <button type="button" class="button" id="ns-logs-load-more">
                    <?php esc_html_e('Load more', 'noble-schema'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>
