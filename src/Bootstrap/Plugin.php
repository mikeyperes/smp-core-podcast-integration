<?php

namespace SMP\Podcast\Bootstrap;

use Hexa\PluginCore\CoreBootstrap\CoreBootstrap;
use Hexa\PluginCore\CorePackageUpdates\CorePackageAjaxController;
use Hexa\PluginCore\CoreRuntime\PluginContext;
use Hexa\PluginCore\PluginUpdates\UpdaterAjaxController;
use Hexa\PluginCore\SnippetRegistry\SnippetAjaxController;
use Hexa\PluginCore\WpAdminTabs\CoreTabConfig;
use Hexa\PluginCore\WpAdminTabs\CoreTabModule;
use SMP\Podcast\Admin\Dashboard;
use SMP\Podcast\Admin\OperationsController;
use SMP\Podcast\Admin\PlaybackSettingsController;
use SMP\Podcast\Compatibility\LegacyCompatibility;
use SMP\Podcast\Config;
use SMP\Podcast\Content\ContentKind;
use SMP\Podcast\Content\DefaultHost;
use SMP\Podcast\Content\DefaultHostFieldPreview;
use SMP\Podcast\Diagnostics\IntegrationTests;
use SMP\Podcast\Frontend\ExternalLinks;
use SMP\Podcast\Frontend\HomeInteractions;
use SMP\Podcast\Frontend\InternalFeed;
use SMP\Podcast\Frontend\PersistentPlayer;
use SMP\Podcast\Frontend\Shortcodes;
use SMP\Podcast\Integrations\PowerPressSync;
use SMP\Podcast\Settings\LegacyOptionSynchronizer;
use SMP\Podcast\Support\RewriteLifecycle;
use SMP\Podcast\Support\Dependencies;

final class Plugin {
    private bool $booted = false;

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $context = new PluginContext(
            [
                'slug' => Config::SLUG,
                'basename' => Config::plugin_basename(),
                'version' => Config::VERSION,
                'path' => SMP_PODCAST_PLUGIN_ROOT . '/',
                'url' => plugin_dir_url( SMP_PODCAST_PLUGIN_FILE ),
                'github_repo' => Config::GITHUB_REPO,
                'admin_page' => Config::SETTINGS_PAGE,
                'capability' => Config::CAPABILITY,
            ]
        );

        $core = new CoreBootstrap( $context );
        $core
            ->add_module( Registries::content_types() )
            ->add_module( new ContentKind() )
            ->add_module( Registries::option_fields() )
            ->add_module( new Shortcodes() )
            ->add_module( new HomeInteractions() )
            ->add_module( new PersistentPlayer() )
            ->add_module( new DefaultHostFieldPreview() )
            ->add_module( new DefaultHost() )
            ->add_module( new PowerPressSync() )
            ->add_module( new InternalFeed() )
            ->add_module( new ExternalLinks() )
            ->add_module( new RewriteLifecycle() )
            ->add_module( new LegacyCompatibility() )
            ->add_module( new LegacyOptionSynchronizer() )
            ->add_module( new IntegrationTests() );

        if ( is_admin() || wp_doing_ajax() ) {
            $core
                ->add_module( new UpdaterAjaxController( \SMP\Podcast\updater_config() ) )
                ->add_module( new CorePackageAjaxController( \SMP\Podcast\core_package_config() ) )
                ->add_module(
                    new SnippetAjaxController(
                        Registries::snippets(),
                        [
                            'capability' => Config::CAPABILITY,
                            'nonce_action' => Config::NONCE_ACTION,
                            'nonce_field' => 'nonce',
                            'toggle_action' => 'smp_podcast_toggle_snippet',
                            'test_action' => 'smp_podcast_test_snippet',
                        ]
                    )
                )
                ->add_module( $this->core_tab_module() )
                ->add_module( new OperationsController() )
                ->add_module( new PlaybackSettingsController() )
                ->add_module( new Dashboard() );

            add_action( 'admin_notices', [ Dependencies::class, 'render_notices' ] );
        }

        $core->boot();
        $this->booted = true;
    }

    private function core_tab_module(): CoreTabModule {
        return new CoreTabModule(
            new CoreTabConfig(
                [
                    'tab_id' => 'hexa-core',
                    'label' => 'Hexa WP Core',
                    'tabs_filter' => 'smp_podcast_dashboard_tabs',
                    'render_filter' => 'smp_podcast_render_dashboard_tab',
                    'capability' => Config::CAPABILITY,
                    'core_root' => SMP_PODCAST_PLUGIN_ROOT . '/lib/hexa-wordpress-plugin-core',
                    'readme_path' => SMP_PODCAST_PLUGIN_ROOT . '/lib/hexa-wordpress-plugin-core/README.md',
                    'library_path' => SMP_PODCAST_PLUGIN_ROOT . '/lib/hexa-wordpress-plugin-core/HEXA_PLUGIN_CORE_LIBRARY.md',
                ]
            )
        );
    }
}
