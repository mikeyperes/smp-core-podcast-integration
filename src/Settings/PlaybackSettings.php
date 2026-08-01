<?php

namespace SMP\Podcast\Settings;

final class PlaybackSettings {
    public const OPTION_NAME = 'smp_podcast_playback_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array {
        return [
            'enabled' => false,
            'ajax_navigation' => true,
            'video_enabled' => true,
            'show_mode_switch' => true,
            'sync_media_position' => true,
            'content_selector' => '[data-smp-ajax-root]',
            'excluded_paths' => "/wp-admin/\n/wp-login.php\n/wp-json/\n/feed/\n/cart/\n/checkout/\n/my-account/",
            'timeout_ms' => 10000,
            'transition_ms' => 180,
            'skip_back' => 15,
            'skip_forward' => 30,
            'show_cover' => true,
            'show_skip' => true,
            'show_rate' => true,
            'show_volume' => true,
            'show_download' => true,
            'show_close' => true,
            'media_session' => true,
            'remember_preferences' => true,
        ];
    }

    /** @return array<string,mixed> */
    public static function get(): array {
        $saved = get_option( self::OPTION_NAME, [] );
        return self::sanitize( is_array( $saved ) ? $saved : [] );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function sanitize( array $input ): array {
        $values = array_merge( self::defaults(), $input );

        return [
            'enabled' => self::boolean( $values['enabled'] ),
            'ajax_navigation' => self::boolean( $values['ajax_navigation'] ),
            'video_enabled' => self::boolean( $values['video_enabled'] ),
            'show_mode_switch' => self::boolean( $values['show_mode_switch'] ),
            'sync_media_position' => self::boolean( $values['sync_media_position'] ),
            'content_selector' => self::selector( $values['content_selector'] ),
            'excluded_paths' => self::paths( $values['excluded_paths'] ),
            'timeout_ms' => self::bounded_int( $values['timeout_ms'], 2000, 30000, 10000 ),
            'transition_ms' => self::bounded_int( $values['transition_ms'], 0, 1000, 180 ),
            'skip_back' => self::bounded_int( $values['skip_back'], 5, 120, 15 ),
            'skip_forward' => self::bounded_int( $values['skip_forward'], 5, 120, 30 ),
            'show_cover' => self::boolean( $values['show_cover'] ),
            'show_skip' => self::boolean( $values['show_skip'] ),
            'show_rate' => self::boolean( $values['show_rate'] ),
            'show_volume' => self::boolean( $values['show_volume'] ),
            'show_download' => self::boolean( $values['show_download'] ),
            'show_close' => self::boolean( $values['show_close'] ),
            'media_session' => self::boolean( $values['media_session'] ),
            'remember_preferences' => self::boolean( $values['remember_preferences'] ),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function save( array $input ): array {
        $settings = self::sanitize( $input );
        update_option( self::OPTION_NAME, $settings, false );
        return $settings;
    }

    /** @return array<string,mixed> */
    public static function public_config(): array {
        $settings = self::get();
        $paths = preg_split( '/\r\n|\r|\n/', (string) $settings['excluded_paths'] ) ?: [];

        return [
            'enabled' => (bool) $settings['enabled'],
            'ajaxNavigation' => (bool) $settings['ajax_navigation'],
            'videoEnabled' => (bool) $settings['video_enabled'],
            'showModeSwitch' => (bool) $settings['show_mode_switch'],
            'syncMediaPosition' => (bool) $settings['sync_media_position'],
            'contentSelector' => (string) $settings['content_selector'],
            'excludedPaths' => array_values( array_filter( array_map( 'trim', $paths ) ) ),
            'timeoutMs' => (int) $settings['timeout_ms'],
            'transitionMs' => (int) $settings['transition_ms'],
            'skipBack' => (int) $settings['skip_back'],
            'skipForward' => (int) $settings['skip_forward'],
            'showCover' => (bool) $settings['show_cover'],
            'showSkip' => (bool) $settings['show_skip'],
            'showRate' => (bool) $settings['show_rate'],
            'showVolume' => (bool) $settings['show_volume'],
            'showDownload' => (bool) $settings['show_download'],
            'showClose' => (bool) $settings['show_close'],
            'mediaSession' => (bool) $settings['media_session'],
            'rememberPreferences' => (bool) $settings['remember_preferences'],
        ];
    }

    private static function boolean( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return 1 === (int) $value;
        }
        return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
    }

    private static function bounded_int( mixed $value, int $minimum, int $maximum, int $default ): int {
        if ( ! is_numeric( $value ) ) {
            return $default;
        }
        return max( $minimum, min( $maximum, (int) $value ) );
    }

    private static function selector( mixed $value ): string {
        $selector = trim( wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' ) );
        if ( '' === $selector || strlen( $selector ) > 240 || preg_match( '/[,{}<>\x00-\x1F]/', $selector ) ) {
            return (string) self::defaults()['content_selector'];
        }
        if (
            preg_match( '/(^|[\s>+~])(?:html|body|header|footer|nav)(?=$|[\s.#:\[>+~])/i', $selector )
            || preg_match( '/(^|[\s>+~])(?:#page|\.site)(?=$|[\s.#:\[>+~])/i', $selector )
            || in_array( strtolower( $selector ), [ '*', ':root', 'main', '#wrapper', '.wrapper' ], true )
        ) {
            return (string) self::defaults()['content_selector'];
        }
        return $selector;
    }

    private static function paths( mixed $value ): string {
        $raw = is_scalar( $value ) ? (string) $value : '';
        $lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
        $paths = [];

        foreach ( $lines as $line ) {
            $line = trim( wp_strip_all_tags( $line ) );
            if ( '' === $line || '/' !== $line[0] || strlen( $line ) > 180 ) {
                continue;
            }
            $line = preg_replace( '/[^A-Za-z0-9_\-\.\/\*\?=&%]/', '', $line ) ?: '';
            if ( '' !== $line ) {
                $paths[] = $line;
            }
        }

        return implode( "\n", array_values( array_unique( $paths ) ) );
    }
}
