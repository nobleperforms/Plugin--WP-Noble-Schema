<?php

if (!defined('ABSPATH')) {
    exit;
}

$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
?>
<div class="wrap ns-tools-wrap" id="ns-sitewide-analytics">
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
        <h1><?php esc_html_e('Sitewide Analytics', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">
    <p class="description">
        <?php esc_html_e('Measure page-specific schema coverage across the site, triage gaps and scan for issues.', 'noble-schema'); ?>
    </p>

    <div class="ns-tools-card ns-site-coverage__panel" id="ns-sitewide-analytics-panel">
        <div id="ns-viewer-site-status" class="ns-schema-viewer__status" hidden></div>

        <div class="ns-site-coverage__loading" id="ns-viewer-site-loading" role="status" hidden>
            <span class="ns-site-coverage__spinner" aria-hidden="true"></span>
            <span><?php esc_html_e('Loading…', 'noble-schema'); ?></span>
        </div>

        <div class="ns-site-coverage__score" id="ns-viewer-site-score" hidden>
            <div class="ns-site-coverage__score-main">
                <div class="ns-site-coverage__ring" id="ns-viewer-site-ring" aria-hidden="true">
                    <svg viewBox="0 0 42 42" focusable="false">
                        <circle class="ns-site-coverage__ring-track" cx="21" cy="21" r="18"></circle>
                        <circle
                            class="ns-site-coverage__ring-value"
                            id="ns-viewer-site-ring-value"
                            cx="21"
                            cy="21"
                            r="18"
                            stroke-dasharray="0 113.1"
                        ></circle>
                    </svg>
                </div>
                <div class="ns-site-coverage__score-text">
                    <div class="ns-site-coverage__percent" id="ns-viewer-site-percent">0%</div>
                    <div class="ns-site-coverage__label"><?php esc_html_e('Page-specific coverage', 'noble-schema'); ?></div>
                    <div class="ns-site-coverage__sub" id="ns-viewer-site-of"></div>
                </div>
            </div>
            <div class="ns-site-coverage__aside">
                <div class="ns-site-coverage__callout" id="ns-viewer-site-callout-wrap">
                    <strong class="ns-site-coverage__callout-label"><?php esc_html_e('Sitewide scripts:', 'noble-schema'); ?></strong>
                    <span class="ns-site-coverage__callout-meta">
                        <span class="ns-site-coverage__callout-body" id="ns-viewer-site-callout"></span>
                        <button
                            type="button"
                            class="ns-site-coverage__callout-help"
                            id="ns-viewer-site-callout-help"
                            aria-label="<?php esc_attr_e('Explain sitewide scripts', 'noble-schema'); ?>"
                            title="<?php esc_attr_e('Explain sitewide scripts', 'noble-schema'); ?>"
                        >
                            <span class="dashicons dashicons-info" aria-hidden="true"></span>
                        </button>
                    </span>
                </div>
                <div class="ns-site-coverage__tiers-row" id="ns-viewer-site-tiers-row" hidden>
                    <div class="ns-site-coverage__tiers" id="ns-viewer-site-tiers"></div>
                    <button
                        type="button"
                        class="ns-site-coverage__tiers-help"
                        id="ns-viewer-site-tiers-help"
                        aria-label="<?php esc_attr_e('Explain coverage tiers', 'noble-schema'); ?>"
                        title="<?php esc_attr_e('Explain coverage tiers', 'noble-schema'); ?>"
                    >
                        <span class="dashicons dashicons-info" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>

        <div class="ns-site-coverage__actions" id="ns-viewer-site-actions" hidden>
            <div class="ns-site-coverage__toolbar">
                <button type="button" class="button button-primary" id="ns-viewer-site-scan">
                    <?php esc_html_e('Scan for issues', 'noble-schema'); ?>
                </button>
                <button type="button" class="button" id="ns-viewer-site-scan-cancel" hidden>
                    <?php esc_html_e('Cancel scan', 'noble-schema'); ?>
                </button>
                <span class="ns-site-coverage__scan-meta" id="ns-viewer-site-scan-meta"></span>
            </div>

            <div class="ns-site-coverage__filters" id="ns-viewer-site-filters" hidden>
                <label>
                    <span class="screen-reader-text"><?php esc_html_e('Filter by tier', 'noble-schema'); ?></span>
                    <select id="ns-viewer-site-filter-tier">
                        <option value=""><?php esc_html_e('All tiers', 'noble-schema'); ?></option>
                    </select>
                </label>
                <label>
                    <span class="screen-reader-text"><?php esc_html_e('Filter by post type', 'noble-schema'); ?></span>
                    <select id="ns-viewer-site-filter-type">
                        <option value=""><?php esc_html_e('All post types', 'noble-schema'); ?></option>
                    </select>
                </label>
                <label class="ns-site-coverage__invalid ns-toggle" for="ns-viewer-site-filter-invalid">
                    <span class="ns-toggle__control">
                        <input type="checkbox" id="ns-viewer-site-filter-invalid" value="1">
                        <span class="ns-toggle__ui" aria-hidden="true"></span>
                    </span>
                    <span class="ns-toggle__label"><?php esc_html_e('Issues only', 'noble-schema'); ?></span>
                </label>
                <label id="ns-viewer-site-filter-reason-wrap" hidden>
                    <span class="screen-reader-text"><?php esc_html_e('Filter by issue type', 'noble-schema'); ?></span>
                    <select id="ns-viewer-site-filter-reason">
                        <option value=""><?php esc_html_e('All issue types', 'noble-schema'); ?></option>
                    </select>
                </label>
            </div>
        </div>

        <div class="ns-site-coverage__progress" id="ns-viewer-site-progress" hidden>
            <div
                class="ns-site-coverage__progress-bar"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="0"
                id="ns-viewer-site-progress-bar"
            >
                <div class="ns-site-coverage__progress-fill" id="ns-viewer-site-progress-fill"></div>
            </div>
            <p class="ns-site-coverage__progress-note" id="ns-viewer-site-progress-note"></p>
        </div>

        <div class="ns-site-coverage__table-wrap" id="ns-viewer-site-table-wrap" hidden>
            <table class="widefat striped ns-site-coverage__table">
                <colgroup>
                    <col class="ns-site-coverage__col-title">
                    <col class="ns-site-coverage__col-type">
                    <col class="ns-site-coverage__col-tier">
                    <col class="ns-site-coverage__col-types">
                    <col class="ns-site-coverage__col-issues">
                    <col class="ns-site-coverage__col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'noble-schema'); ?></th>
                        <th><?php esc_html_e('Type', 'noble-schema'); ?></th>
                        <th><?php esc_html_e('Tier', 'noble-schema'); ?></th>
                        <th><?php esc_html_e('Schema types', 'noble-schema'); ?></th>
                        <th><?php esc_html_e('Issues', 'noble-schema'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'noble-schema'); ?></span></th>
                    </tr>
                </thead>
                <tbody id="ns-viewer-site-tbody"></tbody>
            </table>
            <div class="ns-site-coverage__pager" id="ns-viewer-site-pager"></div>
        </div>

        <details class="ns-site-coverage__archives" id="ns-viewer-site-archives" hidden>
            <summary class="ns-site-coverage__section-title">
                <?php esc_html_e('Archives', 'noble-schema'); ?>
                <span class="ns-site-coverage__archives-count" id="ns-viewer-site-archives-count"></span>
            </summary>
            <div id="ns-viewer-site-archives-body"></div>
        </details>
    </div>
</div>
