<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\Integrations\Plugin_Integrations;

/**
 * Schema configs are stored once for the whole site, so authoring them from a
 * translated WPML admin view invites mismatched terms, templates and post IDs.
 * This keeps the "switch to the default language first" wording and the switch
 * URL identical between the config list and the wizard.
 */
final class Language_Guard {
    /**
     * @return array{current:string,current_label:string,default:string,default_label:string}|null
     */
    public static function mismatch(): ?array {
        if (!Plugin_Integrations::is_wpml_active()) {
            return null;
        }

        $default = Plugin_Integrations::wpml_default_language();
        $current = Plugin_Integrations::wpml_current_language();

        if ($default === '' || $current === '' || $current === $default) {
            return null;
        }

        $languages = Plugin_Integrations::wpml_active_languages();

        return [
            'current'       => $current,
            'current_label' => $languages[$current] ?? $current,
            'default'       => $default,
            'default_label' => $languages[$default] ?? $default,
        ];
    }

    public static function switch_url(string $url): string {
        $mismatch = self::mismatch();

        return $mismatch === null ? $url : add_query_arg('lang', $mismatch['default'], $url);
    }

    public static function title(): string {
        return __('Switch language to continue', 'noble-schema');
    }

    public static function message(): string {
        $mismatch = self::mismatch();
        if ($mismatch === null) {
            return '';
        }

        return sprintf(
            /* translators: 1: current admin language, 2: site default language. */
            __(
                'You are working in %1$s. Schema configs are saved once for the whole site, so they must be created and edited in %2$s. Continuing will reload this page in %2$s.',
                'noble-schema'
            ),
            $mismatch['current_label'],
            $mismatch['default_label']
        );
    }

    public static function button_label(): string {
        $mismatch = self::mismatch();
        if ($mismatch === null) {
            return '';
        }

        return sprintf(
            /* translators: %s: site default language. */
            __('Switch to %s and continue', 'noble-schema'),
            $mismatch['default_label']
        );
    }

    /**
     * Class to add to a link that should confirm the language switch first.
     */
    public static function link_class(): string {
        return self::mismatch() === null ? '' : 'ns-confirm-link';
    }

    /**
     * Confirm-dialog data attributes for such a link, ready to echo into markup.
     */
    public static function confirm_data_attrs(): string {
        if (self::mismatch() === null) {
            return '';
        }

        return sprintf(
            ' data-ns-confirm-title="%s" data-ns-confirm-message="%s" data-ns-confirm-ok="%s" data-ns-confirm-cancel="%s"',
            esc_attr(self::title()),
            esc_attr(self::message()),
            esc_attr(self::button_label()),
            esc_attr(__('Cancel', 'noble-schema'))
        );
    }

    /**
     * Payload for the wizard's blocking dialog, or null when nothing is wrong.
     *
     * @return array{title:string,message:string,button:string,switchUrl:string}|null
     */
    public static function wizard_payload(string $switch_url): ?array {
        if (self::mismatch() === null) {
            return null;
        }

        return [
            'title'     => self::title(),
            'message'   => self::message(),
            'button'    => self::button_label(),
            'switchUrl' => self::switch_url($switch_url),
        ];
    }
}
