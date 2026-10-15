<?php

namespace NobleSchema\Admin;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Generator\Regen_Queue;
use NobleSchema\Tools;

class Menu {
    private string $plugin_file;

    public function __construct(string $plugin_file) {
        $this->plugin_file = $plugin_file;
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_menu', [$this, 'maybe_hide_menu'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'render_regen_notice']);
        add_action('admin_post_ns_toggle_config', [$this, 'handle_toggle']);
    }

    public function add_menu(): void {
        add_options_page(
            __('Schema Config', 'noble-schema'),
            __('Schema Config', 'noble-schema'),
            'manage_options',
            'schema-config',
            [$this, 'render']
        );
    }

    public function maybe_hide_menu(): void {
        if (!Access::can_manage_schema()) {
            remove_submenu_page('options-general.php', 'schema-config');
        }
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'settings_page_schema-config') {
            return;
        }

        $dist_dir = dirname($this->plugin_file) . '/dist';
        $base_url = plugin_dir_url($this->plugin_file);

        if (is_readable($dist_dir . '/styles/admin.css')) {
            wp_enqueue_style(
                'noble-schema-admin',
                $base_url . 'dist/styles/admin.css',
                ['dashicons'],
                Tools\asset_version($dist_dir . '/styles/admin.css')
            );

            $valid_icon = $base_url . 'assets/step-valid.png';
            $error_icon = $base_url . 'assets/step-error.png';
            wp_add_inline_style(
                'noble-schema-admin',
                '.ns-wizard__step-status--valid{background-image:url(' . esc_url_raw($valid_icon) . ')}'
                . '.ns-wizard__step-status--error{background-image:url(' . esc_url_raw($error_icon) . ')}'
            );
        }

        if (is_readable($dist_dir . '/scripts/admin.js')) {
            wp_enqueue_script(
                'noble-schema-admin',
                $base_url . 'dist/scripts/admin.js',
                [],
                Tools\asset_version($dist_dir . '/scripts/admin.js'),
                true
            );

            $queue = new Regen_Queue();
            $regen_status = $queue->get_status();
            wp_localize_script('noble-schema-admin', 'nsNobleSchemaAdmin', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'regenNonce' => wp_create_nonce('ns_regen'),
                'termsNonce' => wp_create_nonce('ns_search_terms'),
                'metaKeysNonce' => wp_create_nonce('ns_search_meta_keys'),
                'acfFieldsNonce' => wp_create_nonce('ns_search_acf_fields'),
                'postsNonce' => wp_create_nonce('ns_search_posts'),
                'toolsNonce' => wp_create_nonce('ns_schema_tools'),
                'clearArchiveNonce' => wp_create_nonce('ns_clear_archive_cache'),
                'autoStart'  => isset($_GET['ns_regen_modal'])
                    || !empty($regen_status['remaining'])
                    || !empty($regen_status['cancelled']),
                'status'     => $regen_status,
                'i18n'       => [
                    'title'       => __('Updating schema on posts', 'noble-schema'),
                    'complete'    => __('Schema update complete', 'noble-schema'),
                    'progress'    => __('Updating %1$d of %2$d posts…', 'noble-schema'),
                    /* translators: %s: formatted count of posts outside the new targeting */
                    'rescanExtra' => __('%s additional posts required rescan as a result of your changes', 'noble-schema'),
                    'done'        => __('All posts updated.', 'noble-schema'),
                    'error'       => __('Regeneration failed. Please try again.', 'noble-schema'),
                    'close'       => __('Close', 'noble-schema'),
                    'retry'       => __('Retry', 'noble-schema'),
                    'working'     => __('Please wait while affected posts are updated.', 'noble-schema'),
                    'httpError'   => __('Server error (%d). You can retry from where you left off.', 'noble-schema'),
                    'ok'          => __('OK', 'noble-schema'),
                    'cancel'      => __('Cancel', 'noble-schema'),
                    'delete'      => __('Delete', 'noble-schema'),
                    'alertTitle'  => __('Notice', 'noble-schema'),
                    'confirmTitle'=> __('Please confirm', 'noble-schema'),
                    'deleteTitle' => __('Delete config?', 'noble-schema'),
                    'deleteConfirm' => __('Delete this config permanently? This cannot be undone.', 'noble-schema'),
                    'regenCancelTitle' => __('Cancel schema update?', 'noble-schema'),
                    'regenCancelConfirm' => __('Stop updating schema on remaining posts? Posts already updated will keep their new schema.', 'noble-schema'),
                    'regenCancelKeep' => __('Keep updating', 'noble-schema'),
                    'regenCancelled' => __('Schema update cancelled.', 'noble-schema'),
                    'regenCancelledLocal' => __('Update cancelled successfully.', 'noble-schema'),
                    'regenCancelledRemote' => __('This action was cancelled by another user/window.', 'noble-schema'),
                    'enableIncomplete' => __('Complete all required steps before enabling this schema config.', 'noble-schema'),
                    'updateIncomplete' => __('Complete all required steps before updating this schema config.', 'noble-schema'),
                    'saveFailed'  => __('Save failed.', 'noble-schema'),
                    'clearArchiveTitle' => __('Clear archive cache?', 'noble-schema'),
                    'clearArchiveConfirm' => __('Clear cached schema output for archive pages targeted by this config? Archives will rebuild on the next visit.', 'noble-schema'),
                    'clearArchive' => __('Clear cache', 'noble-schema'),
                    'clearArchiveDone' => __('Archive cache cleared.', 'noble-schema'),
                    /* translators: %s: formatted post count */
                    'batchCountPosts' => __('%s posts selected', 'noble-schema'),
                    /* translators: 1: formatted post count, 2: "across N languages" phrase */
                    'batchCountPostsAcross' => __('%1$s posts selected %2$s', 'noble-schema'),
                    /* translators: %s: formatted language count */
                    'batchCountAcrossLanguage' => __('across %s language', 'noble-schema'),
                    /* translators: %s: formatted language count */
                    'batchCountAcrossLanguages' => __('across %s languages', 'noble-schema'),
                    /* translators: 1: formatted post count, 2: formatted archive count */
                    'batchCountPostsAndArchives' => __('%1$s posts · %2$s archives', 'noble-schema'),
                    'batchCountEmpty' => __('0 posts selected', 'noble-schema'),
                    'batchCountLoading' => __('Loading post count…', 'noble-schema'),
                    /* translators: %s: schema.org type name */
                    'openSchemaDocs' => __('Open %s schema documentation', 'noble-schema'),
                    'sitewideNoRegen' => __('Shared sitewide schema — no per-post regeneration', 'noble-schema'),
                ],
                'onboarding' => Onboarding::localize_payload(),
            ]);
        }
    }

    public function render(): void {
        if (!Access::can_manage_schema()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'noble-schema'));
        }

        $this->maybe_render_notice();

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if (in_array($action, ['add', 'edit'], true)) {
            $this->render_wizard();
        } else {
            $this->render_list();
        }

        include dirname(__DIR__) . '/views/partials/dialog-modal.php';
        include dirname(__DIR__) . '/views/partials/regen-modal.php';
    }

    public function handle_toggle(): void {
        if (!Access::can_manage_schema()) {
            wp_die(esc_html__('Unauthorized', 'noble-schema'));
        }

        $id = isset($_GET['config_id']) ? (int) $_GET['config_id'] : 0;
        check_admin_referer('ns_toggle_config_' . $id);

        $repo   = new Config_Repository();
        $config = $repo->find($id);

        if (!$config) {
            $this->redirect_with_notice('error', __('Config not found.', 'noble-schema'));
        }

        $new_status = $config->status === 'active' ? 'disabled' : 'active';
        $updated    = $repo->update($id, ['status' => $new_status]);

        if ($updated) {
            (new Regen_Queue())->enqueue_for_config($id);

            $message = $new_status === 'active'
                ? __('Config enabled.', 'noble-schema')
                : __('Config disabled.', 'noble-schema');
            $this->redirect_with_notice('success', $message, (new Regen_Queue())->is_pending());
        }

        $this->redirect_with_notice('error', __('Could not update config status.', 'noble-schema'));
    }

    public static function page_url(array $args = []): string {
        return add_query_arg(
            array_merge(['page' => 'schema-config'], $args),
            admin_url('options-general.php')
        );
    }

    public static function edit_url(int $id): string {
        return self::page_url([
            'action'    => 'edit',
            'config_id' => $id,
        ]);
    }

    private function render_list(): void {
        $table = new Config_List_Table(new Config_Repository());
        $table->prepare_items();

        include dirname(__DIR__) . '/views/list.php';
    }

    private function render_wizard(): void {
        $config_id  = isset($_GET['config_id']) ? (int) $_GET['config_id'] : 0;
        $plugin_dir = dirname($this->plugin_file);
        $controller = new Wizard_Controller($plugin_dir);
        $data       = $controller->view_data($config_id);

        include dirname(__DIR__) . '/views/wizard.php';
    }

    public function render_regen_notice(): void {
        if (!isset($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== 'schema-config') {
            return;
        }

        // Modal progress UI owns the feedback when active.
        if (isset($_GET['ns_regen_modal'])) {
            return;
        }

        $queue = new Regen_Queue();
        if (!$queue->is_pending()) {
            return;
        }

        $count = $queue->pending_count();
        $message = sprintf(
            _n(
                'Regeneration in progress… %s post remaining.',
                'Regeneration in progress… %s posts remaining.',
                $count,
                'noble-schema'
            ),
            number_format_i18n($count)
        );

        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }

    private function maybe_render_notice(): void {
        if (!isset($_GET['ns_notice'])) {
            return;
        }

        $type    = sanitize_key(wp_unslash($_GET['ns_notice']));
        $message = isset($_GET['ns_message'])
            ? sanitize_text_field(wp_unslash(rawurldecode((string) $_GET['ns_message'])))
            : '';

        if ($message === '') {
            return;
        }

        if ($type === 'success') {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        } elseif ($type === 'error') {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }

    private function redirect_with_notice(string $type, string $message, bool $show_regen_modal = false): void {
        $args = [
            'page'       => 'schema-config',
            'ns_notice'  => $type,
            'ns_message' => rawurlencode($message),
        ];

        if ($show_regen_modal) {
            $args['ns_regen_modal'] = '1';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }
}
