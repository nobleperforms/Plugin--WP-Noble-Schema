<?php

declare(strict_types=1);

use NobleSchema\Admin\Access;
use NobleSchema\Admin\Onboarding;
use NobleSchema\Admin\Tools_Page;

/**
 * @return array<string, mixed>
 */
function ns_onboarding_ajax_state(): array {
    $tool = isset($_REQUEST['tool']) ? sanitize_key(wp_unslash((string) $_REQUEST['tool'])) : '';
    $state = Onboarding::get_state();
    if ($state !== null) {
        $state = Onboarding::merge_auto_completion($tool !== '' ? $tool : null);
    }

    return [
        'state'         => $state,
        'activeUi'      => Onboarding::is_active_ui(),
        'showBanner'    => Onboarding::show_banner(),
        'showToolsCard' => Onboarding::show_tools_card(),
        'completed'     => $state ? Onboarding::completed_step_count($state) : 0,
    ];
}

add_action('wp_ajax_ns_onboarding_set_path', static function (): void {
    Access::require_manage_schema();
    check_ajax_referer('ns_onboarding', 'nonce');

    $path = isset($_POST['path']) ? sanitize_key(wp_unslash((string) $_POST['path'])) : '';
    $allowed = [Onboarding::PATH_IMPORT, Onboarding::PATH_CHECKLIST, 'question', ''];
    if (!in_array($path, $allowed, true)) {
        wp_send_json_error(['message' => __('Invalid path.', 'noble-schema')], 400);
    }

    Onboarding::set_path($path);
    $payload = ns_onboarding_ajax_state();
    if ($path === Onboarding::PATH_IMPORT) {
        $payload['redirect'] = Tools_Page::page_url(['tool' => 'transfer', 'transfer_tab' => 'import']);
    }

    wp_send_json_success($payload);
});

add_action('wp_ajax_ns_onboarding_minimize', static function (): void {
    Access::require_manage_schema();
    check_ajax_referer('ns_onboarding', 'nonce');
    Onboarding::minimize();
    wp_send_json_success(ns_onboarding_ajax_state());
});

add_action('wp_ajax_ns_onboarding_restore', static function (): void {
    Access::require_manage_schema();
    check_ajax_referer('ns_onboarding', 'nonce');
    Onboarding::restore();
    wp_send_json_success(ns_onboarding_ajax_state());
});

add_action('wp_ajax_ns_onboarding_dismiss', static function (): void {
    Access::require_manage_schema();
    check_ajax_referer('ns_onboarding', 'nonce');
    Onboarding::dismiss();
    wp_send_json_success(ns_onboarding_ajax_state());
});

add_action('wp_ajax_ns_onboarding_mark_step', static function (): void {
    Access::require_manage_schema();
    check_ajax_referer('ns_onboarding', 'nonce');

    $step = isset($_POST['step']) ? sanitize_key(wp_unslash((string) $_POST['step'])) : '';
    if (!in_array($step, Onboarding::STEP_KEYS, true)) {
        wp_send_json_error(['message' => __('Invalid step.', 'noble-schema')], 400);
    }

    Onboarding::mark_step_complete($step);
    wp_send_json_success(ns_onboarding_ajax_state());
});

add_action('wp_ajax_ns_onboarding_reopen', static function (): void {
    Access::require_manage_schema();
    check_ajax_referer('ns_onboarding', 'nonce');
    Onboarding::reopen();
    wp_send_json_success(ns_onboarding_ajax_state());
});
