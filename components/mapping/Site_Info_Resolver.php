<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

/**
 * Resolves site-level WordPress options and bloginfo values (not post-specific).
 */
class Site_Info_Resolver implements Resolver_Interface {
    /**
     * @return array<string, string>
     */
    public static function fields(): array {
        return [
            'site_name'          => __('Site Name', 'noble-schema'),
            'site_description'   => __('Tagline / Description', 'noble-schema'),
            'home_url'           => __('Home URL', 'noble-schema'),
            'site_url'           => __('Site URL (wp-admin root)', 'noble-schema'),
            'admin_email'        => __('Admin Email', 'noble-schema'),
            'language'           => __('Site Language (locale)', 'noble-schema'),
            'language_attr'      => __('HTML Language Attribute', 'noble-schema'),
            'timezone'           => __('Timezone', 'noble-schema'),
            'site_icon_url'      => __('Site Icon URL', 'noble-schema'),
            'custom_logo_url'    => __('Custom Logo URL', 'noble-schema'),
            'privacy_policy_url' => __('Privacy Policy URL', 'noble-schema'),
            'rss2_url'           => __('RSS2 Feed URL', 'noble-schema'),
            'atom_url'           => __('Atom Feed URL', 'noble-schema'),
        ];
    }

    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        $field = sanitize_key((string) ($source_config['field'] ?? ''));
        if ($field === '' || !array_key_exists($field, self::fields())) {
            return null;
        }

        return match ($field) {
            'site_name' => $this->non_empty_string(get_bloginfo('name')),
            'site_description' => $this->non_empty_string(get_bloginfo('description')),
            'home_url' => $this->non_empty_string(home_url('/')),
            'site_url' => $this->non_empty_string(site_url('/')),
            'admin_email' => $this->non_empty_string((string) get_option('admin_email', '')),
            'language' => $this->non_empty_string(function_exists('determine_locale') ? determine_locale() : get_locale()),
            'language_attr' => $this->non_empty_string(get_bloginfo('language')),
            'timezone' => $this->non_empty_string(
                function_exists('wp_timezone_string')
                    ? wp_timezone_string()
                    : (string) get_option('timezone_string', '')
            ),
            'site_icon_url' => $this->site_icon_url(),
            'custom_logo_url' => $this->custom_logo_url(),
            'privacy_policy_url' => $this->privacy_policy_url(),
            'rss2_url' => $this->non_empty_string(get_bloginfo('rss2_url')),
            'atom_url' => $this->non_empty_string(get_bloginfo('atom_url')),
            default => null,
        };
    }

    private function non_empty_string(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function site_icon_url(): ?string {
        if (!function_exists('get_site_icon_url')) {
            return null;
        }
        $url = get_site_icon_url(512);

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function custom_logo_url(): ?string {
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id <= 0 || !function_exists('wp_get_attachment_image_url')) {
            return null;
        }
        $url = wp_get_attachment_image_url($logo_id, 'full');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function privacy_policy_url(): ?string {
        if (!function_exists('get_privacy_policy_url')) {
            return null;
        }
        $url = get_privacy_policy_url();

        return is_string($url) && $url !== '' ? $url : null;
    }
}
