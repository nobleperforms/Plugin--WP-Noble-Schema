<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Integrations\Plugin_Integrations;
use PHPUnit\Framework\TestCase;

class Plugin_Integrations_Language_Test extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['noble_schema_test_wpml_active'],
            $GLOBALS['noble_schema_test_wpml_default_language'],
            $GLOBALS['noble_schema_test_wpml_current_language'],
            $GLOBALS['noble_schema_test_wpml_post_translations'],
            $GLOBALS['wp_filter']['wpml_default_language'],
            $GLOBALS['wp_filter']['wpml_current_language']
        );
        parent::tearDown();
    }

    public function test_languages_are_empty_when_wpml_inactive(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = false;

        $this->assertSame('', Plugin_Integrations::wpml_default_language());
        $this->assertSame('', Plugin_Integrations::wpml_current_language());
        $this->assertSame([], Plugin_Integrations::post_translations(12));
    }

    public function test_languages_come_from_wpml_filters(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        add_filter('wpml_default_language', static fn () => 'EN');
        add_filter('wpml_current_language', static fn () => ' es ');

        $this->assertSame('en', Plugin_Integrations::wpml_default_language());
        $this->assertSame('es', Plugin_Integrations::wpml_current_language());
    }

    public function test_test_globals_override_filters(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_default_language'] = 'en';
        $GLOBALS['noble_schema_test_wpml_current_language'] = 'fr';

        $this->assertSame('en', Plugin_Integrations::wpml_default_language());
        $this->assertSame('fr', Plugin_Integrations::wpml_current_language());
    }

    public function test_post_translations_excludes_self(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_post_translations'] = [
            10 => [
                10 => 'en',
                20 => 'es',
                30 => 'fr',
            ],
        ];

        $this->assertSame(
            [
                ['id' => 20, 'language_code' => 'es'],
                ['id' => 30, 'language_code' => 'fr'],
            ],
            Plugin_Integrations::post_translations(10)
        );
    }
}
