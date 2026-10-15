<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var int $initial_post_id */
$initial_post_id = isset($initial_post_id) ? (int) $initial_post_id : 0;
$initial_url = isset($initial_url) ? (string) $initial_url : '';
$initial_specialty = isset($initial_specialty) ? (string) $initial_specialty : '';
$initial_archive = isset($initial_archive) ? (string) $initial_archive : '';
$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
$quick_specialties = array_values(array_filter(
    \NobleSchema\Targeting\Specialty_Context::available(),
    static function (array $opt): bool {
        $key = (string) ($opt['value'] ?? '');
        if (!in_array($key, ['front_page', 'posts_page', '404', 'search'], true)) {
            return false;
        }
        // Hide page-backed picks that are not configured in Reading settings.
        if (in_array($key, ['front_page', 'posts_page'], true)
            && \NobleSchema\Targeting\Specialty_Context::resolve_post_id($key) <= 0
        ) {
            return false;
        }

        return true;
    }
));
$home_host = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');
?>
<div
    class="wrap ns-tools-wrap"
    id="ns-schema-tools"
    data-initial-post-id="<?php echo esc_attr((string) $initial_post_id); ?>"
    data-initial-url="<?php echo esc_attr($initial_url); ?>"
    data-initial-specialty="<?php echo esc_attr($initial_specialty); ?>"
    data-initial-archive="<?php echo esc_attr($initial_archive); ?>"
    data-home-host="<?php echo esc_attr($home_host); ?>"
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
        <h1><?php esc_html_e('Page Debugger', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">
    <p class="description">
        <?php esc_html_e('View and validate a post’s JSON-LD. Advanced mode displays matching configs, snapshots and regen state.', 'noble-schema'); ?>
    </p>

    <div class="ns-tools-card">
        <div class="ns-tools-checker__head">
            <div class="ns-tools-checker ns-tools-checker--targets">
                <div class="ns-tools-checker__row">
                    <label class="ns-tools-checker__label" for="ns-tools-post-search">
                        <?php esc_html_e('Find a post or paste a URL', 'noble-schema'); ?>
                    </label>
                    <div class="ns-tools-checker__search">
                        <input
                            type="search"
                            id="ns-tools-post-search"
                            class="ns-tools-checker__input regular-text"
                            placeholder="<?php esc_attr_e('Search by title or ID, or paste a URL…', 'noble-schema'); ?>"
                            autocomplete="off"
                        >
                        <div id="ns-tools-post-results" class="ns-tools-checker__results" hidden></div>
                    </div>
                    <p class="description ns-tools-checker__hint">
                        <?php esc_html_e('Find a page by Post ID, URL or Title to inspect it.', 'noble-schema'); ?>
                    </p>
                </div>
                <?php if ($quick_specialties !== []) : ?>
                    <div class="ns-tools-checker__quick" role="group" aria-label="<?php esc_attr_e('Specialty pages', 'noble-schema'); ?>">
                        <span class="ns-tools-checker__label"><?php esc_html_e('Specialty pages', 'noble-schema'); ?></span>
                        <div class="ns-tools-checker__chips">
                            <?php foreach ($quick_specialties as $opt) : ?>
                                <button
                                    type="button"
                                    class="button ns-tools-checker__chip"
                                    data-specialty="<?php echo esc_attr((string) ($opt['value'] ?? '')); ?>"
                                >
                                    <?php echo esc_html((string) ($opt['label'] ?? $opt['value'])); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <label class="ns-toggle ns-tools-checker__advanced" for="ns-tools-advanced">
                <span class="ns-toggle__control">
                    <input type="checkbox" id="ns-tools-advanced" value="1">
                    <span class="ns-toggle__ui" aria-hidden="true"></span>
                </span>
                <span class="ns-toggle__label"><?php esc_html_e('Advanced mode', 'noble-schema'); ?></span>
            </label>
        </div>
        <hr class="ns-tools-checker__divider">

        <div id="ns-tools-selected" class="ns-tools-checker__selected" hidden></div>

        <div class="ns-tools-checker__actions" id="ns-tools-actions" hidden>
            <button type="button" id="ns-tools-refresh" class="button button-primary" disabled>
                <?php esc_html_e('Refresh', 'noble-schema'); ?>
            </button>
            <button type="button" id="ns-tools-regen" class="button" disabled>
                <?php esc_html_e('Regenerate schema', 'noble-schema'); ?>
            </button>
            <div id="ns-viewer-toolbar" class="ns-schema-viewer__toolbar ns-tools-checker__source" hidden>
                <div class="ns-schema-viewer__toggles" role="group" aria-label="<?php esc_attr_e('Schema source', 'noble-schema'); ?>">
                    <span class="ns-schema-viewer__toggle-label"><?php esc_html_e('Source', 'noble-schema'); ?></span>
                    <button type="button" class="button ns-schema-viewer__source is-active" data-source="stored">
                        <?php esc_html_e('Stored', 'noble-schema'); ?>
                    </button>
                    <button type="button" class="button ns-schema-viewer__source" data-source="live">
                        <?php esc_html_e('Live generate', 'noble-schema'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="ns-tools-status" class="ns-tools-checker__status" hidden></div>

        <div id="ns-tools-simple" hidden>
            <div id="ns-viewer-meta" class="ns-schema-viewer__meta" hidden></div>
            <div id="ns-viewer-validation" class="ns-schema-viewer__validation" hidden></div>

            <div class="ns-tools-json ns-tools-json--standalone" id="ns-viewer-json-wrap" hidden>
                <div class="ns-tools-json__body">
                    <button
                        type="button"
                        class="ns-json-copy"
                        aria-label="<?php esc_attr_e('Copy JSON', 'noble-schema'); ?>"
                        title="<?php esc_attr_e('Copy JSON', 'noble-schema'); ?>"
                    >
                        <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                    </button>
                    <pre id="ns-viewer-json"></pre>
                </div>
            </div>

            <div id="ns-viewer-scripts" class="ns-schema-viewer__scripts" hidden></div>

            <div class="ns-schema-viewer__external" id="ns-viewer-external" hidden>
                <a
                    class="button"
                    id="ns-viewer-schema-org"
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <?php esc_html_e('Schema.org Validator', 'noble-schema'); ?>
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                </a>
                <a
                    class="button"
                    id="ns-viewer-rich-results"
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <?php esc_html_e('Rich Results Test', 'noble-schema'); ?>
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                </a>
            </div>
        </div>

        <div id="ns-tools-advanced-body" hidden>
            <div id="ns-tools-report" class="ns-tools-report"></div>
            <div id="ns-tools-diff" class="ns-tools-diff" hidden></div>
        </div>
    </div>
</div>
