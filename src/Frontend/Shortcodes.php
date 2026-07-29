<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;

final class Shortcodes implements ModuleInterface {
    public function register(): void {
        foreach ( self::callbacks() as $tag => $callback ) {
            add_shortcode( $tag, $callback );
        }
    }

    /** @return array<int,string> */
    public static function tags(): array {
        return array_keys( self::callbacks() );
    }

    /** @return array<string,callable> */
    private static function callbacks(): array {
        return [
            'podcast_url' => [ ShortcodeCallbacks::class, 'podcast_url' ],
            'episode_fields' => [ ShortcodeCallbacks::class, 'episode_fields' ],
            'article_guests' => [ ShortcodeCallbacks::class, 'article_guests' ],
            'podcast_hosts' => [ ShortcodeCallbacks::class, 'podcast_hosts' ],
            'display_single_episode_hosts' => [ ShortcodeCallbacks::class, 'episode_hosts' ],
            'guest_grid' => [ ShortcodeCallbacks::class, 'guest_grid' ],
            'display_guest_profile_info' => [ ShortcodeCallbacks::class, 'guest_profiles' ],
            'display_host_profile_info' => [ ShortcodeCallbacks::class, 'host_profiles' ],
            'podcast_host' => [ ShortcodeCallbacks::class, 'podcast_host' ],
        ];
    }
}
