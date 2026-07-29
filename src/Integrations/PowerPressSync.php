<?php

namespace SMP\Podcast\Integrations;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;

final class PowerPressSync implements ModuleInterface {
    private static bool $syncing = false;

    public function register(): void {
        if ( ! (bool) get_option( 'enable_powerpress_post_functionality', false ) ) {
            return;
        }

        add_action( 'save_post_' . PodcastSettings::content_type(), [ $this, 'sync_from_powerpress' ], 20, 3 );
        add_action( 'acf/save_post', [ $this, 'sync_to_powerpress' ], 30 );
        add_action( 'acf/input/admin_footer', [ $this, 'render_readonly_fields' ] );
    }

    public function sync_from_powerpress( int $post_id, \WP_Post $post, bool $update ): void {
        unset( $post, $update );
        if ( self::$syncing || ! self::eligible( $post_id ) || ! function_exists( 'powerpress_get_enclosure_data' ) ) {
            return;
        }

        $data = powerpress_get_enclosure_data( $post_id );
        if ( ! is_array( $data ) || empty( $data['url'] ) ) {
            return;
        }

        self::$syncing = true;
        self::update_acf_if_changed( 'audio_url', esc_url_raw( (string) $data['url'] ), $post_id );

        $attachment_id = attachment_url_to_postid( (string) $data['url'] );
        if ( $attachment_id > 0 ) {
            self::update_acf_if_changed( 'audio', $attachment_id, $post_id );
        }

        $map = [
            'duration' => 'duration',
            'summary' => 'summary',
            'episode_no' => 'number',
            'season' => 'season',
        ];
        foreach ( $map as $source => $target ) {
            if ( isset( $data[ $source ] ) && '' !== (string) $data[ $source ] ) {
                $value = 'duration' === $source ? self::normalize_duration( (string) $data[ $source ] ) : $data[ $source ];
                self::update_acf_if_changed( $target, $value, $post_id );
            }
        }
        self::update_acf_if_changed( 'active', 1, $post_id );
        self::$syncing = false;
    }

    public function sync_to_powerpress( mixed $post_id ): void {
        if ( self::$syncing || ! is_numeric( $post_id ) ) {
            return;
        }

        self::sync_audio( (int) $post_id );
    }

    /** @return array<string,mixed> */
    public static function sync_audio( int $post_id ): array {
        $before = [
            'audio_url' => (string) get_post_meta( $post_id, 'audio_url', true ),
            'enclosure' => (string) get_post_meta( $post_id, 'enclosure', true ),
        ];

        if ( ! self::eligible( $post_id ) ) {
            return [ 'changed' => false, 'before' => $before, 'after' => $before, 'message' => 'Skipped: content type does not match.' ];
        }

        $audio = function_exists( 'get_field' ) ? get_field( 'audio', $post_id ) : get_post_meta( $post_id, 'audio', true );
        [ $url, $attachment_id ] = self::normalize_audio( $audio );
        if ( '' === $url ) {
            return [ 'changed' => false, 'before' => $before, 'after' => $before, 'message' => 'Skipped: no valid audio file is attached.' ];
        }

        $path = $attachment_id > 0 ? get_attached_file( $attachment_id ) : '';
        $size = is_string( $path ) && is_file( $path ) ? (int) filesize( $path ) : 0;
        $mime = $attachment_id > 0 ? (string) get_post_mime_type( $attachment_id ) : '';
        if ( '' === $mime ) {
            $mime = (string) ( wp_check_filetype( $url )['type'] ?? 'audio/mpeg' );
        }

        $parts = preg_split( '/\r\n|\r|\n/', $before['enclosure'], 4 ) ?: [];
        $tail = isset( $parts[3] ) && '' !== trim( (string) $parts[3] ) ? (string) $parts[3] : '';
        $enclosure = $url . "\n" . $size . "\n" . ( $mime ?: 'audio/mpeg' );
        if ( '' !== $tail ) {
            $enclosure .= "\n" . $tail;
        }

        self::$syncing = true;
        self::update_acf_if_changed( 'audio_url', $url, $post_id );
        if ( $enclosure !== $before['enclosure'] ) {
            update_post_meta( $post_id, 'enclosure', $enclosure );
        }
        self::$syncing = false;

        $after = [
            'audio_url' => (string) get_post_meta( $post_id, 'audio_url', true ),
            'enclosure' => (string) get_post_meta( $post_id, 'enclosure', true ),
        ];

        return [
            'changed' => $before !== $after,
            'before' => $before,
            'after' => $after,
            'message' => $before !== $after ? 'Audio and enclosure metadata synchronized.' : 'Audio metadata was already synchronized.',
        ];
    }

    public function render_readonly_fields(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'post' !== $screen->base || PodcastSettings::content_type() !== $screen->post_type ) {
            return;
        }
        ?>
        <style>.acf-field[data-name="summary"] textarea[readonly],.acf-field[data-name="audio_url"] input[readonly],.acf-field[data-name="number"] input[readonly],.acf-field[data-name="duration"] input[readonly]{background:#f6f7f7;color:#50575e}</style>
        <script>jQuery(function($){$('.acf-field[data-name="summary"] textarea,.acf-field[data-name="audio_url"] input,.acf-field[data-name="number"] input,.acf-field[data-name="duration"] input').prop('readonly',true);});</script>
        <?php
    }

    private static function eligible( int $post_id ): bool {
        return $post_id > 0
            && PodcastSettings::is_podcast_content( $post_id )
            && ! wp_is_post_revision( $post_id )
            && ! wp_is_post_autosave( $post_id )
            && ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
    }

    /** @return array{0:string,1:int} */
    private static function normalize_audio( mixed $audio ): array {
        if ( is_array( $audio ) ) {
            $url = isset( $audio['url'] ) ? esc_url_raw( (string) $audio['url'] ) : '';
            $id = (int) ( $audio['ID'] ?? $audio['id'] ?? 0 );
            return [ $url, $id ];
        }
        if ( is_numeric( $audio ) ) {
            $id = (int) $audio;
            return [ esc_url_raw( (string) wp_get_attachment_url( $id ) ), $id ];
        }
        if ( is_string( $audio ) ) {
            $url = esc_url_raw( $audio );
            return [ $url, $url ? (int) attachment_url_to_postid( $url ) : 0 ];
        }
        return [ '', 0 ];
    }

    private static function normalize_duration( string $duration ): string {
        $parts = explode( ':', trim( $duration ) );
        return 3 === count( $parts ) && '0' === $parts[0] ? $parts[1] . ':' . $parts[2] : trim( $duration );
    }

    private static function update_acf_if_changed( string $field, mixed $value, int $post_id ): void {
        $current = function_exists( 'get_field' ) ? get_field( $field, $post_id, false ) : get_post_meta( $post_id, $field, true );
        if ( $current == $value ) {
            return;
        }

        if ( function_exists( 'update_field' ) ) {
            update_field( $field, $value, $post_id );
        } else {
            update_post_meta( $post_id, $field, $value );
        }
    }
}
