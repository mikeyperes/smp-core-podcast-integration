<?php

namespace SMP\Podcast\Pages;

use Hexa\PluginCore\SiteStructure\PageStructureManager;

/** Podcast page catalog. Query layouts never become ordinary pages. */
final class PageTypes {
    public static function definitions(): array {
        $pages = [
            'homepage' => [ 'title' => 'Homepage', 'slug' => '/', 'kind' => 'layout', 'content_source' => 'WordPress front page' ],
            'single_episode' => [ 'title' => 'Single episode', 'slug' => '/<episode>/', 'kind' => 'layout', 'content_source' => 'Podcast episode query and episode data', 'slug_source' => 'WordPress postname permalink' ],
            'episodes' => [ 'title' => 'Episodes', 'slug' => 'episodes', 'content_source' => 'Podcast episode directory' ],
            'episode_category' => [ 'title' => 'Episode category', 'slug' => '/category/<name>/', 'kind' => 'layout', 'content_source' => 'Podcast episode category query' ],
            'episode_tag' => [ 'title' => 'Episode tag', 'slug' => '/tag/<name>/', 'kind' => 'layout', 'content_source' => 'Podcast episode tag query' ],
            'podcast_host' => [ 'title' => 'Podcast Host', 'slug' => 'podcast-host', 'parent' => 'about', 'content_source' => 'Podcast default host and profile relationships' ],
            'voices' => [ 'title' => 'Voices', 'slug' => 'voices', 'content_source' => 'Live Voices page: host, guests, co-hosts and team overview', 'title_source' => 'WordPress Voices page title' ],
            'guests' => [ 'title' => 'Guests', 'slug' => 'guest', 'kind' => 'layout', 'content_source' => 'WordPress guest archive' ],
            'guest_profile' => [ 'title' => 'Single guest profile', 'slug' => '/guest/<name>/', 'parent' => 'guests', 'kind' => 'profile', 'repeat_for' => 'podcast_guests', 'content_source' => 'Verified Profiles guest record and related episodes' ],
            'become_guest' => [ 'title' => 'Become a Guest', 'slug' => 'become-a-guest', 'design' => 'text', 'content_source' => 'Site guest application copy and form' ],
        ];
        foreach ( $pages as &$page ) {
            $page += [ 'slugs' => [], 'parent' => '', 'design' => 'custom', 'enabled_by_default' => true, 'conditions' => [], 'kind' => 'page' ];
        }
        unset( $page );
        return $pages;
    }

    public static function pages(): array {
        return array_filter( self::definitions(), static fn( array $page ): bool => 'page' === $page['kind'] );
    }

    /** Explicit setup only: defining the catalog never creates or publishes pages. */
    public static function manager(): PageStructureManager {
        return new PageStructureManager( [
            'pages' => self::pages(),
            'option_prefix' => 'smp_podcast_page_',
            'reuse_existing_pages' => true,
            'created_page_status' => 'draft',
            'select_post_statuses' => [ 'publish', 'draft', 'private' ],
            'assignment_statuses' => [ 'publish', 'draft', 'private' ],
        ] );
    }
}
