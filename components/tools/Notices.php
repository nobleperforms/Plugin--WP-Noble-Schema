<?php

namespace NobleSchema\Tools;

class Notices {
    public static function success(string $message): void {
        add_action('admin_notices', function () use ($message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        });
    }

    public static function error(string $message): void {
        add_action('admin_notices', function () use ($message) {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        });
    }

    public static function warning(string $message): void {
        add_action('admin_notices', function () use ($message) {
            echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
        });
    }
}
