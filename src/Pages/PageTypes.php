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
            'homepage'=>['title'=>'Home (series)','slug'=>'home','slugs'=>['home-after-hours'],'content_source'=>'Podcast series and existing WordPress front page'],
            'episodes'=>['title'=>'Episodes','slug'=>'episodes','content_source'=>'Podcast episode directory'],
            'season'=>['title'=>'Season page','slug'=>'/season/<n>/','kind'=>'layout','content_source'=>'podcast_season taxonomy archive'],
            'single_episode'=>['title'=>'Episode page','slug'=>'/<episode>/','kind'=>'layout','content_source'=>'Podcast episode query and episode data'],
            'guests'=>['title'=>'Guests','slug'=>'voices','slugs'=>['guests'],'content_source'=>'Verified profiles directory'],
            'guest_profile'=>['title'=>'Guest page','slug'=>'/profile/<guest>/','kind'=>'layout','content_source'=>'Verified Person profile'],
            'podcast_host'=>['title'=>'Host page','slug'=>'podcast-host','parent'=>'about','content_source'=>'Configured primary host profile'],
            'about'=>['title'=>'About','slug'=>'about','design'=>'text','content_source'=>'Site about copy'],
            'about_podcast'=>['title'=>'About the Podcast','slug'=>'about-the-podcast','slugs'=>['about-the-michael-peres-podcast'],'parent'=>'about','design'=>'text','content_source'=>'Site about copy'],
            'contact'=>['title'=>'Connect','slug'=>'connect','slugs'=>['contact'],'design'=>'text','content_source'=>'Site contact information'],
            'press_releases'=>['title'=>'Press releases','slug'=>'press-releases','content_source'=>'Publication press release directory'],
            'badges'=>['title'=>'Badges','slug'=>'badges','content_source'=>'Podcast brand badges'],
            'welcome'=>['title'=>'Welcome','slug'=>'welcome','content_source'=>'Podcast welcome content'],
            'become_a_guest'=>['title'=>'Become a Guest','slug'=>'become-a-guest','slugs'=>['be-a-guest'],'content_source'=>'Guest application form'],
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
