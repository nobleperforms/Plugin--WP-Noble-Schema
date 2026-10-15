<?php

declare(strict_types=1);

use NobleSchema\Update\Updater;
use PHPUnit\Framework\TestCase;

final class Updater_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
    }

    public function test_normalize_version_strips_v_prefix(): void {
        $this->assertSame('0.1.1', Updater::normalize_version('v0.1.1'));
        $this->assertSame('0.1.1', Updater::normalize_version('V0.1.1'));
        $this->assertSame('0.1.1', Updater::normalize_version('0.1.1'));
    }

    public function test_release_page_url_builds_github_tag_link(): void {
        $this->assertSame(
            'https://github.com/noblestudios/wp-noble-schema/releases/tag/v0.1.1',
            Updater::release_page_url('noblestudios', 'wp-noble-schema', '0.1.1')
        );
        $this->assertSame(
            'https://github.com/noblestudios/wp-noble-schema/releases/tag/v0.1.1',
            Updater::release_page_url('noblestudios', 'wp-noble-schema', 'v0.1.1')
        );
        $this->assertSame('', Updater::release_page_url('', 'wp-noble-schema', '0.1.1'));
    }

    public function test_package_prefers_zip_asset_over_zipball(): void {
        $release = (object) [
            'tag_name'    => 'v0.1.1',
            'html_url'    => 'https://github.com/example/wp-noble-schema/releases/tag/v0.1.1',
            'zipball_url' => 'https://api.github.com/repos/example/wp-noble-schema/zipball/v0.1.1',
            'assets'      => [
                (object) [
                    'id'                   => 99,
                    'name'                 => 'notes.txt',
                    'browser_download_url' => 'https://example.com/notes.txt',
                ],
                (object) [
                    'id'                   => 100,
                    'name'                 => 'wp-noble-schema.zip',
                    'browser_download_url' => 'https://example.com/wp-noble-schema.zip',
                ],
            ],
        ];

        $package = Updater::package_from_release($release);
        $this->assertNotNull($package);
        $this->assertSame('asset', $package->package_source);
        $this->assertSame(100, $package->asset_id);
        $this->assertSame('https://example.com/wp-noble-schema.zip', $package->browser_url);
        $this->assertSame('v0.1.1', $package->tag_name);
    }

    public function test_package_falls_back_to_zipball_when_no_zip_asset(): void {
        $release = (object) [
            'tag_name'    => 'v0.1.1',
            'html_url'    => 'https://github.com/example/wp-noble-schema/releases/tag/v0.1.1',
            'zipball_url' => 'https://api.github.com/repos/example/wp-noble-schema/zipball/v0.1.1',
            'assets'      => [],
        ];

        $package = Updater::package_from_release($release);
        $this->assertNotNull($package);
        $this->assertSame('zipball', $package->package_source);
        $this->assertSame(0, $package->asset_id);
        $this->assertSame(
            'https://api.github.com/repos/example/wp-noble-schema/zipball/v0.1.1',
            $package->browser_url
        );
    }

    public function test_package_returns_null_without_tag_or_package(): void {
        $this->assertNull(Updater::package_from_release((object) [
            'html_url' => 'https://example.com',
            'assets'   => [],
        ]));

        $this->assertNull(Updater::package_from_release((object) [
            'tag_name' => 'v0.1.1',
            'assets'   => [],
        ]));
    }

    public function test_maybe_log_version_change_seeds_without_logging(): void {
        $GLOBALS['noble_schema_test_options'] = ['ns_logging_enabled' => '1'];
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;

        $logged = Updater::maybe_log_version_change('', '0.1.4');
        $this->assertFalse($logged);
        $this->assertSame('0.1.4', $GLOBALS['noble_schema_test_options'][Updater::OPT_PLUGIN_VERSION]);
        $this->assertSame([], $GLOBALS['noble_schema_test_logs']);
    }

    public function test_maybe_log_version_change_logs_on_bump(): void {
        $GLOBALS['noble_schema_test_options'] = [
            Updater::OPT_PLUGIN_VERSION => '0.1.3',
            'ns_logging_enabled'        => '1',
        ];
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;

        $logged = Updater::maybe_log_version_change('', '0.1.4');
        $this->assertTrue($logged);
        $this->assertSame('0.1.4', $GLOBALS['noble_schema_test_options'][Updater::OPT_PLUGIN_VERSION]);
        $this->assertCount(1, $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('plugin_updated', $GLOBALS['noble_schema_test_logs'][0]['category']);
        $this->assertStringContainsString('0.1.3', $GLOBALS['noble_schema_test_logs'][0]['message']);
        $this->assertStringContainsString('0.1.4', $GLOBALS['noble_schema_test_logs'][0]['message']);
    }

    public function test_maybe_log_version_change_skips_same_version(): void {
        $GLOBALS['noble_schema_test_options'] = [
            Updater::OPT_PLUGIN_VERSION => '0.1.4',
            'ns_logging_enabled'        => '1',
        ];
        $GLOBALS['noble_schema_test_logs'] = [];
        $GLOBALS['noble_schema_test_log_seq'] = 0;

        $this->assertFalse(Updater::maybe_log_version_change('', 'v0.1.4'));
        $this->assertSame([], $GLOBALS['noble_schema_test_logs']);
    }
}
