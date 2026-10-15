<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Admin\Language_Guard;
use PHPUnit\Framework\TestCase;

class Language_Guard_Test extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['noble_schema_test_wpml_active'],
            $GLOBALS['noble_schema_test_wpml_languages'],
            $GLOBALS['noble_schema_test_wpml_default_language'],
            $GLOBALS['noble_schema_test_wpml_current_language']
        );
        parent::tearDown();
    }

    private function set_languages(string $current, string $default): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_languages'] = [
            'en' => 'English',
            'es' => 'Spanish',
        ];
        $GLOBALS['noble_schema_test_wpml_current_language'] = $current;
        $GLOBALS['noble_schema_test_wpml_default_language'] = $default;
    }

    public function test_no_mismatch_when_wpml_inactive(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = false;

        $this->assertNull(Language_Guard::mismatch());
        $this->assertSame('', Language_Guard::link_class());
        $this->assertSame('', Language_Guard::confirm_data_attrs());
        $this->assertSame('https://x.test/edit', Language_Guard::switch_url('https://x.test/edit'));
        $this->assertNull(Language_Guard::wizard_payload('https://x.test/edit'));
    }

    public function test_no_mismatch_when_already_in_default_language(): void {
        $this->set_languages('en', 'en');

        $this->assertNull(Language_Guard::mismatch());
        $this->assertSame('https://x.test/edit', Language_Guard::switch_url('https://x.test/edit'));
    }

    public function test_mismatch_uses_active_language_labels(): void {
        $this->set_languages('es', 'en');

        $this->assertSame(
            [
                'current'       => 'es',
                'current_label' => 'Spanish',
                'default'       => 'en',
                'default_label' => 'English',
            ],
            Language_Guard::mismatch()
        );
        $this->assertStringContainsString('Spanish', Language_Guard::message());
        $this->assertStringContainsString('English', Language_Guard::message());
        $this->assertSame('Switch to English and continue', Language_Guard::button_label());
        $this->assertSame('ns-confirm-link', Language_Guard::link_class());
    }

    public function test_switch_url_adds_default_language(): void {
        $this->set_languages('es', 'en');

        $this->assertStringContainsString('lang=en', Language_Guard::switch_url('https://x.test/edit'));
    }

    public function test_wizard_payload_carries_dialog_strings(): void {
        $this->set_languages('es', 'en');

        $payload = Language_Guard::wizard_payload('https://x.test/edit');

        $this->assertNotNull($payload);
        $this->assertSame(Language_Guard::title(), $payload['title']);
        $this->assertSame(Language_Guard::message(), $payload['message']);
        $this->assertSame('Switch to English and continue', $payload['button']);
        $this->assertStringContainsString('lang=en', $payload['switchUrl']);
    }

    public function test_confirm_data_attrs_are_escaped(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_languages'] = ['en' => 'English', 'es' => '"Spanish"'];
        $GLOBALS['noble_schema_test_wpml_current_language'] = 'es';
        $GLOBALS['noble_schema_test_wpml_default_language'] = 'en';

        $attrs = Language_Guard::confirm_data_attrs();

        $this->assertStringContainsString('data-ns-confirm-title=', $attrs);
        $this->assertStringNotContainsString('"Spanish"', $attrs);
        $this->assertStringContainsString('&quot;Spanish&quot;', $attrs);
    }
}
