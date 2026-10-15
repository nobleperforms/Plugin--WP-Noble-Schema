<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

/**
 * Schema Config / Settings / Tools access (optional allowlist).
 */
class Access {
    public static function restriction_enabled(): bool {
        if ((string) get_option(Settings_Page::OPT_RESTRICT, '') !== '1') {
            return false;
        }

        // Never keep restriction on with an empty allowlist (total lockout).
        if (self::allowed_user_ids() === []) {
            delete_option(Settings_Page::OPT_RESTRICT);
            return false;
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public static function allowed_user_ids(): array {
        $raw = get_option(Settings_Page::OPT_ADMIN_USER_IDS, []);
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function can_manage_schema(?int $user_id = null): bool {
        $user_id = $user_id ?? (int) get_current_user_id();
        if ($user_id <= 0) {
            return false;
        }

        if (!user_can($user_id, 'manage_options')) {
            return false;
        }

        if (!self::restriction_enabled()) {
            return true;
        }

        return in_array($user_id, self::allowed_user_ids(), true);
    }

    /**
     * AJAX guard — sends JSON 403 and exits when unauthorized.
     */
    public static function require_manage_schema(): void {
        if (!self::can_manage_schema()) {
            wp_send_json_error(['message' => __('Unauthorized', 'noble-schema')], 403);
        }
    }

    /**
     * Admin page / admin-post guard — dies when unauthorized.
     */
    public static function require_manage_schema_page(): void {
        if (!self::can_manage_schema()) {
            wp_die(esc_html__('Unauthorized', 'noble-schema'));
        }
    }

    /**
     * Users eligible for the allowlist picker.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function manage_options_user_options(): array {
        $users = get_users([
            'capability' => 'manage_options',
            'orderby'    => 'display_name',
            'order'      => 'ASC',
            'fields'     => ['ID', 'display_name', 'user_login'],
        ]);

        $options = [];
        foreach ($users as $user) {
            $options[] = [
                'value' => (string) (int) $user->ID,
                'label' => sprintf(
                    '%s (%s)',
                    (string) $user->display_name,
                    (string) $user->user_login
                ),
            ];
        }

        return $options;
    }

    /**
     * Sanitize allowlist IDs: positive ints, manage_options only, unique.
     *
     * @param mixed $value
     * @return list<int>
     */
    public static function sanitize_user_ids($value, bool $force_current_user = false): array {
        $raw = is_array($value) ? $value : [];
        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            if (!user_can($id, 'manage_options')) {
                continue;
            }
            $ids[] = $id;
        }

        if ($force_current_user) {
            $current = (int) get_current_user_id();
            // Always include the acting user when forced. Callers that enable
            // restriction have already authorized this request; skipping the
            // capability re-check avoids empty allowlists / self-lockouts.
            if ($current > 0 && !in_array($current, $ids, true)) {
                $ids[] = $current;
            }
        }

        sort($ids);

        return $ids;
    }
}
