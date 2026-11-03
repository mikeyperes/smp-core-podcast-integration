<?php

namespace SMP\Podcast\Pages;

use Hexa\PluginCore\SiteStructure\PageStructureManager;

/** Podcast page catalog. Query layouts never become ordinary pages. */
final class PageTypes {
    /** Historical keys remain resolvable by catalog consumers at their data owner. */
    public const MOVED = [
        'homepage' => ['hws-base-tools', 'homepage'],
        'episode_category' => ['hws-base-tools', 'category'],
        'episode_tag' => ['hws-base-tools', 'tag'],
        'voices' => ['smp-verified-profiles', 'voices'],
        'guests' => ['smp-verified-profiles', 'voices'],
        'guest_profile' => ['smp-verified-profiles', 'guest_profile'],
    ];

    public static function definitions(): array {
        $pages = [
            'single_episode' => [ 'parent' => 'episodes', 'title' => 'Single episode', 'slug' => '/<episode>/', 'kind' => 'layout', 'content_source' => 'Podcast episode query and episode data', 'slug_source' => 'WordPress postname permalink' ],
            'episodes' => [ 'title' => 'Episodes', 'slug' => 'episodes', 'content_source' => 'Podcast episode directory' ],
            'podcast_host' => [ 'title' => 'Podcast Host', 'slug' => 'podcast-host', 'parent' => 'about', 'content_source' => 'Podcast default host and profile relationships' ],
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
