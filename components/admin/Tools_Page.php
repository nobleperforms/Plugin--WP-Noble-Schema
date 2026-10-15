<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\Generator\Regen_Queue;
use NobleSchema\Targeting\Archive_Context;
use NobleSchema\Tools;

class Tools_Page {
    private string $plugin_file;

    public function __construct(string $plugin_file) {
        $this->plugin_file = $plugin_file;
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_menu', [$this, 'maybe_hide_menu'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void {
        $hook = add_management_page(
            __('Schema Tools', 'noble-schema'),
            __('Schema Tools', 'noble-schema'),
            'manage_options',
            'schema-tools',
            [$this, 'render']
        );
        add_action("load-{$hook}", [$this, 'prepare_document_title']);
    }

    /**
     * Point admin chrome at the active tool label and build a custom <title>.
     */
    public function prepare_document_title(): void {
        $tool = self::current_tool_slug();
        $titles = self::tool_titles();
        $GLOBALS['title'] = ($tool !== '' && isset($titles[$tool]))
            ? $titles[$tool]
            : __('Schema Tools', 'noble-schema');

        add_filter('admin_title', [$this, 'filter_admin_title'], 10, 2);
    }

    /**
     * @param string $admin_title Full title WordPress would use.
     * @param string $title       Page title from get_admin_page_title().
     */
    public function filter_admin_title(string $admin_title, string $title): string {
        return self::document_title(self::current_tool_slug());
    }

    /**
     * Labels keyed by `tool` query arg. Matches each tool page H1.
     *
     * @return array<string, string>
     */
    public static function tool_titles(): array {
        return [
            'storefront'          => __('Schema Storefront', 'noble-schema'),
            'batch-regen'         => __('Batch Regenerate', 'noble-schema'),
            'sitewide-analytics'  => __('Sitewide Analytics', 'noble-schema'),
            'checker'             => __('Page Debugger', 'noble-schema'),
            'logs'                => __('Logs', 'noble-schema'),
            'transfer'            => __('Export / Import', 'noble-schema'),
            'data-removal'        => __('Data Removal', 'noble-schema'),
        ];
    }

    /**
     * Resolve the active tool slug the same way render() does.
     */
    public static function current_tool_slug(): string {
        $tool = isset($_GET['tool']) ? sanitize_key(wp_unslash((string) $_GET['tool'])) : '';
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

        // Deep link with post_id opens the page debugger.
        if ($tool === '' && $post_id > 0) {
            return 'checker';
        }

        return isset(self::tool_titles()[$tool]) ? $tool : '';
    }

    /**
     * Browser title: "{Tool} ‹ Schema Tools ‹ {Site}" or hub without a tool segment.
     */
    public static function document_title(?string $tool = null, ?string $site_name = null): string {
        $tool = $tool ?? self::current_tool_slug();
        $site_name = $site_name ?? (string) get_bloginfo('name');
        $tools_label = __('Schema Tools', 'noble-schema');
        $titles = self::tool_titles();

        $parts = [];
        if ($tool !== '' && isset($titles[$tool])) {
            $parts[] = $titles[$tool];
        }
        $parts[] = $tools_label;
        if ($site_name !== '') {
            $parts[] = $site_name;
        }

        // Match WordPress admin separators (&lsaquo;).
        return implode(' &lsaquo; ', $parts);
    }

    public function maybe_hide_menu(): void {
        if (!Access::can_manage_schema()) {
            remove_submenu_page('tools.php', 'schema-tools');
        }
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'tools_page_schema-tools') {
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
            $tool = isset($_GET['tool']) ? sanitize_key(wp_unslash((string) $_GET['tool'])) : '';

            wp_localize_script('noble-schema-admin', 'nsNobleSchemaAdmin', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'postsNonce' => wp_create_nonce('ns_search_posts'),
                'toolsNonce' => wp_create_nonce('ns_schema_tools'),
                'regenNonce' => wp_create_nonce('ns_regen'),
                'batchRegenUrl' => self::page_url(['tool' => 'batch-regen']),
                'configEditUrl' => Menu::page_url(['action' => 'edit', 'config_id' => 0]),
                'autoStart'  => $tool === 'batch-regen' && (
                    !empty($regen_status['remaining']) || !empty($regen_status['cancelled'])
                ),
                'status'     => $regen_status,
                'i18n'       => [
                    'ok'            => __('OK', 'noble-schema'),
                    'cancel'        => __('Cancel', 'noble-schema'),
                    'alertTitle'    => __('Notice', 'noble-schema'),
                    'confirmTitle'  => __('Please confirm', 'noble-schema'),
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
                    'regenCancelTitle' => __('Cancel schema update?', 'noble-schema'),
                    'regenCancelConfirm' => __('Stop updating schema on remaining posts? Posts already updated will keep their new schema.', 'noble-schema'),
                    'regenCancelKeep' => __('Keep updating', 'noble-schema'),
                    'regenCancelled' => __('Schema update cancelled.', 'noble-schema'),
                    'regenCancelledLocal' => __('Update cancelled successfully.', 'noble-schema'),
                    'regenCancelledRemote' => __('This action was cancelled by another user/window.', 'noble-schema'),
                    'toolsSearchPlaceholder' => __('Search by title or ID…', 'noble-schema'),
                    'toolsNoResults' => __('No posts found.', 'noble-schema'),
                    'toolsSelectPost' => __('Select a post to inspect its schema.', 'noble-schema'),
                    'toolsLoading'  => __('Loading…', 'noble-schema'),
                    'toolsLoadError'=> __('Could not load schema report.', 'noble-schema'),
                    'toolsRegenTitle' => __('Regenerate schema?', 'noble-schema'),
                    'toolsRegenConfirm' => __('Regenerate schema for this post? The stored snapshot will be replaced.', 'noble-schema'),
                    'toolsRefresh'  => __('Refresh', 'noble-schema'),
                    'toolsRegen'    => __('Regenerate schema', 'noble-schema'),
                    'toolsAdvancedMode' => __('Advanced mode', 'noble-schema'),
                    'toolsValidationTitle' => __('Validation', 'noble-schema'),
                    'toolsCopyJson' => __('Copy JSON', 'noble-schema'),
                    'toolsCopied'   => __('Copied', 'noble-schema'),
                    'toolsCopyFailed' => __('Could not copy.', 'noble-schema'),
                    'toolsRegenUnchanged' => __('Schema unchanged after regenerate.', 'noble-schema'),
                    'toolsRegenChanged' => __('Schema regenerated. Changes detected.', 'noble-schema'),
                    'toolsBefore'   => __('Before', 'noble-schema'),
                    'toolsAfter'    => __('After', 'noble-schema'),
                    'toolsMissingSnapshot' => __('No snapshot stored', 'noble-schema'),
                    'toolsWouldPrint' => __('Would print on frontend', 'noble-schema'),
                    'toolsWouldNotPrint' => __('Would not print on frontend', 'noble-schema'),
                    'toolsMatched'  => __('Matched', 'noble-schema'),
                    'toolsNotMatched' => __('Not matched', 'noble-schema'),
                    'toolsGenerationTitle' => __('Generation', 'noble-schema'),
                    'toolsGenerationYes' => __('Would generate schema', 'noble-schema'),
                    'toolsGenerationNo' => __('Would not generate schema', 'noble-schema'),
                    'toolsGenerationFields' => __('Resolved fields', 'noble-schema'),
                    'toolsGenerationEmpty' => __('No matched active configs to evaluate.', 'noble-schema'),
                    'toolsPendingRegen' => __('Pending in regen queue', 'noble-schema'),
                    'toolsNotPendingRegen' => __('Not pending in regen queue', 'noble-schema'),
                    'toolsLogsTitle' => __('System logs', 'noble-schema'),
                    'toolsLogsEmpty' => __('No log entries for this page.', 'noble-schema'),
                    'toolsLogsDisabled' => __('System logging is off.', 'noble-schema'),
                    'toolsLogsViewAll' => __('View all logs', 'noble-schema'),
                    'toolsLogsOpenSettings' => __('Open Logs', 'noble-schema'),
                    'batchTargetRequired' => __('Select at least one target, or choose Global.', 'noble-schema'),
                    'batchConfirmTitle' => __('Start batch regeneration?', 'noble-schema'),
                    /* translators: %s: formatted post count */
                    'batchConfirm' => __('Regenerate schema for the selected targets? This will update %s posts.', 'noble-schema'),
                    'batchStart' => __('Start regeneration', 'noble-schema'),
                    'batchStarted' => __('Regeneration started.', 'noble-schema'),
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
                    'storefrontLoadingVocab' => __('Loading schema.org types…', 'noble-schema'),
                    'storefrontRefresh' => __('Refresh types', 'noble-schema'),
                    'storefrontRefreshing' => __('Refreshing types', 'noble-schema'),
                    'storefrontVocabReady' => __('Cached · %1$s types · refreshed %2$s', 'noble-schema'),
                    'storefrontVocabEmpty' => __('Types not loaded yet.', 'noble-schema'),
                    'storefrontNoTypes' => __('No types match your search.', 'noble-schema'),
                    'storefrontLoadingType' => __('Loading type…', 'noble-schema'),
                    'storefrontSelectType' => __('Select a schema.org type to choose properties and save a template.', 'noble-schema'),
                    'storefrontPropsOwn' => __('Defined on this type', 'noble-schema'),
                    'storefrontPropsInherited' => __('Inherited', 'noble-schema'),
                    'storefrontSave' => __('Save template', 'noble-schema'),
                    'storefrontSaving' => __('Saving…', 'noble-schema'),
                    'storefrontNeedProps' => __('Select at least one property.', 'noble-schema'),
                    'storefrontOverwriteTitle' => __('Overwrite template?', 'noble-schema'),
                    'storefrontShadowTitle' => __('Shadow built-in template?', 'noble-schema'),
                    'storefrontOverwrite' => __('Overwrite', 'noble-schema'),
                    'storefrontContinue' => __('Continue', 'noble-schema'),
                    'storefrontSavedTitle' => __('Template saved', 'noble-schema'),
                    'storefrontAddConfig' => __('Add Schema Config', 'noble-schema'),
                    'storefrontLoadError' => __('Could not load schema.org types.', 'noble-schema'),
                    'storefrontManage' => __('Manage Custom Templates', 'noble-schema'),
                    'storefrontManageTitle' => __('Custom templates', 'noble-schema'),
                    'storefrontManageEmpty' => __('No custom templates yet.', 'noble-schema'),
                    'storefrontManageLoading' => __('Loading…', 'noble-schema'),
                    'storefrontManageLoadError' => __('Could not load custom templates.', 'noble-schema'),
                    'storefrontDeleteTitle' => __('Delete custom template?', 'noble-schema'),
                    /* translators: %s: template label */
                    'storefrontDeleteConfirm' => __('Delete “%s”? This cannot be undone.', 'noble-schema'),
                    'storefrontDelete' => __('Delete', 'noble-schema'),
                    'storefrontDeleting' => __('Deleting…', 'noble-schema'),
                    'storefrontDeleted' => __('Template deleted.', 'noble-schema'),
                    'storefrontNotWritable' => __('Custom templates can’t be saved because the uploads templates directory is not writable on this environment.', 'noble-schema'),
                    'storefrontNotWritableDelete' => __('Custom templates can’t be deleted because the uploads templates directory is not writable on this environment.', 'noble-schema'),
                    'viewerSelectPost' => __('Select a post to view its schema.', 'noble-schema'),
                    'viewerLoading' => __('Loading schema…', 'noble-schema'),
                    'viewerLoadError' => __('Could not load schema.', 'noble-schema'),
                    'viewerNoSchema' => __('No schema available for this post.', 'noble-schema'),
                    'viewerStored' => __('Stored', 'noble-schema'),
                    'viewerLive' => __('Live generate', 'noble-schema'),
                    'viewerPretty' => __('Pretty', 'noble-schema'),
                    'viewerRaw' => __('Raw', 'noble-schema'),
                    'viewerCopy' => __('Copy JSON', 'noble-schema'),
                    'viewerCopied' => __('Copied', 'noble-schema'),
                    'viewerSchemaOrg' => __('Schema.org Validator', 'noble-schema'),
                    'viewerRichResults' => __('Rich Results Test', 'noble-schema'),
                    'viewerExternalHint' => __('Validates the published page URL (what crawlers see), not the Live generate preview.', 'noble-schema'),
                    'viewerValidationOk' => __('Passed structural checks', 'noble-schema'),
                    'viewerValidationFail' => __('Structural issues found', 'noble-schema'),
                    /* translators: 1: error count, 2: warning count */
                    'viewerValidationSummary' => __('%1$s errors · %2$s warnings', 'noble-schema'),
                    'viewerSourceStored' => __('Showing stored snapshot', 'noble-schema'),
                    'viewerSourceLive' => __('Showing live generate preview (not saved)', 'noble-schema'),
                    'viewerSiteCoverage' => __('Page-specific coverage', 'noble-schema'),
                    'viewerSiteOf' => __('%1$s of %2$s posts have page-specific schema', 'noble-schema'),
                    'viewerSiteSitewide' => __('Sitewide scripts', 'noble-schema'),
                    'viewerSiteSitewideHelpTitle' => __('Sitewide scripts', 'noble-schema'),
                    'viewerSiteSitewideHelpIntro' => __('Sitewide scripts are schema that apply broadly across the site. They are listed separately so they do not inflate page-specific coverage %.', 'noble-schema'),
                    'viewerSiteSitewideHelp' => [
                        __('Configured sitewide types — Schema types selected under Schema Settings → Sitewide schema types. Organization, WebSite and WebPage are always included.', 'noble-schema'),
                        __('Sitewide / All pages configs — Schema configs with Targeting → Sitewide or All pages also count as sitewide, regardless of type.', 'noble-schema'),
                        __('Edit the type list anytime in Schema Settings. The setting is included when you Export / Import plugin settings.', 'noble-schema'),
                    ],
                    'viewerSiteScan' => __('Scan for issues', 'noble-schema'),
                    'viewerSiteScanning' => __('Scanning…', 'noble-schema'),
                    'viewerSiteCancelScan' => __('Cancel scan', 'noble-schema'),
                    'viewerSiteScanDone' => __('Scan complete', 'noble-schema'),
                    'viewerSiteLoadError' => __('Could not load site coverage.', 'noble-schema'),
                    'viewerSiteOpenPage' => __('Open in Page Debugger', 'noble-schema'),
                    'viewerSiteAllTypes' => __('All post types', 'noble-schema'),
                    'viewerSiteAllReasons' => __('All issue types', 'noble-schema'),
                    'viewerSiteNoRows' => __('No posts match these filters.', 'noble-schema'),
                    'viewerSiteLastScan' => __('Last scan: %s', 'noble-schema'),
                    'viewerSiteTiersHelpTitle' => __('Coverage tiers', 'noble-schema'),
                    'viewerSiteTiersHelpIntro' => __('Tiers describe how much page-specific schema a URL has—not just whether any JSON-LD exists. Sitewide-only schema does not count toward coverage %.', 'noble-schema'),
                    'viewerSiteTiersHelp' => [
                        __('None — No stored schema graph for that post (a coverage gap).', 'noble-schema'),
                        __('Sitewide only — Only sitewide types / Sitewide or All pages configs are present (no page-specific schema). Does not count toward coverage %.', 'noble-schema'),
                        __('Page schema — incomplete — A page-targeted config matches, but the snapshot is not complete yet.', 'noble-schema'),
                        __('Page schema — complete — Page-specific schema is stored and complete, but structural validation failed (or it is not validated yet). Counts toward coverage %.', 'noble-schema'),
                        __('Validated — Page-specific schema is complete and passes structural checks. Counts toward coverage %.', 'noble-schema'),
                    ],
                    'viewerSiteTier' => [
                        'none' => __('None', 'noble-schema'),
                        'sitewide_only' => __('Sitewide only', 'noble-schema'),
                        'page_incomplete' => __('Page schema — incomplete', 'noble-schema'),
                        'page_complete' => __('Page schema — complete', 'noble-schema'),
                        'validated' => __('Validated', 'noble-schema'),
                    ],
                    'transferExporting' => __('Preparing export…', 'noble-schema'),
                    'transferExportReady' => __('Download started.', 'noble-schema'),
                    'transferExportError' => __('Export failed.', 'noble-schema'),
                    'transferNeedConfig' => __('Select a schema config to export.', 'noble-schema'),
                    'transferNeedFile' => __('Choose an export file first.', 'noble-schema'),
                    'transferPreviewing' => __('Reading export…', 'noble-schema'),
                    'transferPreviewError' => __('Could not read export file.', 'noble-schema'),
                    'transferApplying' => __('Importing…', 'noble-schema'),
                    'transferApplyError' => __('Import failed.', 'noble-schema'),
                    'transferConflict' => __('Conflict', 'noble-schema'),
                    'transferOverwrite' => __('Overwrite', 'noble-schema'),
                    'transferSkip' => __('Skip', 'noble-schema'),
                    'transferCreate' => __('Import as new', 'noble-schema'),
                    'transferTemplates' => __('Custom templates', 'noble-schema'),
                    'transferConfigs' => __('Schema configs', 'noble-schema'),
                    'transferSettings' => __('Plugin settings', 'noble-schema'),
                    'transferNoItems' => __('Nothing to import in this pack.', 'noble-schema'),
                    'transferConfirmTitle' => __('Confirm import?', 'noble-schema'),
                    'transferConfirm' => __('Apply the selected import actions? This may overwrite existing configs or custom templates.', 'noble-schema'),
                    'transferBatchPromptTitle' => __('Run batch regenerate?', 'noble-schema'),
                    'transferBatchPrompt' => __('Import finished. Would you like to open Batch Regenerate to rewrite schema for matching posts?', 'noble-schema'),
                    'transferBatchPromptYes' => __('Open Batch Regenerate', 'noble-schema'),
                    'transferBatchPromptNo' => __('Not now', 'noble-schema'),
                    'dataRemovalDeleting' => __('Deleting…', 'noble-schema'),
                    'dataRemovalError' => __('Data removal failed.', 'noble-schema'),
                    'dataRemovalDelete' => __('Delete', 'noble-schema'),
                    'dataRemovalTypeDelete' => __('Type DELETE to confirm:', 'noble-schema'),
                    'dataRemovalConfirmPhrase' => 'DELETE',
                    'dataRemovalNotWritable' => __('Custom templates can’t be deleted because the uploads templates directory is not writable on this environment.', 'noble-schema'),
                    'offboardTitle' => __('Perform offboarding?', 'noble-schema'),
                    'offboardMessage' => __('This installs Schema Lite and starts irreversible client offboarding. You will finish removal from Schema Lite’s settings page.', 'noble-schema'),
                    'offboardDetails' => [
                        __('Copies and activates wp-noble-schema-lite into wp-content/plugins', 'noble-schema'),
                        __('Requires a writable plugins directory', 'noble-schema'),
                        __('Completing offboarding migrates schema JSON, then removes Noble Schema and its project data', 'noble-schema'),
                    ],
                    'offboardConfirmPhrase' => 'OFFBOARD',
                    'offboardConfirmLabel' => __('Start offboarding', 'noble-schema'),
                    'offboardStarting' => __('Installing Schema Lite…', 'noble-schema'),
                    'offboardError' => __('Offboarding failed.', 'noble-schema'),
                    'offboardNotWritable' => __('Offboarding requires a writable wp-content/plugins directory.', 'noble-schema'),
                    'dataRemovalScopes' => [
                        'everything' => [
                            'title' => __('Delete everything?', 'noble-schema'),
                            'message' => __('This permanently removes Noble Schema project data. The plugin stays installed.', 'noble-schema'),
                            'details' => [
                                __('Plugin settings (GitHub updates, access, logging)', 'noble-schema'),
                                __('All custom templates', 'noble-schema'),
                                __('All schema configs, targeting, field maps and manual values', 'noble-schema'),
                                __('All snapshots and runtime caches', 'noble-schema'),
                                __('All system log entries', 'noble-schema'),
                            ],
                        ],
                        'everything_no_templates' => [
                            'title' => __('Delete everything?', 'noble-schema'),
                            'message' => __('This permanently removes Noble Schema project data. Custom templates will be skipped because the uploads templates directory isn’t writable. The plugin stays installed.', 'noble-schema'),
                            'details' => [
                                __('Plugin settings (GitHub updates, access, logging)', 'noble-schema'),
                                __('All schema configs, targeting, field maps and manual values', 'noble-schema'),
                                __('All snapshots and runtime caches', 'noble-schema'),
                                __('All system log entries', 'noble-schema'),
                                __('Custom templates skipped (not writable)', 'noble-schema'),
                            ],
                        ],
                        'settings' => [
                            'title' => __('Delete plugin settings?', 'noble-schema'),
                            'message' => __('This permanently clears project settings (GitHub, access, logging toggles).', 'noble-schema'),
                            'details' => [
                                __('Enable updates, username, repository and token', 'noble-schema'),
                                __('Delete-all-data-on-uninstall toggle', 'noble-schema'),
                                __('Access restriction and system logging options', 'noble-schema'),
                            ],
                        ],
                        'templates' => [
                            'title' => __('Delete custom templates?', 'noble-schema'),
                            'message' => __('This permanently deletes custom template files in uploads/noble-schema/templates. Bundled plugin templates are kept.', 'noble-schema'),
                            'details' => [
                                __('All custom template JSON files', 'noble-schema'),
                            ],
                        ],
                        'configs' => [
                            'title' => __('Delete all schema configs?', 'noble-schema'),
                            'message' => __('This permanently removes configs and generated output.', 'noble-schema'),
                            'details' => [
                                __('All schema configs, targeting, field maps and manual values', 'noble-schema'),
                                __('All snapshots and runtime caches', 'noble-schema'),
                            ],
                        ],
                        'snapshots' => [
                            'title' => __('Delete snapshots and caches?', 'noble-schema'),
                            'message' => __('This permanently clears generated JSON-LD and runtime caches. Configs and settings are kept.', 'noble-schema'),
                            'details' => [
                                __('All stored snapshots', 'noble-schema'),
                                __('Archive and block-usage caches', 'noble-schema'),
                                __('Pending regen job state', 'noble-schema'),
                            ],
                        ],
                        'logs' => [
                            'title' => __('Delete system logs?', 'noble-schema'),
                            'message' => __('This permanently deletes all stored system log entries. Logging stays enabled if it was on.', 'noble-schema'),
                            'details' => [
                                __('All rows in the system logs table', 'noble-schema'),
                            ],
                        ],
                    ],
                    'logsLoading' => __('Loading logs…', 'noble-schema'),
                    'logsError' => __('Could not load logs.', 'noble-schema'),
                    'logsPolling' => __('Live', 'noble-schema'),
                    'logsPaused' => __('Paused', 'noble-schema'),
                    'logsPause' => __('Pause', 'noble-schema'),
                    'logsResume' => __('Resume', 'noble-schema'),
                    'logsEmpty' => __('No log entries exist yet.', 'noble-schema'),
                    'logsEmptyFiltered' => __('No log entries match the current filters.', 'noble-schema'),
                    'logsDetail' => __('Details', 'noble-schema'),
                    'logsLevel' => __('Level', 'noble-schema'),
                    'logsCategory' => __('Category', 'noble-schema'),
                    'logsLoadMore' => __('Load more', 'noble-schema'),
                    'logsLoadingMore' => __('Loading…', 'noble-schema'),
                    'logsClearTitle' => __('Delete all logs?', 'noble-schema'),
                    'logsClearBody' => __('This permanently deletes all stored system log entries. Logging stays enabled.', 'noble-schema'),
                    'logsClearConfirm' => __('Delete all logs', 'noble-schema'),
                    'logsClearing' => __('Deleting…', 'noble-schema'),
                    'logsClearError' => __('Could not delete logs.', 'noble-schema'),
                ],
                'transferTab' => isset($_GET['transfer_tab'])
                    ? sanitize_key(wp_unslash((string) $_GET['transfer_tab']))
                    : '',
                'onboarding' => Onboarding::localize_payload(),
            ]);
        }
    }

    public function render(): void {
        if (!\NobleSchema\Admin\Access::can_manage_schema()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'noble-schema'));
        }

        $tool = isset($_GET['tool']) ? sanitize_key(wp_unslash((string) $_GET['tool'])) : '';
        $initial_post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        $initial_url = isset($_GET['url']) ? esc_url_raw(wp_unslash((string) $_GET['url'])) : '';
        $initial_specialty = isset($_GET['specialty_key']) ? sanitize_key(wp_unslash((string) $_GET['specialty_key'])) : '';
        $initial_archive = isset($_GET['archive_key']) ? sanitize_text_field(wp_unslash((string) $_GET['archive_key'])) : '';

        // Deep link with post_id / url / specialty / archive opens the page debugger.
        if ($tool === '' && ($initial_post_id > 0 || $initial_url !== '' || $initial_specialty !== '' || $initial_archive !== '')) {
            $tool = 'checker';
        }

        $post_types = get_post_types(['public' => true], 'objects');
        $page_templates = wp_get_theme()->get_page_templates();
        $archive_pages = Archive_Context::available_archives();
        $specialty_pages = \NobleSchema\Targeting\Specialty_Context::available();

        include dirname(__DIR__) . '/views/tools.php';
        include dirname(__DIR__) . '/views/partials/dialog-modal.php';
        if (in_array($tool, ['checker', 'batch-regen'], true)) {
            include dirname(__DIR__) . '/views/partials/regen-modal.php';
        }
    }

    public static function page_url(array $args = []): string {
        return add_query_arg(
            array_merge(['page' => 'schema-tools'], $args),
            admin_url('tools.php')
        );
    }
}
