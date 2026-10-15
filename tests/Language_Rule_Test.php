<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Targeting\Language_Rule;
use PHPUnit\Framework\TestCase;

class Language_Rule_Test extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['noble_schema_test_wpml_active'],
            $GLOBALS['noble_schema_test_wpml_languages'],
            $GLOBALS['noble_schema_test_wpml_post_language'],
            $GLOBALS['noble_schema_test_wpml_current_language']
        );
        parent::tearDown();
    }

    public function test_normalize_rule_requires_language_and_operator(): void {
        $this->assertNull(Language_Rule::normalize_rule(['language' => '', 'operator' => 'is']));
        $this->assertSame(
            [
                'type'     => 'language',
                'language' => 'en',
                'operator' => 'is_not',
            ],
            Language_Rule::normalize_rule(['language' => 'EN', 'operator' => 'is_not'])
        );
        $this->assertSame(
            [
                'type'     => 'language',
                'language' => 'es',
                'operator' => 'is',
            ],
            Language_Rule::normalize_rule(['language' => 'es', 'operator' => 'nope'])
        );
    }

    public function test_matches_request_language_when_post_id_missing(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_current_language'] = 'es';

        $this->assertTrue(Language_Rule::matches_post(0, 'es', 'is'));
        $this->assertFalse(Language_Rule::matches_post(0, 'en', 'is'));
        $this->assertTrue(Language_Rule::matches_post(0, 'en', 'is_not'));
    }

    public function test_matches_post_when_wpml_inactive_is_always_true(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = false;
        $this->assertTrue(Language_Rule::matches_post(12, 'en', 'is'));
        $this->assertTrue(Language_Rule::matches_post(12, 'en', 'is_not'));
    }

    public function test_matches_post_compares_wpml_language_code(): void {
        $GLOBALS['noble_schema_test_wpml_active'] = true;
        $GLOBALS['noble_schema_test_wpml_post_language'] = [
            12 => 'en',
            13 => 'es',
        ];

        $this->assertTrue(Language_Rule::matches_post(12, 'en', 'is'));
        $this->assertFalse(Language_Rule::matches_post(12, 'es', 'is'));
        $this->assertTrue(Language_Rule::matches_post(12, 'es', 'is_not'));
        $this->assertFalse(Language_Rule::matches_post(13, 'en', 'is'));
        $this->assertTrue(Language_Rule::matches_post(13, 'en', 'is_not'));
    }
}
