<?php

declare(strict_types=1);

namespace NobleSchemaLite;

final class Frontend {
    public function register(): void {
        add_action('wp_head', [$this, 'print_json_ld'], 1);
    }

    public function print_json_ld(): void {
        if (is_admin()) {
            return;
        }

        if (is_singular()) {
            $json = get_post_meta(get_queried_object_id(), NS_LITE_META_KEY, true);
            $json = is_string($json) ? trim($json) : '';
            if ($json === '') {
                return;
            }
            echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
            return;
        }

        $archive_key = self::current_archive_key();
        if ($archive_key === null) {
            return;
        }

        $all = get_option(NS_LITE_ARCHIVE_OPTION, []);
        if (!is_array($all)) {
            return;
        }
        $json = trim((string) ($all[$archive_key] ?? ''));
        if ($json === '') {
            return;
        }

        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    public static function current_archive_key(): ?string {
        if (is_home() && !is_front_page()) {
            return 'home';
        }

        if (is_post_type_archive()) {
            $post_type = get_query_var('post_type');
            if (is_array($post_type)) {
                $post_type = $post_type[0] ?? '';
            }
            $post_type = sanitize_key((string) $post_type);
            if ($post_type !== '') {
                return 'post_type:' . $post_type;
            }
        }

        return null;
    }
}
