<?php
/**
 * Plugin Name: Noble Schema Lite
 * Description: Lightweight JSON-LD schema editor for posts and archives after Noble Schema offboarding.
 * Version: 1.0.0
 * Author: Noble Studios
 * Author URI: https://noblestudios.com
 * Text Domain: noble-schema-lite
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NS_LITE_VERSION', '1.0.0');
define('NS_LITE_FILE', __FILE__);
define('NS_LITE_DIR', __DIR__);
define('NS_LITE_META_KEY', '_ns_lite_schema');
define('NS_LITE_ARCHIVE_OPTION', 'ns_lite_archive_schema');
define('NS_LITE_COMPLETE_OPTION', 'ns_lite_offboarding_complete');
define('NS_LITE_PARENT_OPTION', 'ns_lite_parent_plugin');

require_once NS_LITE_DIR . '/includes/Migrator.php';
require_once NS_LITE_DIR . '/includes/Frontend.php';
require_once NS_LITE_DIR . '/includes/Meta_Box.php';
require_once NS_LITE_DIR . '/includes/Admin.php';
require_once NS_LITE_DIR . '/includes/Plugin.php';

NobleSchemaLite\Plugin::boot();
