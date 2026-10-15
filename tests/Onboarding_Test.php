<?php

declare(strict_types=1);

namespace NobleSchema\Tests;

use NobleSchema\Admin\Onboarding;
use PHPUnit\Framework\TestCase;

class Onboarding_Test extends TestCase {
    protected function setUp(): void {
        $GLOBALS['noble_schema_test_options'] = [];
        unset($GLOBALS['noble_schema_test_config_count'], $GLOBALS['noble_schema_test_template_count']);
    }

    public function test_missing_option_is_inactive(): void {
        $this->assertNull(Onboarding::get_state());
        $this->assertFalse(Onboarding::is_active_ui());
        $this->assertFalse(Onboarding::show_tools_card());
        $this->assertFalse(Onboarding::show_banner());
    }

    public function test_seed_on_activate_sets_active_with_banner(): void {
        Onboarding::seed_on_activate();
        $state = Onboarding::get_state();
        $this->assertNotNull($state);
        $this->assertSame('active', $state['status']);
        $this->assertNull($state['path']);
        $this->assertTrue($state['show_banner']);
        $this->assertTrue(Onboarding::is_active_ui());
        $this->assertTrue(Onboarding::show_banner());
        $this->assertFalse(Onboarding::show_tools_card());
        $this->assertSame(0, Onboarding::completed_step_count($state));
    }

    public function test_minimize_dismiss_reopen_preserves_steps(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('checklist');
        Onboarding::mark_step_complete('settings');

        Onboarding::minimize();
        $this->assertSame('minimized', Onboarding::get_state()['status']);
        $this->assertTrue(Onboarding::is_active_ui());

        Onboarding::dismiss();
        $this->assertSame('dismissed', Onboarding::get_state()['status']);
        $this->assertTrue(Onboarding::show_tools_card());
        $this->assertTrue(Onboarding::get_state()['steps']['settings']['completed']);

        Onboarding::reopen();
        $state = Onboarding::get_state();
        $this->assertSame('active', $state['status']);
        $this->assertSame('checklist', $state['path']);
        $this->assertTrue($state['steps']['settings']['completed']);
        $this->assertFalse($state['show_banner']);
        $this->assertFalse(Onboarding::show_tools_card());
    }

    public function test_import_path_switches_to_checklist_when_tasks_remain(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('import');
        Onboarding::complete_import_path();
        $state = Onboarding::get_state();
        $this->assertSame('checklist', $state['path']);
        $this->assertSame('active', $state['status']);
        $this->assertTrue(Onboarding::is_active_ui());
        $this->assertLessThan(4, Onboarding::completed_step_count($state));
    }

    public function test_import_path_celebrates_when_checklist_is_satisfied(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('import');
        update_option(Onboarding::OPT_SETTINGS_TOUCHED, '1');
        $GLOBALS['noble_schema_test_template_count'] = 1;
        $GLOBALS['noble_schema_test_config_count'] = 1;

        $state = Onboarding::get_state();
        $state['steps']['viewer']['completed'] = true;
        $state['steps']['viewer']['manual'] = true;
        Onboarding::save_state($state);

        Onboarding::complete_import_path();
        $state = Onboarding::get_state();
        $this->assertSame('checklist', $state['path']);
        $this->assertSame('celebrating', $state['status']);
        $this->assertSame(4, Onboarding::completed_step_count($state));

        Onboarding::finalize_celebration();
        $this->assertSame('completed', Onboarding::get_state()['status']);
        $this->assertTrue(Onboarding::show_tools_card());
    }

    public function test_import_marks_imported_templates_and_configs(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('import');
        $GLOBALS['noble_schema_test_template_count'] = 2;
        $GLOBALS['noble_schema_test_config_count'] = 3;

        Onboarding::complete_import_path();
        $state = Onboarding::get_state();
        $this->assertSame('checklist', $state['path']);
        $this->assertSame('active', $state['status']);
        $this->assertTrue($state['steps']['templates']['completed']);
        $this->assertTrue($state['steps']['configs']['completed']);
        $this->assertFalse($state['steps']['settings']['completed']);
        $this->assertFalse($state['steps']['viewer']['completed']);
    }

    public function test_checklist_completes_when_all_steps_done(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('checklist');
        foreach (['settings', 'templates', 'configs', 'viewer'] as $step) {
            Onboarding::mark_step_complete($step);
        }
        $this->assertSame('celebrating', Onboarding::get_state()['status']);
        $this->assertSame(4, Onboarding::completed_step_count(Onboarding::get_state()));
        $this->assertTrue(Onboarding::is_active_ui());

        Onboarding::finalize_celebration();
        $this->assertSame('completed', Onboarding::get_state()['status']);
        $this->assertFalse(Onboarding::is_active_ui());
    }

    public function test_merge_auto_completion_settings_and_viewer(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('checklist');

        update_option(Onboarding::OPT_SETTINGS_TOUCHED, '1');
        $state = Onboarding::merge_auto_completion(null);
        $this->assertTrue($state['steps']['settings']['completed']);
        $this->assertFalse($state['steps']['settings']['manual']);

        $state = Onboarding::merge_auto_completion('viewer');
        $this->assertTrue($state['steps']['viewer']['completed']);
        $this->assertTrue(Onboarding::get_state()['steps']['viewer']['completed']);
    }

    public function test_merge_auto_completion_configs_via_test_hook(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('checklist');
        $GLOBALS['noble_schema_test_config_count'] = 2;
        $state = Onboarding::merge_auto_completion(null);
        $this->assertTrue($state['steps']['configs']['completed']);
    }

    public function test_clear_path_returns_to_question_preserving_steps(): void {
        Onboarding::seed_on_activate();
        Onboarding::set_path('checklist');
        Onboarding::mark_step_complete('settings');

        Onboarding::clear_path();
        $state = Onboarding::get_state();
        $this->assertNull($state['path']);
        $this->assertTrue($state['steps']['settings']['completed']);
        $this->assertSame('active', $state['status']);
    }

    public function test_banner_shows_once_until_started_or_dismissed(): void {
        Onboarding::seed_on_activate();
        $this->assertTrue(Onboarding::show_banner());

        Onboarding::get_started();
        $this->assertFalse(Onboarding::show_banner());

        Onboarding::seed_on_activate();
        Onboarding::dismiss_banner();
        $this->assertFalse(Onboarding::show_banner());
        $this->assertTrue(Onboarding::is_active_ui());
    }

    public function test_get_started_clears_banner_and_restores(): void {
        Onboarding::seed_on_activate();
        Onboarding::minimize();
        Onboarding::get_started();
        $state = Onboarding::get_state();
        $this->assertSame('active', $state['status']);
        $this->assertFalse($state['show_banner']);
    }

    public function test_dismiss_banner_only(): void {
        Onboarding::seed_on_activate();
        Onboarding::dismiss_banner();
        $this->assertFalse(Onboarding::show_banner());
        $this->assertSame('active', Onboarding::get_state()['status']);
    }
}
