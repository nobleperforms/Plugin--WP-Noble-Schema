<?php
/**
 * Plugin Name: Noble Schema
 * Description: Structured Schema.org JSON-LD configuration and output for WordPress.
 * Version: 0.1.8
 * Author: Noble Studios
 * Author URI: https://noblestudios.com
 * Text Domain: noble-schema
 */

namespace NobleSchema;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin {
    private static ?Plugin $instance = null;

    private string $plugin_file;
    private ?Mapping\Resolver_Registry $resolver_registry = null;
    private ?Generator\Generator $generator = null;

    public static function get_instance(): Plugin {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->plugin_file = __FILE__;
        $this->load_dependencies();
        register_activation_hook($this->plugin_file, [Db\Installer::class, 'activate']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function plugin_file(): string {
        return $this->plugin_file;
    }

    private function load_dependencies(): void {
        $mapping_interface = __DIR__ . '/components/mapping/Resolver_Interface.php';
        if (is_readable($mapping_interface)) {
            require_once $mapping_interface;
        }

        foreach (glob(__DIR__ . '/components/*/*') as $path) {
            if (preg_match('/\.php$/', $path) && !str_contains($path, '/views/')) {
                if (str_ends_with($path, 'Resolver_Interface.php')) {
                    continue;
                }

                require_once $path;
            }
        }
        foreach (glob(__DIR__ . '/components/endpoints/*.php') as $path) {
            require_once $path;
        }
    }

    public function boot(): void {
        Db\Installer::maybe_upgrade();
        Update\Updater::maybe_log_version_change($this->plugin_file);

        (new Admin\Menu(__FILE__))->register();
        (new Admin\Tools_Page(__FILE__))->register();
        (new Admin\Settings_Page(__FILE__))->register();
        (new Admin\Onboarding(__FILE__))->register();
        (new Render\Frontend(new Db\Snapshot_Repository()))->register();
        (new Admin\Manual_Values_Meta_Box(
            new Db\Config_Repository(),
            new Db\Target_Repository(),
            new Db\Field_Repository(),
            new Db\Manual_Value_Repository(),
            new Templates\Template_Loader(__DIR__ . '/templates'),
            new Targeting\Matcher(),
        ))->register();

        (new Generator\Regen_Queue())->register();

        Conflict\Detector::register();

        add_action(\NobleSchema\Tools\Log::CRON_HOOK, static function (): void {
            \NobleSchema\Tools\Log::cleanup_expired();
        });
        if (\NobleSchema\Tools\Log::enabled()) {
            \NobleSchema\Tools\Log::schedule_cleanup();
        }

        $plugin_dir = __DIR__;
        (new Blocks\Schema_Block(
            new Db\Config_Repository(),
            new Db\Field_Repository(),
            new Templates\Template_Loader($plugin_dir . '/templates'),
        ))->register();

        add_action('save_post', function ($post_id, $post, $update) {
            if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
                return;
            }
            (new Cache\Block_Usage_Cache())->invalidate_all();
            plugin()->generator()->generate_for_post((int) $post_id);
        }, 20, 3);
    }

    public function generator(): Generator\Generator {
        if ($this->generator === null) {
            $this->generator = new Generator\Generator(
                new Db\Config_Repository(),
                new Db\Target_Repository(),
                new Db\Field_Repository(),
                new Db\Manual_Value_Repository(),
                new Db\Snapshot_Repository(),
                new Templates\Template_Loader(__DIR__ . '/templates'),
                new Targeting\Matcher(),
                $this->resolvers(),
                new Generator\Graph_Builder(),
            );
        }

        return $this->generator;
    }

    public function resolvers(): Mapping\Resolver_Registry {
        if ($this->resolver_registry === null) {
            $this->resolver_registry = $this->build_resolver_registry();
        }

        return $this->resolver_registry;
    }

    private function build_resolver_registry(): Mapping\Resolver_Registry {
        $registry = new Mapping\Resolver_Registry();
        $callback = new Mapping\Callback_Resolver();

        $registry->register('core', new Mapping\Core_Resolver());
        $registry->register('site_info', new Mapping\Site_Info_Resolver());
        $registry->register('post_meta', new Mapping\Post_Meta_Resolver());
        $registry->register('acf', new Mapping\Acf_Resolver());
        $registry->register('yoast', new Mapping\Yoast_Resolver());
        $registry->register('events_calendar', new Mapping\Events_Calendar_Resolver());
        $registry->register('static', new Mapping\Static_Resolver());
        $registry->register('manual_post', new Mapping\Manual_Resolver());
        $registry->register('manual_block', new Mapping\Manual_Resolver());
        $registry->register('taxonomy', new Mapping\Taxonomy_Resolver());
        $registry->register('block_attr', new Mapping\Block_Attr_Resolver());
        $registry->register('block_content', new Mapping\Block_Content_Resolver());
        $registry->register('callback', $callback);

        return $registry;
    }
}

Plugin::get_instance();

function plugin(): Plugin {
    return Plugin::get_instance();
}
