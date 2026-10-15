<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var string $tool */
$tool = isset($tool) ? (string) $tool : '';
$hub_url = \NobleSchema\Admin\Tools_Page::page_url();

if ($tool === 'checker') {
    include __DIR__ . '/tools-checker.php';
    return;
}

if ($tool === 'sitewide-analytics') {
    include __DIR__ . '/tools-sitewide-analytics.php';
    return;
}

if ($tool === 'batch-regen') {
    include __DIR__ . '/tools-batch-regen.php';
    return;
}

if ($tool === 'storefront') {
    include __DIR__ . '/tools-storefront.php';
    return;
}

if ($tool === 'transfer') {
    include __DIR__ . '/tools-transfer.php';
    return;
}

if ($tool === 'data-removal') {
    include __DIR__ . '/tools-data-removal.php';
    return;
}

if ($tool === 'logs') {
    include __DIR__ . '/tools-logs.php';
    return;
}

$tools = [
    [
        'id'    => 'storefront',
        'title' => __('Schema Storefront', 'noble-schema'),
        'desc'  => __('Browse schema.org types and add custom templates for the Schema Config wizard.', 'noble-schema'),
        'icon'  => 'dashicons-store',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'storefront']),
    ],
    [
        'id'    => 'batch-regen',
        'title' => __('Batch Regenerate', 'noble-schema'),
        'desc'  => __('Regenerate schema for posts matching placement targets (post types, templates, archives and more).', 'noble-schema'),
        'icon'  => 'dashicons-update',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'batch-regen']),
    ],
    [
        'id'    => 'sitewide-analytics',
        'title' => __('Sitewide Analytics', 'noble-schema'),
        'desc'  => __('Measure page-specific schema coverage across the site, triage gaps and scan for issues.', 'noble-schema'),
        'icon'  => 'dashicons-chart-area',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'sitewide-analytics']),
    ],
    [
        'id'    => 'checker',
        'title' => __('Page Debugger', 'noble-schema'),
        'desc'  => __('View and validate a post’s JSON-LD. Advanced mode displays matching configs, snapshots and regen state.', 'noble-schema'),
        'icon'  => 'dashicons-media-code',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'checker']),
    ],
    [
        'id'    => 'logs',
        'title' => __('Logs', 'noble-schema'),
        'desc'  => __('Browse system logs for schema changes, imports, templates, errors and more.', 'noble-schema'),
        'icon'  => 'dashicons-list-view',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'logs']),
    ],
    [
        'id'    => 'transfer',
        'title' => __('Export / Import', 'noble-schema'),
        'desc'  => __('Export or import schema configs, plugin settings and/or custom templates.', 'noble-schema'),
        'icon'  => 'dashicons-migrate',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'transfer']),
    ],
    [
        'id'    => 'data-removal',
        'title' => __('Data Removal', 'noble-schema'),
        'desc'  => __('Permanently delete plugin settings, custom templates, configs or generated snapshots.', 'noble-schema'),
        'icon'  => 'dashicons-trash',
        'url'   => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'data-removal']),
    ],
];

if (\NobleSchema\Admin\Onboarding::show_tools_card()) {
    $onboarding_card = [
        'id'    => 'onboarding',
        'title' => __('Getting Started', 'noble-schema'),
        'desc'  => __('Reopen the setup guide to restart the Noble Schema onboarding process.', 'noble-schema'),
        'icon'  => 'dashicons-welcome-learn-more',
        'url'   => wp_nonce_url(
            \NobleSchema\Admin\Tools_Page::page_url(['ns_onboarding' => 'reopen']),
            'ns_onboarding_banner'
        ),
    ];

    $insert_at = count($tools);
    foreach ($tools as $index => $item) {
        if (($item['id'] ?? '') === 'data-removal') {
            $insert_at = $index;
            break;
        }
    }
    array_splice($tools, $insert_at, 0, [$onboarding_card]);
}
$configs_url = \NobleSchema\Admin\Menu::page_url();
$settings_url = \NobleSchema\Admin\Settings_Page::page_url();
?>
<div class="wrap ns-tools-wrap" id="ns-schema-tools-hub">
    <h1 class="wp-heading-inline"><?php esc_html_e('Schema Tools', 'noble-schema'); ?></h1>
    <a
        href="<?php echo esc_url($configs_url); ?>"
        class="page-title-action ns-tools-link"
        aria-label="<?php esc_attr_e('Schema Config', 'noble-schema'); ?>"
        title="<?php esc_attr_e('Schema Config', 'noble-schema'); ?>"
    >
        <span class="dashicons dashicons-menu" aria-hidden="true"></span>
    </a>
    <a
        href="<?php echo esc_url($settings_url); ?>"
        class="page-title-action ns-tools-link"
        aria-label="<?php esc_attr_e('Schema Settings', 'noble-schema'); ?>"
        title="<?php esc_attr_e('Schema Settings', 'noble-schema'); ?>"
    >
        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
    </a>
    <hr class="wp-header-end">
    <p class="description">
        <?php esc_html_e('Utilities for inspecting, regenerating and managing Noble Schema output.', 'noble-schema'); ?>
    </p>

    <div class="ns-tools-grid">
        <?php foreach ($tools as $item) : ?>
            <a
                class="ns-tools-grid__card<?php echo ($item['id'] ?? '') === 'data-removal' ? ' ns-tools-grid__card--danger' : ''; ?>"
                href="<?php echo esc_url($item['url']); ?>"
            >
                <span class="ns-tools-grid__icon dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                <span class="ns-tools-grid__body">
                    <span class="ns-tools-grid__title"><?php echo esc_html($item['title']); ?></span>
                    <span class="ns-tools-grid__desc"><?php echo esc_html($item['desc']); ?></span>
                </span>
                <span class="ns-tools-grid__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
