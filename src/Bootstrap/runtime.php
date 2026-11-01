<?php

namespace SMP\Podcast;

defined( 'ABSPATH' ) || exit;

Support\BootstrapMigration::register( Config::SLUG, Config::MAIN_FILE );

function updater_config(): \Hexa\PluginCore\PluginUpdates\UpdaterConfig {
    static $config = null;

    if ( $config instanceof \Hexa\PluginCore\PluginUpdates\UpdaterConfig ) {
        return $config;
    }

    $config = \Hexa\PluginCore\PluginUpdates\UpdaterConfig::from_plugin_file(
        SMP_PODCAST_PLUGIN_FILE,
        Config::GITHUB_REPO,
        [
            'plugin_slug'               => Config::SLUG,
            'proper_folder_name'        => Config::SLUG,
            'runtime_folder_name'       => Config::SLUG,
            'plugin_basename'           => Config::plugin_basename(),
            'canonical_plugin_basename' => Config::SLUG . '/' . Config::MAIN_FILE,
            'plugin_starter_file'       => Config::MAIN_FILE,
            'github_branch'             => Config::GITHUB_BRANCH,
            'requires'                  => '6.2',
            'requires_php'              => '8.0',
            'tested'                    => '7.0',
            'nonce_action'              => Config::NONCE_ACTION,
            'nonce_param'               => 'nonce',
            'ajax_action_prefix'        => 'smp_podcast_updater',
            'progress_key'              => 'smp_podcast_update_progress',
        ]
    );

    return $config;
}

function core_package_config(): \Hexa\PluginCore\CorePackageUpdates\CorePackageConfig {
    static $config = null;

    if ( $config instanceof \Hexa\PluginCore\CorePackageUpdates\CorePackageConfig ) {
        return $config;
    }

    $config = \Hexa\PluginCore\CorePackageUpdates\CorePackageConfig::from_core_root(
        SMP_PODCAST_PLUGIN_ROOT . '/lib/hexa-wordpress-plugin-core',
        [
            'github_repo'        => 'mikeyperes/hexa-wordpress-plugin-core',
            'github_branch'      => 'main',
            'nonce_action'       => Config::NONCE_ACTION,
            'nonce_param'        => 'nonce',
            'ajax_action_prefix' => 'smp_podcast_core_package',
            'cache_key'          => 'smp_podcast_hexa_core_package',
        ]
    );

    return $config;
}

function boot_github_updater(): void {
    if ( ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }

    ( new \Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater( updater_config() ) )->register();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot_github_updater', 20 );

function boot_plugin(): void {
    static $plugin = null;

    if ( ! $plugin instanceof Bootstrap\Plugin ) {
        $plugin = new Bootstrap\Plugin();
    }

    $plugin->boot();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot_plugin', 30 );

function activate_plugin(): void {
    Support\RewriteLifecycle::schedule();
}
register_activation_hook( SMP_PODCAST_PLUGIN_FILE, __NAMESPACE__ . '\\activate_plugin' );

function deactivate_plugin(): void {
    flush_rewrite_rules( false );
}
register_deactivation_hook( SMP_PODCAST_PLUGIN_FILE, __NAMESPACE__ . '\\deactivate_plugin' );
