<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \NobleSchema\Admin\Config_List_Table $table */
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Schema Config', 'noble-schema'); ?></h1>
    <a
        href="<?php echo esc_url(\NobleSchema\Admin\Language_Guard::switch_url(
            \NobleSchema\Admin\Menu::page_url(['action' => 'add'])
        )); ?>"
        class="page-title-action <?php echo esc_attr(\NobleSchema\Admin\Language_Guard::link_class()); ?>"
        <?php echo \NobleSchema\Admin\Language_Guard::confirm_data_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes are escaped in the guard. ?>
    >
        <?php esc_html_e('Add New', 'noble-schema'); ?>
    </a>
    <a
        href="<?php echo esc_url(\NobleSchema\Admin\Tools_Page::page_url()); ?>"
        class="page-title-action ns-tools-link"
        aria-label="<?php esc_attr_e('Schema Tools', 'noble-schema'); ?>"
        title="<?php esc_attr_e('Schema Tools', 'noble-schema'); ?>"
    >
        <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
    </a>
    <a
        href="<?php echo esc_url(\NobleSchema\Admin\Settings_Page::page_url()); ?>"
        class="page-title-action ns-tools-link"
        aria-label="<?php esc_attr_e('Schema Settings', 'noble-schema'); ?>"
        title="<?php esc_attr_e('Schema Settings', 'noble-schema'); ?>"
    >
        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
    </a>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="schema-config">
        <?php $table->display(); ?>
    </form>
</div>
