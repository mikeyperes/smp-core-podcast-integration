<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\ShortcodeRegistry\ShortcodeRegistry;
use Hexa\PluginCore\ShortcodeRegistry\ShortcodeDefinition;

final class Shortcodes implements ModuleInterface {
    public function register(): void {
        foreach ( self::registry()->all() as $tag => $definition ) {
            add_shortcode( $tag, self::callbacks()[ $tag ] );
        }
    }

    public static function registry(): ShortcodeRegistry {
        static $registry = null;
        if ( $registry instanceof ShortcodeRegistry ) { return $registry; }
        $registry = new ShortcodeRegistry();
        $examples = [
            'smp_episode_presence' => '[smp_episode_presence field="audio" post_id="{input}"]',
            'mpp_episode_presence' => '[mpp_episode_presence field="audio" post_id="{input}"]',
            'smp_listen_button' => '[smp_listen_button post_id="{input}" label="Listen" class=""]',
            'smp_watch_button' => '[smp_watch_button post_id="{input}" label="Watch" class="" element="a"]',
            'podcast_url' => '[podcast_url social="spotify"]',
            'episode_fields' => '[episode_fields post_id="{input}" name="audio_url"]',
            'article_guests' => '[article_guests post_id="{input}"]',
            'podcast_hosts' => '[podcast_hosts post_id="{input}"]',
            'display_single_episode_hosts' => '[display_single_episode_hosts post_id="{input}" must_have_thumbnail="false"]',
            'guest_grid' => '[guest_grid]',
            'display_guest_profile_info' => '[display_guest_profile_info post_id="{input}"]',
            'display_host_profile_info' => '[display_host_profile_info post_id="{input}"]',
            'podcast_host' => '[podcast_host id="title"]',
        ];
        foreach ( self::callbacks() as $tag => $callback ) {
            $registry->add( new ShortcodeDefinition(
                $tag,
                ucwords( str_replace( '_', ' ', $tag ) ),
                $examples[ $tag ] ?? '[' . $tag . ' post_id="{input}"]',
                'Existing ' . $tag . ' output; empty output is valid when its episode or profile data is absent.',
                'Live output below; callback registration and media/profile regression checks in tests/run.php.',
                '0',
                'Post ID (0 uses the current post)'
            ) );
        }
        return $registry;
    }

    /** @return array<int,string> */
    public static function tags(): array {
        return array_keys( self::callbacks() );
    }

    /** @return array<string,callable> */
    private static function callbacks(): array {
        return array_merge( EpisodeData::callbacks(), [
            'smp_listen_button' => [ ShortcodeCallbacks::class, 'listen_button' ],
            'smp_watch_button' => [ ShortcodeCallbacks::class, 'watch_button' ],
            'podcast_url' => [ ShortcodeCallbacks::class, 'podcast_url' ],
            'episode_fields' => [ ShortcodeCallbacks::class, 'episode_fields' ],
            'article_guests' => [ ShortcodeCallbacks::class, 'article_guests' ],
            'podcast_hosts' => [ ShortcodeCallbacks::class, 'podcast_hosts' ],
            'display_single_episode_hosts' => [ ShortcodeCallbacks::class, 'episode_hosts' ],
            'guest_grid' => [ ShortcodeCallbacks::class, 'guest_grid' ],
            'display_guest_profile_info' => [ ShortcodeCallbacks::class, 'guest_profiles' ],
            'display_host_profile_info' => [ ShortcodeCallbacks::class, 'host_profiles' ],
            'podcast_host' => [ ShortcodeCallbacks::class, 'podcast_host' ],
        ] );
    }
}
