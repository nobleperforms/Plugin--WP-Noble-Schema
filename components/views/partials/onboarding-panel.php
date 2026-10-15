<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array $state */
$state = \NobleSchema\Admin\Onboarding::get_state();
if ($state === null) {
    return;
}

$status = (string) ($state['status'] ?? 'active');
$path = $state['path'] ?? null;
$steps = is_array($state['steps'] ?? null) ? $state['steps'] : [];
$completed = \NobleSchema\Admin\Onboarding::completed_step_count($state);
$total = count(\NobleSchema\Admin\Onboarding::STEP_KEYS);
$celebrating = $status === \NobleSchema\Admin\Onboarding::STATUS_CELEBRATING;
$minimized = !$celebrating && $status === \NobleSchema\Admin\Onboarding::STATUS_MINIMIZED;
$show_back = !$celebrating && in_array($path, ['import', 'checklist'], true);
$current_tool = isset($_GET['tool']) ? sanitize_key(wp_unslash((string) $_GET['tool'])) : '';
$on_import_page = $current_tool === 'transfer';

$urls = [
    'settings'  => \NobleSchema\Admin\Settings_Page::page_url(),
    'templates' => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'storefront']),
    'configs'   => \NobleSchema\Admin\Menu::page_url(['action' => 'add']),
    'viewer'    => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'checker']),
    'import'    => \NobleSchema\Admin\Tools_Page::page_url(['tool' => 'transfer', 'transfer_tab' => 'import']),
];

$step_labels = [
    'settings'  => __('Configure project settings', 'noble-schema'),
    'templates' => __('Add custom schema templates', 'noble-schema'),
    'configs'   => __('Add schema configs', 'noble-schema'),
    'viewer'    => __('Validate your templates', 'noble-schema'),
];
?>
<div
    id="ns-onboarding"
    class="ns-onboarding<?php echo $minimized ? ' is-minimized' : ''; ?>"
    data-status="<?php echo esc_attr($status); ?>"
    data-path="<?php echo esc_attr((string) ($path ?? '')); ?>"
    role="complementary"
    aria-label="<?php esc_attr_e('Getting started', 'noble-schema'); ?>"
>
    <button
        type="button"
        class="ns-onboarding__pill"
        id="ns-onboarding-restore"
        <?php echo $minimized ? '' : 'hidden'; ?>
    >
        <span class="dashicons dashicons-flag" aria-hidden="true"></span>
        <span class="ns-onboarding__pill-label">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: completed steps, 2: total steps */
                    __('Setup · %1$d/%2$d', 'noble-schema'),
                    $completed,
                    $total
                )
            );
            ?>
        </span>
    </button>

    <div class="ns-onboarding__panel" <?php echo $minimized ? 'hidden' : ''; ?>>
        <div class="ns-onboarding__header">
            <div class="ns-onboarding__heading">
                <button
                    type="button"
                    class="ns-onboarding__back"
                    id="ns-onboarding-back"
                    data-ns-onboarding-path="question"
                    aria-label="<?php esc_attr_e('Back', 'noble-schema'); ?>"
                    title="<?php esc_attr_e('Back', 'noble-schema'); ?>"
                    <?php echo $show_back ? '' : 'hidden'; ?>
                >
                    <svg class="ns-onboarding__back-icon" width="14" height="14" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                        <path d="M16 10H5m0 0l4.5-4.5M5 10l4.5 4.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
                <h2 class="ns-onboarding__title"><?php esc_html_e('Getting started', 'noble-schema'); ?></h2>
            </div>
            <div class="ns-onboarding__header-actions">
                <button type="button" class="ns-onboarding__icon-btn" id="ns-onboarding-minimize" aria-label="<?php esc_attr_e('Minimize', 'noble-schema'); ?>">
                    <span class="dashicons dashicons-minus" aria-hidden="true"></span>
                </button>
                <button type="button" class="ns-onboarding__icon-btn" id="ns-onboarding-dismiss" aria-label="<?php esc_attr_e('Dismiss', 'noble-schema'); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
        </div>

        <div class="ns-onboarding__body" data-ns-onboarding-section="question" <?php echo (!$celebrating && $path === null) ? '' : 'hidden'; ?>>
            <p class="ns-onboarding__prompt"><?php esc_html_e('Do you have a starter config to import?', 'noble-schema'); ?></p>
            <div class="ns-onboarding__actions">
                <button type="button" class="button button-primary" data-ns-onboarding-path="checklist">
                    <?php esc_html_e('No, guide me', 'noble-schema'); ?>
                </button>
                <button type="button" class="button" data-ns-onboarding-path="import">
                    <?php esc_html_e('Yes, import', 'noble-schema'); ?>
                </button>
            </div>
        </div>

        <div class="ns-onboarding__body" data-ns-onboarding-section="import" <?php echo (!$celebrating && $path === 'import') ? '' : 'hidden'; ?>>
            <p class="ns-onboarding__prompt<?php echo $on_import_page ? ' ns-onboarding__prompt--last' : ''; ?>">
                <?php esc_html_e('Import your starter pack from Export / Import. We’ll check what’s left on the setup checklist afterward.', 'noble-schema'); ?>
            </p>
            <?php if (!$on_import_page) : ?>
                <div class="ns-onboarding__actions" id="ns-onboarding-import-actions">
                    <a class="button button-primary" href="<?php echo esc_url($urls['import']); ?>">
                        <?php esc_html_e('Open Import', 'noble-schema'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="ns-onboarding__body" data-ns-onboarding-section="checklist" <?php echo (!$celebrating && $path === 'checklist') ? '' : 'hidden'; ?>>
            <p class="ns-onboarding__progress" id="ns-onboarding-progress">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: completed steps, 2: total steps */
                        __('%1$d of %2$d complete', 'noble-schema'),
                        $completed,
                        $total
                    )
                );
                ?>
            </p>
            <ul class="ns-onboarding__list" id="ns-onboarding-list">
                <?php foreach ($step_labels as $key => $label) :
                    $done = !empty($steps[$key]['completed']);
                    ?>
                    <li
                        class="ns-onboarding__item<?php echo $done ? ' is-complete' : ''; ?>"
                        data-step="<?php echo esc_attr($key); ?>"
                    >
                        <span class="ns-onboarding__check" aria-hidden="true">
                            <?php if ($done) : ?>
                                <span class="dashicons dashicons-yes"></span>
                            <?php else : ?>
                                <span class="ns-onboarding__check-empty"></span>
                            <?php endif; ?>
                        </span>
                        <a class="ns-onboarding__link" href="<?php echo esc_url($urls[$key]); ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                        <?php if (!$done) : ?>
                            <button
                                type="button"
                                class="button-link ns-onboarding__mark"
                                data-ns-onboarding-mark="<?php echo esc_attr($key); ?>"
                            >
                                <?php esc_html_e('Mark complete', 'noble-schema'); ?>
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="ns-onboarding__body" data-ns-onboarding-section="complete" <?php echo $celebrating ? '' : 'hidden'; ?>>
            <p class="ns-onboarding__complete-title"><?php esc_html_e('You’re all set!', 'noble-schema'); ?></p>
            <p class="ns-onboarding__prompt">
                <?php esc_html_e('Noble Schema is ready to go. This guide will close on your next visit.', 'noble-schema'); ?>
            </p>
        </div>
    </div>
</div>
