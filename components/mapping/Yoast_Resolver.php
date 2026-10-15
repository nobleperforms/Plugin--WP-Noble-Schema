<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

use NobleSchema\Integrations\Plugin_Integrations;

class Yoast_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        if (!Plugin_Integrations::is_yoast_active()) {
            return null;
        }

        $field = sanitize_key((string) ($source_config['field'] ?? ''));
        $post_id = (int) ($context['post_id'] ?? 0);

        if ($field === '' || $post_id <= 0) {
            return null;
        }

        if (!isset(Plugin_Integrations::yoast_fields()[$field])) {
            return null;
        }

        $value = $this->read_meta($field, $post_id);
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['opengraph-image', 'twitter-image'], true) && is_numeric($value)) {
            $url = wp_get_attachment_url((int) $value);
            return is_string($url) && $url !== '' ? $url : null;
        }

        return $value;
    }

    private function read_meta(string $field, int $post_id): mixed {
        if (class_exists('WPSEO_Meta') && method_exists('WPSEO_Meta', 'get_value')) {
            $value = \WPSEO_Meta::get_value($field, $post_id);
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }

        $meta_key = '_yoast_wpseo_' . $field;
        $fallback = get_post_meta($post_id, $meta_key, true);

        return $fallback === '' || $fallback === false || $fallback === null ? null : $fallback;
    }
}
