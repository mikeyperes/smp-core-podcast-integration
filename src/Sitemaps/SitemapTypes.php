<?php

namespace SMP\Podcast\Sitemaps;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\Sitemaps\SitemapProviders;

defined( 'ABSPATH' ) || exit;

/**
 * This plugin owns no sitemaps. It gives one site-type rule: a podcast site's
 * episodes are its posts, so the authors sitemap is off.
 */
final class SitemapTypes implements ModuleInterface {
    public const SLUG = 'smp-core-podcast-integration';

    public static function definitions(): array {
        return [
            [ 'id' => 'authors', 'site_types' => [ 'podcast_website' ], 'recommended' => false, 'why' => 'On a podcast site the episodes are the posts; the host and guests have their own pages.' ],
        ];
    }

    public function register(): void {
        if ( ! class_exists( SitemapProviders::class ) ) {
            return;
        }
        SitemapProviders::register( self::SLUG, 'SMP Core Podcast Integration', admin_url( 'options-general.php?page=' . \SMP\Podcast\Config::SETTINGS_PAGE ), self::definitions() );
    }
}
