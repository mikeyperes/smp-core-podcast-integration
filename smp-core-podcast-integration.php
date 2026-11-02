<?php
/**
 * Plugin Name: Scale My Podcast - Core Functionality
 * Description: Podcast content, fields, feeds, shortcodes, and PowerPress integration for Hexa WordPress sites.
 * Author: Michael Peres
 * Plugin URI: https://github.com/mikeyperes/smp-core-podcast-integration
 * Version: 3.6.3
 * Text Domain: smp-core-podcast-integration
 * Domain Path: /languages
 * Author URI: https://michaelperes.com
 * GitHub Plugin URI: https://github.com/mikeyperes/smp-core-podcast-integration
 * GitHub Branch: main
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

namespace SMP\Podcast;

defined( 'ABSPATH' ) || exit;

if ( defined( 'SMP_PODCAST_BOOTSTRAPPED' ) ) {
    return;
}

define( 'SMP_PODCAST_BOOTSTRAPPED', true );
define( 'SMP_PODCAST_PLUGIN_FILE', __FILE__ );
define( 'SMP_PODCAST_PLUGIN_ROOT', __DIR__ );

require_once __DIR__ . '/src/Support/Autoloader.php';
Support\Autoloader::register( __DIR__ . '/src' );

$hexa_core_root = __DIR__ . '/lib/hexa-wordpress-plugin-core';
require_once $hexa_core_root . '/bootstrap.php';
\hexa_plugin_core_register_package(
    Config::SLUG,
    $hexa_core_root,
    [ 'minimum_version' => '1.1.9' ]
);

require_once __DIR__ . '/src/Bootstrap/runtime.php';
