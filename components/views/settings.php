<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var bool $enable_updates */
/** @var string $gh_username */
/** @var string $gh_repo */
/** @var string $gh_token */
/** @var bool $using_predefined */
/** @var bool $delete_on_uninstall */
/** @var bool $restrict_access */
/** @var list<array{value:string,label:string}> $admin_user_options */
/** @var list<int> $admin_user_ids */
/** @var list<string> $locked_admin_user_ids */
/** @var bool $logging_enabled */
/** @var string $logging_retention */
/** @var array<string, string> $logging_retention_choices */
/** @var list<string> $sitewide_schema_types */
/** @var list<array{value:string,label:string}> $sitewide_type_options */
/** @var list<string> $sitewide_locked_types */
/** @var array<string, mixed> $update_last_check */
$enable_updates = !empty($enable_updates);
$update_last_check = is_array($update_last_check ?? null) ? $update_last_check : [];
$using_predefined = !empty($using_predefined);
$delete_on_uninstall = !empty($delete_on_uninstall);
$restrict_access = !empty($restrict_access);
$logging_enabled = !empty($logging_enabled);
$logging_retention = (string) ($logging_retention ?? '3m');
$logging_retention_choices = is_array($logging_retention_choices ?? null) ? $logging_retention_choices : [];
$sitewide_schema_types = is_array($sitewide_schema_types ?? null)
    ? $sitewide_schema_types
    : \NobleSchema\Admin\Settings_Page::sitewide_schema_types();
$sitewide_type_options = is_array($sitewide_type_options ?? null) ? $sitewide_type_options : [];
$sitewide_locked_types = is_array($sitewide_locked_types ?? null)
    ? $sitewide_locked_types
    : \NobleSchema\Admin\Settings_Page::default_sitewide_schema_types();
$admin_user_options = is_array($admin_user_options ?? null) ? $admin_user_options : [];
$admin_user_ids = is_array($admin_user_ids ?? null) ? $admin_user_ids : [];
$locked_admin_user_ids = is_array($locked_admin_user_ids ?? null) ? $locked_admin_user_ids : [];
?>
<div class="wrap ns-settings-wrap" id="ns-schema-settings">
    <h1 class="wp-heading-inline"><?php esc_html_e('Schema Settings', 'noble-schema'); ?></h1>
    <a
        href="<?php echo esc_url(\NobleSchema\Admin\Menu::page_url()); ?>"
        class="page-title-action ns-tools-link"
        aria-label="<?php esc_attr_e('Schema Config', 'noble-schema'); ?>"
        title="<?php esc_attr_e('Schema Config', 'noble-schema'); ?>"
    >
        <span class="dashicons dashicons-menu" aria-hidden="true"></span>
    </a>
    <a
        href="<?php echo esc_url(\NobleSchema\Admin\Tools_Page::page_url()); ?>"
        class="page-title-action ns-tools-link"
        aria-label="<?php esc_attr_e('Schema Tools', 'noble-schema'); ?>"
        title="<?php esc_attr_e('Schema Tools', 'noble-schema'); ?>"
    >
        <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
    </a>
    <hr class="wp-header-end">
    <p class="description">
        <?php esc_html_e('Administrative Noble Schema plugin settings and configurations.', 'noble-schema'); ?>
    </p>

    <?php if (!empty($_GET['settings-updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'noble-schema'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="options.php" class="ns-settings-form">
        <?php settings_fields(\NobleSchema\Admin\Settings_Page::OPTION_GROUP); ?>

        <div class="ns-tools-card">
            <h2 class="ns-tools-card__title"><?php esc_html_e('Automatic plugin updates', 'noble-schema'); ?></h2>
            <p class="description">
                <?php esc_html_e('Enable this option to install automatic plugin updates directly from a managed Github repository.', 'noble-schema'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Updates', 'noble-schema'); ?></th>
                    <td>
                        <label class="ns-toggle" for="ns-enable-updates">
                            <span class="ns-toggle__control">
                                <input
                                    id="ns-enable-updates"
                                    type="checkbox"
                                    name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_ENABLE); ?>"
                                    value="1"
                                    <?php checked($enable_updates); ?>
                                >
                                <span class="ns-toggle__ui" aria-hidden="true"></span>
                            </span>
                            <span class="ns-toggle__label"><?php esc_html_e('Enable automatic checks for plugin updates', 'noble-schema'); ?></span>
                        </label>
                    </td>
                </tr>
                <tr class="ns-gh-settings-row"<?php echo $enable_updates ? '' : ' hidden'; ?>>
                    <th scope="row">
                        <label for="ns-gh-username"><?php esc_html_e('GitHub username', 'noble-schema'); ?></label>
                    </th>
                    <td>
                        <input
                            id="ns-gh-username"
                            class="regular-text"
                            type="text"
                            name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_USERNAME); ?>"
                            value="<?php echo esc_attr($gh_username); ?>"
                            autocomplete="off"
                        >
                        <p class="description"><?php esc_html_e('Organization or user that owns the repository (e.g. noblestudios).', 'noble-schema'); ?></p>
                    </td>
                </tr>
                <tr class="ns-gh-settings-row"<?php echo $enable_updates ? '' : ' hidden'; ?>>
                    <th scope="row">
                        <label for="ns-gh-repo"><?php esc_html_e('Repository name', 'noble-schema'); ?></label>
                    </th>
                    <td>
                        <input
                            id="ns-gh-repo"
                            class="regular-text"
                            type="text"
                            name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_REPO); ?>"
                            value="<?php echo esc_attr($gh_repo); ?>"
                            autocomplete="off"
                        >
                        <p class="description"><?php esc_html_e('Repository slug (e.g. wp-noble-schema).', 'noble-schema'); ?></p>
                    </td>
                </tr>
                <tr class="ns-gh-settings-row"<?php echo $enable_updates ? '' : ' hidden'; ?>>
                    <th scope="row">
                        <label for="ns-gh-token"><?php esc_html_e('GitHub token', 'noble-schema'); ?></label>
                    </th>
                    <td>
                        <input
                            id="ns-gh-token"
                            class="regular-text"
                            type="password"
                            name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_TOKEN); ?>"
                            value="<?php echo esc_attr($gh_token); ?>"
                            autocomplete="new-password"
                            <?php disabled($using_predefined); ?>
                        >
                        <?php if ($using_predefined) : ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: PHP constant name */
                                    esc_html__('This value is required for private repositories. The token is defined via the %s constant and can’t be edited here.', 'noble-schema'),
                                    '<code>' . esc_html(\NobleSchema\Admin\Settings_Page::GIT_TOKEN_CONSTANT) . '</code>'
                                );
                                ?>
                            </p>
                        <?php else : ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: PHP constant name */
                                    esc_html__('This value is required for private repositories. You can also set this by defining %s in wp-config.php.', 'noble-schema'),
                                    '<code>' . esc_html(\NobleSchema\Admin\Settings_Page::GIT_TOKEN_CONSTANT) . '</code>'
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="ns-gh-update-status" id="ns-gh-update-status"<?php echo $enable_updates ? '' : ' hidden'; ?>>
                <?php
                $status_ok = array_key_exists('ok', $update_last_check) ? !empty($update_last_check['ok']) : null;
                $status_message = (string) ($update_last_check['message'] ?? '');
                $checked_at = (int) ($update_last_check['checked_at'] ?? 0);
                $update_available = !empty($update_last_check['ok'])
                    && (($update_last_check['code'] ?? '') === 'update_available');
                $latest_version = (string) ($update_last_check['latest'] ?? '');
                $release_url = (string) ($update_last_check['release_url'] ?? '');
                if ($release_url === '' && $update_available && $latest_version !== '' && $gh_username !== '' && $gh_repo !== '') {
                    $release_url = \NobleSchema\Update\Updater::release_page_url($gh_username, $gh_repo, $latest_version);
                }
                ?>
                <p class="ns-gh-update-status__message<?php echo $status_ok === false ? ' ns-gh-update-status__message--error' : ($status_ok ? ' ns-gh-update-status__message--ok' : ''); ?>" id="ns-gh-update-status-message">
                    <?php
                    if ($status_message !== '') {
                        echo esc_html($status_message);
                    } else {
                        esc_html_e('No update check has run yet. Use “Check for updates” or visit Dashboard → Updates.', 'noble-schema');
                    }
                    ?>
                </p>
                <p
                    class="ns-gh-update-status__details"
                    id="ns-gh-update-status-details"
                    <?php echo ($update_available && $release_url !== '') ? '' : ' hidden'; ?>
                >
                    <a
                        id="ns-gh-update-details-link"
                        href="<?php echo esc_url($release_url !== '' ? $release_url : '#'); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <?php
                        if ($latest_version !== '') {
                            printf(
                                /* translators: %s: new plugin version */
                                esc_html__('View version %s details', 'noble-schema'),
                                esc_html($latest_version)
                            );
                        } else {
                            esc_html_e('View version details', 'noble-schema');
                        }
                        ?>
                    </a>
                </p>
                <p class="description" id="ns-gh-update-status-meta">
                    <?php
                    if ($checked_at > 0) {
                        printf(
                            /* translators: %s: localized date/time */
                            esc_html__('Last checked: %s', 'noble-schema'),
                            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $checked_at))
                        );
                    }
                    ?>
                </p>
                <p>
                    <button
                        type="button"
                        class="button<?php echo $update_available ? ' button-primary' : ''; ?>"
                        id="ns-gh-check-updates"
                        data-mode="<?php echo $update_available ? 'update' : 'check'; ?>"
                    >
                        <?php
                        echo $update_available
                            ? esc_html__('Update Now', 'noble-schema')
                            : esc_html__('Check for updates', 'noble-schema');
                        ?>
                    </button>
                </p>
            </div>
        </div>

        <div class="ns-tools-card">
            <h2 class="ns-tools-card__title"><?php esc_html_e('Access', 'noble-schema'); ?></h2>
            <p class="description">
                <?php esc_html_e('Limit who can administer Schema Config, Settings and Tools.', 'noble-schema'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Admin access', 'noble-schema'); ?></th>
                    <td>
                        <label class="ns-toggle" for="ns-restrict-schema-admin">
                            <span class="ns-toggle__control">
                                <input
                                    id="ns-restrict-schema-admin"
                                    type="checkbox"
                                    name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_RESTRICT); ?>"
                                    value="1"
                                    <?php checked(!empty($restrict_access)); ?>
                                >
                                <span class="ns-toggle__ui" aria-hidden="true"></span>
                            </span>
                            <span class="ns-toggle__label"><?php esc_html_e('Restrict schema admin access', 'noble-schema'); ?></span>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, only specific users can access schema administration (requires manage_options)', 'noble-schema'); ?>
                        </p>
                    </td>
                </tr>
                <tr class="ns-access-settings-row"<?php echo !empty($restrict_access) ? '' : ' hidden'; ?>>
                    <th scope="row">
                        <label for="ns-schema-admins-input"><?php esc_html_e('Allowed administrators', 'noble-schema'); ?></label>
                    </th>
                    <td>
                        <div
                            class="ns-multiselect"
                            id="ns-schema-admins"
                            data-name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_ADMIN_USER_IDS); ?>[]"
                            data-placeholder="<?php esc_attr_e('Search administrators…', 'noble-schema'); ?>"
                        >
                            <div class="ns-multiselect__pills" hidden></div>
                            <div class="ns-multiselect__control">
                                <input
                                    type="text"
                                    id="ns-schema-admins-input"
                                    class="ns-multiselect__input regular-text"
                                    autocomplete="off"
                                    placeholder="<?php esc_attr_e('Search administrators…', 'noble-schema'); ?>"
                                    aria-autocomplete="list"
                                    aria-expanded="false"
                                    aria-controls="ns-schema-admins-list"
                                >
                                <ul class="ns-multiselect__dropdown" id="ns-schema-admins-list" role="listbox" hidden></ul>
                            </div>
                            <div class="ns-multiselect__values"></div>
                            <script type="application/json" class="ns-multiselect__options"><?php
                                echo wp_json_encode(array_values($admin_user_options ?? []));
                            ?></script>
                            <script type="application/json" class="ns-multiselect__selected"><?php
                                echo wp_json_encode(array_map('strval', array_values($admin_user_ids ?? [])));
                            ?></script>
                            <script type="application/json" class="ns-multiselect__locked"><?php
                                echo wp_json_encode(array_values($locked_admin_user_ids ?? []));
                            ?></script>
                        </div>
                        <p class="description">
                            <?php esc_html_e('At least one primary user is required to administer this plugin.', 'noble-schema'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="ns-tools-card">
            <h2 class="ns-tools-card__title"><?php esc_html_e('System logs', 'noble-schema'); ?></h2>
            <p class="description">
                <?php esc_html_e('Record schema changes, imports, template actions and errors in Schema Tools → Logs.', 'noble-schema'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Logging', 'noble-schema'); ?></th>
                    <td>
                        <label class="ns-toggle" for="ns-logging-enabled">
                            <span class="ns-toggle__control">
                                <input
                                    id="ns-logging-enabled"
                                    type="checkbox"
                                    name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_LOGGING_ENABLED); ?>"
                                    value="1"
                                    <?php checked($logging_enabled); ?>
                                    data-was-enabled="<?php echo $logging_enabled ? '1' : '0'; ?>"
                                >
                                <span class="ns-toggle__ui" aria-hidden="true"></span>
                            </span>
                            <span class="ns-toggle__label"><?php esc_html_e('Enable system logs', 'noble-schema'); ?></span>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Disabling system logging permanently deletes all stored logs.', 'noble-schema'); ?>
                        </p>
                    </td>
                </tr>
                <tr
                    class="ns-logging-settings-row"
                    id="ns-logging-settings"
                    <?php echo $logging_enabled ? '' : ' hidden'; ?>
                    style="<?php echo $logging_enabled ? '' : 'display:none'; ?>"
                >
                    <th scope="row">
                        <label for="ns-logging-retention"><?php esc_html_e('Retention', 'noble-schema'); ?></label>
                    </th>
                    <td>
                        <select
                            id="ns-logging-retention"
                            name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_LOGGING_RETENTION); ?>"
                            data-initial="<?php echo esc_attr($logging_retention); ?>"
                        >
                            <?php foreach ($logging_retention_choices as $slug => $label) : ?>
                                <option
                                    value="<?php echo esc_attr((string) $slug); ?>"
                                    <?php selected($logging_retention, (string) $slug); ?>
                                >
                                    <?php echo esc_html((string) $label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Select a length of time you’d like these logs to be retained for.', 'noble-schema'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="ns-tools-card">
            <h2 class="ns-tools-card__title"><?php esc_html_e('Sitewide Analytics', 'noble-schema'); ?></h2>
            <p class="description">
                <?php esc_html_e('The following options controls how Sitewide Analytics classifies schema types.', 'noble-schema'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ns-sitewide-schema-types"><?php esc_html_e('Sitewide schema types', 'noble-schema'); ?></label>
                    </th>
                    <td>
                        <div
                            class="ns-multiselect"
                            id="ns-sitewide-schema-types"
                            data-name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_SITEWIDE_SCHEMA_TYPES); ?>[]"
                            data-placeholder="<?php esc_attr_e('Search schema types…', 'noble-schema'); ?>"
                        >
                            <div class="ns-multiselect__pills" hidden></div>
                            <div class="ns-multiselect__control">
                                <input
                                    type="text"
                                    id="ns-sitewide-schema-types-input"
                                    class="ns-multiselect__input regular-text"
                                    autocomplete="off"
                                    placeholder="<?php esc_attr_e('Search schema types…', 'noble-schema'); ?>"
                                    aria-autocomplete="list"
                                    aria-expanded="false"
                                    aria-controls="ns-sitewide-schema-types-list"
                                >
                                <ul class="ns-multiselect__dropdown" id="ns-sitewide-schema-types-list" role="listbox" hidden></ul>
                            </div>
                            <div class="ns-multiselect__values"></div>
                            <script type="application/json" class="ns-multiselect__options"><?php
                                echo wp_json_encode(array_values($sitewide_type_options));
                            ?></script>
                            <script type="application/json" class="ns-multiselect__selected"><?php
                                echo wp_json_encode(array_values($sitewide_schema_types));
                            ?></script>
                            <script type="application/json" class="ns-multiselect__locked"><?php
                                echo wp_json_encode(array_values($sitewide_locked_types));
                            ?></script>
                        </div>
                        <p class="description">
                            <?php esc_html_e('Schema types treated as sitewide do not inflate coverage percentages when reviewing sitewide schema coverage metrics.', 'noble-schema'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="ns-tools-card">
            <h2 class="ns-tools-card__title"><?php esc_html_e('Uninstall', 'noble-schema'); ?></h2>
            <p class="description">
                <?php esc_html_e('Control what happens when Noble Schema is deleted from your WordPress site.', 'noble-schema'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Data cleanup', 'noble-schema'); ?></th>
                    <td>
                        <label class="ns-toggle" for="ns-delete-on-uninstall">
                            <span class="ns-toggle__control">
                                <input
                                    id="ns-delete-on-uninstall"
                                    type="checkbox"
                                    name="<?php echo esc_attr(\NobleSchema\Admin\Settings_Page::OPT_DELETE_ON_UNINSTALL); ?>"
                                    value="1"
                                    <?php checked($delete_on_uninstall); ?>
                                >
                                <span class="ns-toggle__ui" aria-hidden="true"></span>
                            </span>
                            <span class="ns-toggle__label"><?php esc_html_e('Delete all Noble Schema data when uninstalling the plugin', 'noble-schema'); ?></span>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, uninstalling this plugin completely and irreversibly deletes data all plugin data from this site.', 'noble-schema'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Save settings', 'noble-schema')); ?>
    </form>
</div>
<?php include __DIR__ . '/partials/dialog-modal.php'; ?>
