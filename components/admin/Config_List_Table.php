<?php

namespace NobleSchema\Admin;

use NobleSchema\Db\Config_Repository;

class Config_List_Table extends \WP_List_Table {
    private Config_Repository $repo;

    public function __construct(Config_Repository $repo) {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        parent::__construct([
            'singular' => 'config',
            'plural'   => 'configs',
            'ajax'     => false,
        ]);

        $this->repo = $repo;
    }

    public function get_columns(): array {
        return [
            'name'       => __('Name', 'noble-schema'),
            'schema'     => __('Schema Type', 'noble-schema'),
            'placement'  => __('Placement', 'noble-schema'),
            'status'     => __('Status', 'noble-schema'),
            'updated_at' => __('Updated', 'noble-schema'),
        ];
    }

    public function prepare_items(): void {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'updated_at';
        $order   = isset($_GET['order']) ? strtoupper(sanitize_key(wp_unslash($_GET['order']))) : 'DESC';

        $this->items = $this->repo->all([
            'orderby' => $orderby,
            'order'   => $order,
        ]);
    }

    public function no_items(): void {
        $add_url = Menu::page_url(['action' => 'add']);
        ?>
        <div class="ns-empty-state">
            <p class="ns-empty-state__message"><?php esc_html_e('You haven’t created any schema configs yet.', 'noble-schema'); ?></p>
            <p class="ns-empty-state__hint"><?php esc_html_e('Schema configs define structured data rules for your content.', 'noble-schema'); ?></p>
            <a href="<?php echo esc_url($add_url); ?>" class="button button-primary">
                <?php esc_html_e('Create a schema config', 'noble-schema'); ?>
            </a>
        </div>
        <?php
    }

    protected function get_sortable_columns(): array {
        return [
            'name'       => ['name', false],
            'updated_at' => ['updated_at', true],
        ];
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'schema':
                return esc_html($item->schema_type);
            case 'placement':
                return esc_html(self::placement_label((string) $item->placement));
            case 'status':
                $label = ($item->status ?? '') === 'active'
                    ? __('Active', 'noble-schema')
                    : __('Disabled', 'noble-schema');
                return esc_html($label);
            case 'updated_at':
                return esc_html(
                    mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item->updated_at)
                );
            default:
                return '';
        }
    }

    protected function column_name($item): string {
        $title = sprintf(
            '<strong><a href="%s" class="%s"%s>%s</a></strong>',
            esc_url($this->edit_url((int) $item->id)),
            esc_attr(Language_Guard::link_class()),
            Language_Guard::confirm_data_attrs(),
            esc_html($item->name)
        );

        return $title . $this->row_actions($this->get_row_actions($item));
    }

    /**
     * Under WPML, editing happens in the default language, so send the reader
     * straight there once they have confirmed the switch.
     */
    private function edit_url(int $id): string {
        return Language_Guard::switch_url(Menu::edit_url($id));
    }

    private function get_row_actions(object $item): array {
        $id = (int) $item->id;

        $actions = [
            'edit' => sprintf(
                '<a href="%s" class="%s"%s>%s</a>',
                esc_url($this->edit_url($id)),
                esc_attr(Language_Guard::link_class()),
                Language_Guard::confirm_data_attrs(),
                esc_html__('Edit', 'noble-schema')
            ),
            'duplicate' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=ns_duplicate_config&config_id=' . $id),
                    'ns_duplicate_config_' . $id
                )),
                esc_html__('Duplicate', 'noble-schema')
            ),
        ];

        if ($item->status === 'active') {
            $actions['disable'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=ns_toggle_config&config_id=' . $id),
                    'ns_toggle_config_' . $id
                )),
                esc_html__('Disable', 'noble-schema')
            );
        } else {
            $actions['enable'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=ns_toggle_config&config_id=' . $id),
                    'ns_toggle_config_' . $id
                )),
                esc_html__('Enable', 'noble-schema')
            );
        }

        $config_name = (string) ($item->name ?? '');
        if ($config_name === '') {
            $config_name = sprintf(
                /* translators: %d: config ID */
                __('Config #%d', 'noble-schema'),
                $id
            );
        }

        $actions['delete'] = sprintf(
            '<a href="%s" class="submitdelete ns-confirm-link" data-ns-confirm-message="%s" data-ns-confirm-title="%s" data-ns-confirm-ok="%s" data-ns-confirm-cancel="%s" data-ns-confirm-destructive="1">%s</a>',
            esc_url(wp_nonce_url(
                admin_url('admin-post.php?action=ns_delete_config&config_id=' . $id),
                'ns_delete_config_' . $id
            )),
            esc_attr(sprintf(
                /* translators: %s: schema config name */
                __('Delete “%s” permanently? This cannot be undone.', 'noble-schema'),
                $config_name
            )),
            esc_attr(sprintf(
                /* translators: %s: schema config name */
                __('Delete “%s”?', 'noble-schema'),
                $config_name
            )),
            esc_attr(__('Delete', 'noble-schema')),
            esc_attr(__('Cancel', 'noble-schema')),
            esc_html__('Delete', 'noble-schema')
        );

        return $actions;
    }

    private static function placement_label(string $placement): string {
        return match ($placement) {
            'global' => __('All pages', 'noble-schema'),
            'sitewide' => __('Sitewide', 'noble-schema'),
            'post_type' => __('Post type', 'noble-schema'),
            'page_template' => __('Page template', 'noble-schema'),
            'block' => __('Block', 'noble-schema'),
            'post_id' => __('Specific posts', 'noble-schema'),
            'archive' => __('Archive', 'noble-schema'),
            'mixed' => __('Mixed (OR)', 'noble-schema'),
            default => $placement,
        };
    }
}
