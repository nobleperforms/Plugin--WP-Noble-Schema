<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Transfer\Pack;
use PHPUnit\Framework\TestCase;

class Transfer_Pack_Test extends TestCase {
    public function test_normalize_rejects_bad_format(): void {
        $pack = new Pack();
        $this->expectException(\InvalidArgumentException::class);
        $pack->normalize(['format' => 'nope', 'version' => 1]);
    }

    public function test_normalize_accepts_singular_config(): void {
        $pack = new Pack();
        $normalized = $pack->normalize([
            'format'  => Pack::FORMAT,
            'version' => 1,
            'scope'   => 'config',
            'config'  => [
                'name' => 'Home',
                'template_slug' => 'web-page',
                'schema_type' => 'WebPage',
                'targets' => [],
                'fields' => [],
            ],
        ]);

        $this->assertSame('config', $normalized['scope']);
        $this->assertCount(1, $normalized['configs']);
        $this->assertSame('Home', $normalized['configs'][0]['name']);
        $this->assertSame([], $normalized['settings']);
    }

    public function test_export_settings_stub_shape(): void {
        $pack = new Pack();
        $normalized = $pack->normalize([
            'format'           => Pack::FORMAT,
            'version'          => 1,
            'scope'            => 'settings',
            'settings'         => [],
            'custom_templates' => [
                [
                    'slug' => 'web-page',
                    'label' => 'WebPage',
                    'schema_type' => 'WebPage',
                    'fields' => [
                        ['path' => 'name', 'label' => 'name', 'required' => true, 'value_type' => 'text'],
                    ],
                ],
            ],
            'configs' => [],
        ]);

        $this->assertSame('settings', $normalized['scope']);
        $this->assertCount(1, $normalized['custom_templates']);
        $this->assertSame('web-page', $normalized['custom_templates'][0]['slug']);
    }

    public function test_export_settings_includes_github_options_and_token(): void {
        $GLOBALS['noble_schema_test_options'] = [
            'ns_enable_updates' => '1',
            'ns_gh_username' => 'noblestudios',
            'ns_gh_repo' => 'wp-noble-schema',
            'ns_gh_token' => 'ghp_secret_token_value',
        ];

        $pack = new Pack();
        $exported = $pack->export_settings();

        $this->assertSame('settings', $exported['scope']);
        $this->assertSame('1', $exported['settings']['ns_enable_updates']);
        $this->assertSame('noblestudios', $exported['settings']['ns_gh_username']);
        $this->assertSame('wp-noble-schema', $exported['settings']['ns_gh_repo']);
        $this->assertSame('ghp_secret_token_value', $exported['settings']['ns_gh_token']);
    }

    public function test_normalize_targets_forces_has_block_filter_key(): void {
        $pack = new Pack();
        $method = new \ReflectionMethod(Pack::class, 'normalize_targets');
        $targets = $method->invoke($pack, [
            [
                'rule_type'  => 'has_block',
                'rule_key'   => null,
                'rule_value' => 'acf/faq',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
            [
                'rule_type'  => 'post_type',
                'rule_key'   => null,
                'rule_value' => 'post',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
        ]);

        $this->assertSame('filter', $targets[0]['rule_key']);
        $this->assertNull($targets[1]['rule_key']);
    }

    public function test_resolve_import_placement_block_only_becomes_global(): void {
        $pack = new Pack();
        $method = new \ReflectionMethod(Pack::class, 'resolve_import_placement');
        $placement = $method->invoke($pack, [
            [
                'rule_type'  => 'has_block',
                'rule_key'   => 'filter',
                'rule_value' => 'acf/faq',
                'operator'   => 'is',
                'rule_group' => 0,
            ],
        ], 'block');

        $this->assertSame('global', $placement);
    }

    public function test_resolve_import_placement_preserves_sitewide(): void {
        $pack = new Pack();
        $method = new \ReflectionMethod(Pack::class, 'resolve_import_placement');
        $placement = $method->invoke($pack, [], 'sitewide');
        $this->assertSame('sitewide', $placement);
    }

    public function test_resolve_import_placement_preserves_global(): void {
        $pack = new Pack();
        $method = new \ReflectionMethod(Pack::class, 'resolve_import_placement');
        $this->assertSame('global', $method->invoke($pack, [], 'global'));
        $this->assertSame(
            'global',
            $method->invoke($pack, [
                [
                    'rule_type'  => 'language',
                    'rule_key'   => null,
                    'rule_value' => 'en',
                    'operator'   => 'is',
                    'rule_group' => 0,
                ],
            ], 'global')
        );
    }

    public function test_prepare_import_config_keeps_valid_sitewide_active(): void {
        $pack = new Pack();
        $method = new \ReflectionMethod(Pack::class, 'prepare_import_config');
        $prepared = $method->invoke($pack, [
            'name'      => 'Org',
            'placement' => 'sitewide',
            'status'    => 'active',
            'targets'   => [
                [
                    'rule_type'  => 'language',
                    'rule_key'   => null,
                    'rule_value' => 'en',
                    'operator'   => 'is',
                    'rule_group' => 0,
                ],
            ],
            'fields' => [
                [
                    'field_path'    => 'name',
                    'source_type'   => 'site_info',
                    'source_config' => ['field' => 'blogname'],
                ],
                [
                    'field_path'    => 'url',
                    'source_type'   => 'static',
                    'source_config' => ['value' => 'https://example.com'],
                ],
            ],
        ]);

        $this->assertSame('sitewide', $prepared['config']['placement']);
        $this->assertSame('active', $prepared['config']['status']);
        $this->assertSame([], $prepared['warnings']);
        $this->assertSame([], $prepared['targets']);
    }

    public function test_prepare_import_config_disables_sitewide_with_blocked_sources(): void {
        $pack = new Pack();
        $method = new \ReflectionMethod(Pack::class, 'prepare_import_config');
        $prepared = $method->invoke($pack, [
            'name'      => 'Bad Org',
            'placement' => 'sitewide',
            'status'    => 'active',
            'targets'   => [],
            'fields'    => [
                [
                    'field_path'    => 'name',
                    'source_type'   => 'core',
                    'source_config' => ['field' => 'post_title'],
                ],
            ],
        ]);

        $this->assertSame('sitewide', $prepared['config']['placement']);
        $this->assertSame('disabled', $prepared['config']['status']);
        $this->assertCount(1, $prepared['warnings']);
        $this->assertStringContainsString('disabled', $prepared['warnings'][0]);
        $this->assertStringContainsString('core', $prepared['warnings'][0]);
    }

    public function test_preview_masks_github_token(): void {
        $pack = new Pack();
        $preview = $pack->preview([
            'format'   => Pack::FORMAT,
            'version'  => 1,
            'scope'    => 'settings',
            'settings' => [
                'ns_enable_updates' => '1',
                'ns_gh_username' => 'noblestudios',
                'ns_gh_repo' => 'wp-noble-schema',
                'ns_gh_token' => 'ghp_secret_token_value',
            ],
            'custom_templates' => [],
            'configs' => [],
        ]);

        $this->assertSame(4, $preview['settings']['count']);
        $token_item = null;
        foreach ($preview['settings']['items'] as $item) {
            if (($item['key'] ?? '') === 'ns_gh_token') {
                $token_item = $item;
                break;
            }
        }

        $this->assertNotNull($token_item);
        $this->assertStringNotContainsString('ghp_secret_token_value', (string) $token_item['value']);
        $this->assertStringEndsWith('alue', (string) $token_item['value']);
        $this->assertStringContainsString('•', (string) $token_item['value']);
    }
}
