<?php

namespace SMP\Podcast\Settings;

final class PlaybackSettings {
    public const OPTION_NAME = 'smp_podcast_playback_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array {
        return [
            'enabled' => false,
            'ajax_player' => false,
            'video_enabled' => true,
            'show_mode_switch' => true,
            'sync_media_position' => true,
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
        if ( ! array_key_exists( 'ajax_player', $input ) && array_key_exists( 'ajax_navigation', $input ) ) {
            $input['ajax_player'] = $input['ajax_navigation'];
        }
        $values = array_merge( self::defaults(), $input );

        return [
            'enabled' => self::boolean( $values['enabled'] ),
            'ajax_player' => self::boolean( $values['ajax_player'] ),
            'video_enabled' => self::boolean( $values['video_enabled'] ),
            'show_mode_switch' => self::boolean( $values['show_mode_switch'] ),
            'sync_media_position' => self::boolean( $values['sync_media_position'] ),
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

        return [
            'enabled' => (bool) $settings['enabled'],
            'ajaxPlayer' => (bool) $settings['ajax_player'],
            'videoEnabled' => (bool) $settings['video_enabled'],
            'showModeSwitch' => (bool) $settings['show_mode_switch'],
            'syncMediaPosition' => (bool) $settings['sync_media_position'],
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

    public static function navigation_ready(): bool {
        return class_exists( \HWS\BaseTools\PluginRuntime\PluginMetadata::class )
            && version_compare( \HWS\BaseTools\PluginRuntime\PluginMetadata::VERSION, '13.47.0', '>=' )
            && class_exists( \HWS\BaseTools\AjaxNavigation\Settings::class )
            && ! empty( \HWS\BaseTools\AjaxNavigation\Settings::get()['enabled'] );
    }

    public static function permanent(): bool {
        return self::get()['ajax_player'] && self::navigation_ready();
    }

    public static function navigation_settings_url(): string {
        return admin_url( 'admin.php?page=hws-core-tools&tab=ajax-navigation' );
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

}
