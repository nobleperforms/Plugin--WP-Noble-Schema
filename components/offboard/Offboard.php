<?php

declare(strict_types=1);

namespace NobleSchema\Offboard;

final class Offboard {
    public const CONFIRM_PHRASE = 'OFFBOARD';
    public const LITE_SLUG = 'wp-noble-schema-lite';
    public const LITE_MAIN = 'wp-noble-schema-lite/wp-noble-schema-lite.php';
    public const PARENT_OPTION = 'ns_lite_parent_plugin';

    public static function plugins_dir_writable(): bool {
        return is_dir(WP_PLUGIN_DIR) && is_writable(WP_PLUGIN_DIR);
    }

    public static function bundle_dir(): string {
        return dirname(__DIR__, 2) . '/offboard/' . self::LITE_SLUG;
    }

    public static function target_dir(): string {
        return trailingslashit(WP_PLUGIN_DIR) . self::LITE_SLUG;
    }

    public static function lite_settings_url(): string {
        return admin_url('options-general.php?page=noble-schema-lite');
    }

    /**
     * @return array{redirect:string,copied:bool,activated:bool}
     */
    public function start(string $parent_plugin_file): array {
        if (!self::plugins_dir_writable()) {
            throw new \RuntimeException(
                __('Offboarding requires a writable wp-content/plugins directory.', 'noble-schema')
            );
        }

        $source = self::bundle_dir();
        if (!is_dir($source) || !is_readable($source . '/wp-noble-schema-lite.php')) {
            throw new \RuntimeException(
                __('Schema Lite bundle is missing from the Noble Schema plugin package.', 'noble-schema')
            );
        }

        $target = self::target_dir();
        $this->copy_directory($source, $target);

        $main_path = trailingslashit($target) . 'wp-noble-schema-lite.php';
        if (!is_readable($main_path)) {
            throw new \RuntimeException(
                __('Schema Lite was copied but the main plugin file is missing.', 'noble-schema')
            );
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Freshly copied plugins are invisible until the plugins cache is cleared.
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        } else {
            wp_cache_delete('plugins', 'plugins');
        }

        update_option(self::PARENT_OPTION, plugin_basename($parent_plugin_file), false);

        $result = activate_plugin(self::LITE_MAIN, '', false, true);
        if (is_wp_error($result)) {
            throw new \RuntimeException(
                sprintf(
                    /* translators: %s: error message */
                    __('Could not activate Schema Lite: %s', 'noble-schema'),
                    $result->get_error_message()
                )
            );
        }

        return [
            'redirect'  => self::lite_settings_url(),
            'copied'    => true,
            'activated' => true,
        ];
    }

    private function copy_directory(string $source, string $target): void {
        $source = untrailingslashit($source);
        $target = untrailingslashit($target);

        if (!is_dir($target) && !wp_mkdir_p($target)) {
            throw new \RuntimeException(
                __('Could not create the Schema Lite plugin directory.', 'noble-schema')
            );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $dest = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($dest) && !wp_mkdir_p($dest)) {
                    throw new \RuntimeException(
                        __('Could not create Schema Lite subdirectories.', 'noble-schema')
                    );
                }
                continue;
            }

            $dir = dirname($dest);
            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                throw new \RuntimeException(
                    __('Could not create Schema Lite subdirectories.', 'noble-schema')
                );
            }
            if (!copy($item->getPathname(), $dest)) {
                throw new \RuntimeException(
                    __('Could not copy Schema Lite plugin files.', 'noble-schema')
                );
            }
        }
    }
}
