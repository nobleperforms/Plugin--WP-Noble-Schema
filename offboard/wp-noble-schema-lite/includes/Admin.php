<?php

declare(strict_types=1);

namespace NobleSchemaLite;

final class Admin {
    public const PAGE_SLUG = 'noble-schema-lite';
    public const COMPLETE_PHRASE = 'OFFBOARD';

    public function register(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ns_lite_complete_step', [$this, 'ajax_complete_step']);
        add_action('wp_ajax_ns_lite_purge_migrated', [$this, 'ajax_purge_migrated']);
        add_action('admin_notices', [$this, 'maybe_notice']);
    }

    public function register_menu(): void {
        add_options_page(
            __('Schema Lite', 'noble-schema-lite'),
            __('Schema Lite', 'noble-schema-lite'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public static function settings_url(): string {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        $complete = (string) get_option(NS_LITE_COMPLETE_OPTION, '') === '1';
        $handle = 'ns-lite-admin';
        wp_register_style($handle, false, [], NS_LITE_VERSION);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, $this->inline_css());

        wp_register_script($handle, false, [], NS_LITE_VERSION, true);
        wp_enqueue_script($handle);
        wp_localize_script($handle, 'nsLiteAdmin', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ns_lite_complete'),
            'phrase'   => self::COMPLETE_PHRASE,
            'complete' => $complete,
            'i18n'     => [
                'confirmMessage' => __('This migrates schema data into Schema Lite, then removes Noble Schema and its project data. This cannot be undone.', 'noble-schema-lite'),
                'working'        => __('Working…', 'noble-schema-lite'),
                'stepMigrate'    => __('Migrating schema snapshots…', 'noble-schema-lite'),
                'stepPrepare'    => __('Preparing Noble Schema removal…', 'noble-schema-lite'),
                'stepRemove'     => __('Removing Noble Schema…', 'noble-schema-lite'),
                'stepFinish'     => __('Finishing up…', 'noble-schema-lite'),
                'done'           => __('Offboarding complete.', 'noble-schema-lite'),
                'error'          => __('Offboarding failed.', 'noble-schema-lite'),
                'phraseMismatch' => __('Confirmation phrase did not match.', 'noble-schema-lite'),
                'purgeConfirm'   => __('Delete all migrated Schema Lite JSON-LD and reset offboarding so it can be run again? Frontend schema output will stop until you migrate or add new JSON. This cannot be undone.', 'noble-schema-lite'),
                'purgeWorking'   => __('Deleting migrated data…', 'noble-schema-lite'),
                'purgeError'     => __('Could not delete migrated data.', 'noble-schema-lite'),
            ],
        ]);
        wp_add_inline_script($handle, $this->inline_js(), 'after');
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $complete = (string) get_option(NS_LITE_COMPLETE_OPTION, '') === '1';
        $purged = isset($_GET['ns_lite_purged']) && (string) $_GET['ns_lite_purged'] === '1';
        ?>
        <div class="wrap" id="ns-lite-settings">
            <h1><?php esc_html_e('Schema Lite', 'noble-schema-lite'); ?></h1>

            <?php if ($purged) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Migrated data was deleted and offboarding was reset. You can run Complete offboarding again.', 'noble-schema-lite'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($complete) : ?>
                <div class="ns-lite-card">
                    <h2><?php esc_html_e('Status', 'noble-schema-lite'); ?></h2>
                    <p>
                        <?php esc_html_e(
                            'Offboarding is complete. Schema Lite serves JSON-LD from post meta and the archive option. Edit schema on each post’s “Schema JSON-LD” box.',
                            'noble-schema-lite'
                        ); ?>
                    </p>
                </div>

                <div class="ns-lite-card" id="ns-lite-purge-panel">
                    <h2><?php esc_html_e('Migrated data', 'noble-schema-lite'); ?></h2>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: post meta key */
                            esc_html__('Removes every Schema Lite JSON-LD entry stored in %s, clears archive schema and resets offboarding so the process can be run again. Frontend output stops until you migrate or add new JSON.', 'noble-schema-lite'),
                            '<code>' . esc_html(NS_LITE_META_KEY) . '</code>'
                        );
                        ?>
                    </p>
                    <p>
                        <button type="button" class="button" id="ns-lite-purge-btn">
                            <?php esc_html_e('Delete all migrated data', 'noble-schema-lite'); ?>
                        </button>
                    </p>
                    <p class="ns-lite-purge-status" id="ns-lite-purge-status" aria-live="polite"></p>
                </div>
            <?php else : ?>
                <div class="ns-lite-card">
                    <h2><?php esc_html_e('Complete offboarding', 'noble-schema-lite'); ?></h2>
                    <p>
                        <?php esc_html_e(
                            'Migrates existing Noble Schema output into Schema Lite, then removes the Noble Schema plugin and its project data. This cannot be undone.',
                            'noble-schema-lite'
                        ); ?>
                    </p>
                    <div id="ns-lite-complete-panel">
                        <p>
                            <label for="ns-lite-confirm">
                                <?php
                                printf(
                                    /* translators: %s: confirmation phrase */
                                    esc_html__('Type %s to confirm:', 'noble-schema-lite'),
                                    '<code>' . esc_html(self::COMPLETE_PHRASE) . '</code>'
                                );
                                ?>
                            </label>
                        </p>
                        <p>
                            <input type="text" id="ns-lite-confirm" class="regular-text" autocomplete="off">
                        </p>
                        <p>
                            <button type="button" class="button button-primary" id="ns-lite-complete-btn" style="background:#b32d2e;border-color:#b32d2e;">
                                <?php esc_html_e('Complete offboarding', 'noble-schema-lite'); ?>
                            </button>
                        </p>
                    </div>
                    <div id="ns-lite-progress" class="ns-lite-progress" hidden>
                        <p class="ns-lite-progress__label" id="ns-lite-progress-label"><?php esc_html_e('Working…', 'noble-schema-lite'); ?></p>
                        <div class="ns-lite-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="ns-lite-progress-bar">
                            <div class="ns-lite-progress__fill" id="ns-lite-progress-fill"></div>
                        </div>
                        <p class="ns-lite-progress__status" id="ns-lite-progress-status" aria-live="polite"></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_complete_step(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to complete offboarding.', 'noble-schema-lite')], 403);
        }
        check_ajax_referer('ns_lite_complete', 'nonce');

        if ((string) get_option(NS_LITE_COMPLETE_OPTION, '') === '1') {
            wp_send_json_success([
                'done'    => true,
                'percent' => 100,
                'message' => __('Offboarding is already complete.', 'noble-schema-lite'),
                'reload'  => self::settings_url(),
            ]);
        }

        $confirm = isset($_POST['confirm']) ? (string) wp_unslash((string) $_POST['confirm']) : '';
        if ($confirm !== self::COMPLETE_PHRASE) {
            wp_send_json_error([
                'message' => __('Confirmation phrase did not match.', 'noble-schema-lite'),
            ], 400);
        }

        $step = isset($_POST['step']) ? sanitize_key(wp_unslash((string) $_POST['step'])) : '';
        $allowed = ['migrate', 'prepare', 'remove_parent', 'finish'];
        if (!in_array($step, $allowed, true)) {
            wp_send_json_error(['message' => __('Unknown offboarding step.', 'noble-schema-lite')], 400);
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        @set_time_limit(120);

        try {
            wp_send_json_success($this->run_step($step));
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Offboarding failed: %s', 'noble-schema-lite'),
                    $e->getMessage()
                ),
            ], 500);
        }
    }

    public function ajax_purge_migrated(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to delete migrated data.', 'noble-schema-lite')], 403);
        }
        check_ajax_referer('ns_lite_complete', 'nonce');

        if ((string) get_option(NS_LITE_COMPLETE_OPTION, '') !== '1') {
            wp_send_json_error([
                'message' => __('Offboarding must be complete before migrated data can be deleted.', 'noble-schema-lite'),
            ], 400);
        }

        $confirm = isset($_POST['confirm']) ? (string) wp_unslash((string) $_POST['confirm']) : '';
        if ($confirm !== '1') {
            wp_send_json_error([
                'message' => __('Deletion was not confirmed.', 'noble-schema-lite'),
            ], 400);
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        @set_time_limit(120);

        $counts = (new Migrator())->purge_migrated_data();
        wp_send_json_success([
            'posts'    => $counts['posts'],
            'archives' => $counts['archives'],
            'message'  => sprintf(
                /* translators: 1: post meta rows deleted, 2: archive entries cleared */
                __('Deleted schema data from %1$d posts, cleared %2$d archive entries and reset offboarding.', 'noble-schema-lite'),
                $counts['posts'],
                $counts['archives']
            ),
            'reload'   => add_query_arg('ns_lite_purged', '1', self::settings_url()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function run_step(string $step): array {
        switch ($step) {
            case 'migrate':
                $counts = (new Migrator())->migrate();
                return [
                    'step'     => 'migrate',
                    'next'     => 'prepare',
                    'percent'  => 35,
                    'posts'    => $counts['posts'],
                    'archives' => $counts['archives'],
                    'message'  => sprintf(
                        /* translators: 1: post count, 2: archive count */
                        __('Migrated %1$d posts and %2$d archives.', 'noble-schema-lite'),
                        $counts['posts'],
                        $counts['archives']
                    ),
                ];

            case 'prepare':
                update_option('ns_delete_data_on_uninstall', '1', false);
                return [
                    'step'    => 'prepare',
                    'next'    => 'remove_parent',
                    'percent' => 55,
                    'message' => __('Uninstall data wipe enabled.', 'noble-schema-lite'),
                ];

            case 'remove_parent':
                $result = $this->remove_parent_plugin();
                return [
                    'step'    => 'remove_parent',
                    'next'    => 'finish',
                    'percent' => 85,
                    'manual'  => $result['manual'],
                    'deleted' => $result['deleted'],
                    'message' => $result['message'],
                ];

            case 'finish':
                update_option(NS_LITE_COMPLETE_OPTION, '1', false);
                return [
                    'step'    => 'finish',
                    'next'    => null,
                    'done'    => true,
                    'percent' => 100,
                    'message' => __('Offboarding complete.', 'noble-schema-lite'),
                    'reload'  => self::settings_url(),
                ];
        }

        throw new \RuntimeException(__('Unknown offboarding step.', 'noble-schema-lite'));
    }

    /**
     * @return array{manual:bool,deleted:bool,message:string}
     */
    private function remove_parent_plugin(): array {
        $parent = (string) get_option(NS_LITE_PARENT_OPTION, '');
        if ($parent === '') {
            return [
                'manual'  => false,
                'deleted' => false,
                'message' => __('No parent plugin reference found; skipping removal.', 'noble-schema-lite'),
            ];
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (function_exists('is_plugin_active') && is_plugin_active($parent)) {
            deactivate_plugins($parent, true);
        }

        $parent_path = WP_PLUGIN_DIR . '/' . $parent;
        $parent_dir = dirname($parent_path);
        $can_delete = is_writable(WP_PLUGIN_DIR)
            && ((is_dir($parent_dir) && is_writable($parent_dir)) || is_writable(dirname($parent_dir)));

        if (!$can_delete || !is_dir($parent_dir)) {
            return [
                'manual'  => is_dir($parent_dir),
                'deleted' => !is_dir($parent_dir),
                'message' => is_dir($parent_dir)
                    ? __('Noble Schema was deactivated. Files could not be deleted; remove them manually.', 'noble-schema-lite')
                    : __('Noble Schema is already removed.', 'noble-schema-lite'),
            ];
        }

        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $result = delete_plugins([$parent]);
        if (is_wp_error($result)) {
            return [
                'manual'  => true,
                'deleted' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Noble Schema was deactivated but not deleted: %s', 'noble-schema-lite'),
                    $result->get_error_message()
                ),
            ];
        }

        return [
            'manual'  => false,
            'deleted' => true,
            'message' => __('Noble Schema deactivated and deleted.', 'noble-schema-lite'),
        ];
    }

    public function maybe_notice(): void {
        if (!isset($_GET['page']) || (string) $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['ns_lite_manual'])) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__(
                'Noble Schema was deactivated, but its files could not be deleted on this environment. Remove the Noble Schema plugin folder manually (or on the next deploy).',
                'noble-schema-lite'
            )
        );
    }

    private function inline_css(): string {
        return <<<'CSS'
.ns-lite-card{max-width:40rem;margin-top:1rem;padding:1.1rem 1.25rem;background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.ns-lite-card h2{margin:0 0 .75rem;font-size:1.1em}
.ns-lite-card>p:last-child{margin-bottom:0}
.ns-lite-progress{margin-top:1.25rem;max-width:36rem}
.ns-lite-progress__label{font-weight:600;margin:0 0 .5rem}
.ns-lite-progress__track{background:#dcdcde;border-radius:999px;height:10px;overflow:hidden;position:relative}
.ns-lite-progress__fill{background:#b32d2e;border-radius:999px;height:100%;width:0;transition:width .25s ease}
.ns-lite-progress__fill.is-indeterminate{position:absolute;left:0;width:40%;transition:none;animation:ns-lite-indeterminate 1.1s ease-in-out infinite}
.ns-lite-progress__status{color:#646970;margin:.5rem 0 0}
.ns-lite-purge-status{color:#646970;margin:.5rem 0 0}
.ns-lite-purge-status.is-error{color:#b32d2e}
@keyframes ns-lite-indeterminate{0%{transform:translateX(-120%)}100%{transform:translateX(280%)}}
CSS;
    }

    private function inline_js(): string {
        return <<<'JS'
(function () {
  const cfg = window.nsLiteAdmin || {};
  const i18n = cfg.i18n || {};

  function initPurge() {
    const btn = document.getElementById('ns-lite-purge-btn');
    const statusEl = document.getElementById('ns-lite-purge-status');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      if (!window.confirm(i18n.purgeConfirm || 'Delete all migrated data? This cannot be undone.')) {
        return;
      }

      btn.disabled = true;
      if (statusEl) {
        statusEl.classList.remove('is-error');
        statusEl.textContent = i18n.purgeWorking || 'Deleting migrated data…';
      }

      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_lite_purge_migrated');
        body.set('nonce', cfg.nonce || '');
        body.set('confirm', '1');
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || i18n.purgeError || 'Could not delete migrated data.');
        }
        const data = json.data || {};
        if (statusEl) statusEl.textContent = data.message || '';
        window.location.href = data.reload || window.location.href;
      } catch (err) {
        if (statusEl) {
          statusEl.classList.add('is-error');
          statusEl.textContent = err.message || String(err);
        }
        btn.disabled = false;
      }
    });
  }

  function initComplete() {
    const btn = document.getElementById('ns-lite-complete-btn');
    const input = document.getElementById('ns-lite-confirm');
    const panel = document.getElementById('ns-lite-complete-panel');
    const progress = document.getElementById('ns-lite-progress');
    const labelEl = document.getElementById('ns-lite-progress-label');
    const statusEl = document.getElementById('ns-lite-progress-status');
    const bar = document.getElementById('ns-lite-progress-bar');
    const fill = document.getElementById('ns-lite-progress-fill');
    if (!btn || !input || !progress) return;

    const stepLabels = {
      migrate: i18n.stepMigrate || 'Migrating schema snapshots…',
      prepare: i18n.stepPrepare || 'Preparing Noble Schema removal…',
      remove_parent: i18n.stepRemove || 'Removing Noble Schema…',
      finish: i18n.stepFinish || 'Finishing up…',
    };

    function setProgress(percent, label, status, indeterminate) {
      if (labelEl) labelEl.textContent = label || (i18n.working || 'Working…');
      if (statusEl) statusEl.textContent = status || '';
      if (fill) {
        fill.classList.toggle('is-indeterminate', !!indeterminate);
        if (!indeterminate) {
          fill.style.width = Math.max(0, Math.min(100, Number(percent) || 0)) + '%';
        } else {
          fill.style.width = '';
        }
      }
      if (bar) bar.setAttribute('aria-valuenow', String(Math.round(Number(percent) || 0)));
    }

    async function runStep(step, confirm) {
      const body = new URLSearchParams();
      body.set('action', 'ns_lite_complete_step');
      body.set('nonce', cfg.nonce || '');
      body.set('confirm', confirm);
      body.set('step', step);
      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      });
      const json = await res.json();
      if (!json || !json.success) {
        throw new Error((json && json.data && json.data.message) || i18n.error || 'Offboarding failed.');
      }
      return json.data || {};
    }

    btn.addEventListener('click', async () => {
      const confirm = (input.value || '').trim();
      if (confirm !== (cfg.phrase || 'OFFBOARD')) {
        window.alert(i18n.phraseMismatch || 'Confirmation phrase did not match.');
        input.focus();
        return;
      }
      if (!window.confirm(i18n.confirmMessage || 'Complete offboarding?')) {
        return;
      }

      btn.disabled = true;
      input.disabled = true;
      if (panel) panel.hidden = true;
      progress.hidden = false;
      setProgress(8, i18n.working || 'Working…', '', true);

      let step = 'migrate';
      let manual = false;
      try {
        while (step) {
          setProgress(
            step === 'migrate' ? 15 : step === 'prepare' ? 45 : step === 'remove_parent' ? 70 : 90,
            stepLabels[step] || (i18n.working || 'Working…'),
            '',
            true
          );
          const data = await runStep(step, confirm);
          manual = manual || !!data.manual;
          setProgress(data.percent || 0, stepLabels[step] || (i18n.working || 'Working…'), data.message || '', false);
          if (data.done) {
            setProgress(100, i18n.done || 'Offboarding complete.', data.message || '', false);
            const url = data.reload || window.location.href;
            window.location.href = manual ? (url + (url.indexOf('?') >= 0 ? '&' : '?') + 'ns_lite_manual=1') : url;
            return;
          }
          step = data.next || null;
        }
      } catch (err) {
        setProgress(0, i18n.error || 'Offboarding failed.', err.message || String(err), false);
        btn.disabled = false;
        input.disabled = false;
        if (panel) panel.hidden = false;
      }
    });
  }

  if (cfg.complete) {
    initPurge();
  } else {
    initComplete();
  }
})();
JS;
    }
}
