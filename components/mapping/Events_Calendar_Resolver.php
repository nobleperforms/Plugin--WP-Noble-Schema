<?php

declare(strict_types=1);

namespace NobleSchema\Mapping;

use NobleSchema\Integrations\Plugin_Integrations;

class Events_Calendar_Resolver implements Resolver_Interface {
    /**
     * @param array<string, mixed> $source_config
     * @param array<string, mixed> $context
     */
    public function resolve(array $source_config, array $context): mixed {
        if (!Plugin_Integrations::is_events_calendar_active()) {
            return null;
        }

        $field = sanitize_key((string) ($source_config['field'] ?? ''));
        $post_id = (int) ($context['post_id'] ?? 0);

        if ($field === '' || $post_id <= 0) {
            return null;
        }

        if (!isset(Plugin_Integrations::events_calendar_fields()[$field])) {
            return null;
        }

        $value = match ($field) {
            'start_date' => $this->event_datetime($post_id, 'start'),
            'end_date' => $this->event_datetime($post_id, 'end'),
            'all_day' => $this->all_day($post_id),
            'cost' => $this->string_or_null(
                function_exists('tribe_get_cost') ? tribe_get_cost($post_id, true) : get_post_meta($post_id, '_EventCost', true)
            ),
            'website' => $this->string_or_null(
                function_exists('tribe_get_event_website_url')
                    ? tribe_get_event_website_url($post_id)
                    : get_post_meta($post_id, '_EventURL', true)
            ),
            'venue_name' => $this->string_or_null(function_exists('tribe_get_venue') ? tribe_get_venue($post_id) : null),
            'venue_address' => $this->string_or_null(function_exists('tribe_get_address') ? tribe_get_address($post_id) : null),
            'venue_full_address' => $this->string_or_null(
                function_exists('tribe_get_full_address') ? tribe_get_full_address($post_id) : null
            ),
            'venue_city' => $this->string_or_null(function_exists('tribe_get_city') ? tribe_get_city($post_id) : null),
            'venue_region' => $this->venue_region($post_id),
            'venue_zip' => $this->string_or_null(function_exists('tribe_get_zip') ? tribe_get_zip($post_id) : null),
            'venue_country' => $this->string_or_null(function_exists('tribe_get_country') ? tribe_get_country($post_id) : null),
            'venue_phone' => $this->string_or_null(function_exists('tribe_get_phone') ? tribe_get_phone($post_id) : null),
            'venue_website' => $this->string_or_null(
                function_exists('tribe_get_venue_website_url') ? tribe_get_venue_website_url($post_id) : null
            ),
            'venue_place' => $this->venue_place($post_id),
            'organizer_name' => $this->string_or_null(
                function_exists('tribe_get_organizer') ? tribe_get_organizer($post_id) : null
            ),
            'organizer_email' => $this->string_or_null(
                function_exists('tribe_get_organizer_email') ? tribe_get_organizer_email($post_id, false) : null
            ),
            'organizer_phone' => $this->string_or_null(
                function_exists('tribe_get_organizer_phone') ? tribe_get_organizer_phone($post_id) : null
            ),
            'organizer_website' => $this->string_or_null(
                function_exists('tribe_get_organizer_website_url') ? tribe_get_organizer_website_url($post_id) : null
            ),
            default => null,
        };

        return $value === '' || $value === false || $value === null ? null : $value;
    }

    private function event_datetime(int $post_id, string $which): ?string {
        if ($which === 'start' && function_exists('tribe_get_start_date')) {
            $formatted = tribe_get_start_date($post_id, true, 'c');
            $value = $this->string_or_null($formatted);
            if ($value !== null) {
                return $value;
            }
        }

        if ($which === 'end' && function_exists('tribe_get_end_date')) {
            $formatted = tribe_get_end_date($post_id, true, 'c');
            $value = $this->string_or_null($formatted);
            if ($value !== null) {
                return $value;
            }
        }

        if ($which === 'start' && class_exists('Tribe__Events__Timezones') && method_exists('Tribe__Events__Timezones', 'event_start_timestamp')) {
            $timestamp = \Tribe__Events__Timezones::event_start_timestamp($post_id);
            if (is_numeric($timestamp) && (int) $timestamp > 0) {
                return date('c', (int) $timestamp);
            }
        }

        if ($which === 'end' && class_exists('Tribe__Events__Timezones') && method_exists('Tribe__Events__Timezones', 'event_end_timestamp')) {
            $timestamp = \Tribe__Events__Timezones::event_end_timestamp($post_id);
            if (is_numeric($timestamp) && (int) $timestamp > 0) {
                return date('c', (int) $timestamp);
            }
        }

        $meta_key = $which === 'end' ? '_EventEndDate' : '_EventStartDate';
        $raw = get_post_meta($post_id, $meta_key, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        return $timestamp ? date('c', $timestamp) : $raw;
    }

    private function all_day(int $post_id): ?bool {
        $raw = get_post_meta($post_id, '_EventAllDay', true);
        if ($raw === '' || $raw === null || $raw === false) {
            return false;
        }

        return (string) $raw === 'yes' || (string) $raw === '1' || $raw === true;
    }

    private function venue_region(int $post_id): ?string {
        if (function_exists('tribe_get_region')) {
            $region = $this->string_or_null(tribe_get_region($post_id));
            if ($region !== null) {
                return $region;
            }
        }

        if (function_exists('tribe_get_stateprovince')) {
            return $this->string_or_null(tribe_get_stateprovince($post_id));
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function venue_place(int $post_id): ?array {
        $name = $this->string_or_null(function_exists('tribe_get_venue') ? tribe_get_venue($post_id) : null);
        $street = $this->string_or_null(function_exists('tribe_get_address') ? tribe_get_address($post_id) : null);
        $city = $this->string_or_null(function_exists('tribe_get_city') ? tribe_get_city($post_id) : null);
        $region = $this->venue_region($post_id);
        $postal = $this->string_or_null(function_exists('tribe_get_zip') ? tribe_get_zip($post_id) : null);
        $country = $this->string_or_null(function_exists('tribe_get_country') ? tribe_get_country($post_id) : null);

        if ($name === null && $street === null && $city === null) {
            return null;
        }

        $place = ['@type' => 'Place'];
        if ($name !== null) {
            $place['name'] = $name;
        }

        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $street,
            'addressLocality' => $city,
            'addressRegion'   => $region,
            'postalCode'      => $postal,
            'addressCountry'  => $country,
        ], static fn ($value) => $value !== null && $value !== '');

        if (count($address) > 1) {
            $place['address'] = $address;
        }

        $phone = $this->string_or_null(function_exists('tribe_get_phone') ? tribe_get_phone($post_id) : null);
        if ($phone !== null) {
            $place['telephone'] = $phone;
        }

        $url = $this->string_or_null(
            function_exists('tribe_get_venue_website_url') ? tribe_get_venue_website_url($post_id) : null
        );
        if ($url !== null) {
            $place['url'] = $url;
        }

        return $place;
    }

    private function string_or_null(mixed $value): ?string {
        if ($value === null || $value === false) {
            return null;
        }

        $string = trim(wp_strip_all_tags((string) $value));
        return $string !== '' ? $string : null;
    }
}
