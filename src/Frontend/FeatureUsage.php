<?php

namespace SMP\Podcast\Frontend;

use SMP\Podcast\Settings\PodcastSettings;

/** Request-local use detection; no option, content or template mutation. */
final class FeatureUsage {
    private static bool $player_required = false;

    public static function home(): bool {
        return function_exists( 'is_front_page' ) && is_front_page();
    }

    public static function player(): bool {
        if ( self::$player_required || self::home() ) { return true; }
        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
            return $id > 0 && PodcastSettings::is_podcast_content( $id );
        }
        return false;
    }

    /** Called only after a media shortcode has resolved usable media. */
    public static function require_player(): void {
        self::$player_required = true;
        do_action( 'smp_podcast_player_required' );
    }
}
