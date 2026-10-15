<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Removal\Data_Remover;
use PHPUnit\Framework\TestCase;

class Data_Remover_Test extends TestCase {
    public function test_scopes_list(): void {
        $this->assertSame(
            ['everything', 'settings', 'templates', 'configs', 'snapshots', 'logs'],
            Data_Remover::scopes()
        );
    }

    public function test_purge_rejects_unknown_scope(): void {
        $remover = new Data_Remover();
        $this->expectException(\InvalidArgumentException::class);
        $remover->purge('nope');
    }

    public function test_purge_settings_clears_options(): void {
        $GLOBALS['noble_schema_test_options'] = [
            'ns_enable_updates' => '1',
            'ns_gh_username' => 'noblestudios',
            'ns_gh_repo' => 'wp-noble-schema',
            'ns_gh_token' => 'secret',
            'ns_gh_update_last_check' => ['ok' => true],
            'ns_delete_data_on_uninstall' => '1',
            'ns_restrict_schema_admin' => '1',
            'ns_schema_admin_user_ids' => [1, 2],
        ];

        $result = (new Data_Remover())->purge('settings');

        $this->assertSame('settings', $result['scope']);
        $this->assertSame(16, $result['counts']['settings']);
        $this->assertArrayNotHasKey('ns_gh_token', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_gh_update_last_check', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_plugin_version', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_onboarding_state', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_settings_touched', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_enable_updates', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_delete_data_on_uninstall', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_restrict_schema_admin', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_schema_admin_user_ids', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_logging_enabled', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_logging_retention', $GLOBALS['noble_schema_test_options']);
        $this->assertArrayNotHasKey('ns_sitewide_schema_types', $GLOBALS['noble_schema_test_options']);
    }

    public function test_purge_templates_rejects_when_not_writable(): void {
        $uploads = sys_get_temp_dir() . '/ns-removal-uploads-' . uniqid('', true);
        mkdir($uploads, 0777, true);
        chmod($uploads, 0555);
        $GLOBALS['noble_schema_test_uploads'] = $uploads;

        $base = sys_get_temp_dir() . '/ns-removal-ro-' . uniqid('', true);
        mkdir($base, 0777, true);

        $remover = new Data_Remover($base);
        $this->assertFalse($remover->custom_templates_writable());

        try {
            $this->expectException(\RuntimeException::class);
            $remover->purge('templates');
        } finally {
            chmod($uploads, 0755);
            @rmdir($uploads);
            unset($GLOBALS['noble_schema_test_uploads']);
            @rmdir($base);
        }
    }

    public function test_purge_logs_clears_entries_without_disabling_logging(): void {
        $GLOBALS['noble_schema_test_options'] = [
            'ns_logging_enabled' => '1',
            'ns_logging_retention' => '3m',
        ];
        $GLOBALS['noble_schema_test_logs'] = [
            ['id' => 1, 'created_at' => '2026-08-05 12:00:00', 'level' => 'info', 'category' => 'system', 'post_id' => null, 'message' => 'a', 'context' => '{}'],
        ];
        $GLOBALS['noble_schema_test_log_seq'] = 1;

        $result = (new Data_Remover())->purge('logs');

        $this->assertSame('logs', $result['scope']);
        $this->assertSame(1, $result['counts']['logs']);
        $this->assertSame([], $GLOBALS['noble_schema_test_logs']);
        $this->assertSame('1', $GLOBALS['noble_schema_test_options']['ns_logging_enabled']);
    }
}
