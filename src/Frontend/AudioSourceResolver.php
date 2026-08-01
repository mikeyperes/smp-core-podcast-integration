<?php

namespace SMP\Podcast\Frontend;

final class AudioSourceResolver {
    /** @return array<string,mixed> */
    public static function resolve( int $post_id ): array {
        if ( $post_id < 1 || ! get_post( $post_id ) ) {
            return [];
        }

        $powerpress = self::powerpress( $post_id );
        $direct = self::direct_audio( $post_id );
        $enclosure = self::enclosure( $post_id );
        $playback_url = self::valid_url( $powerpress['url'] ?? '' )
            ?: self::valid_url( $direct['url'] ?? '' )
            ?: self::valid_url( $enclosure['url'] ?? '' );

        if ( '' === $playback_url ) {
            return [];
        }

        $download_url = self::valid_url( $direct['url'] ?? '' )
            ?: self::valid_url( $enclosure['url'] ?? '' )
            ?: $playback_url;
        $duration = self::duration( $powerpress['duration'] ?? ( $enclosure['duration'] ?? '' ) );
        $cover_size = apply_filters( 'smp_podcast_player_cover_image_size', 'medium_large', $post_id );
        $cover_size = is_string( $cover_size ) || is_array( $cover_size ) ? $cover_size : 'medium_large';
        $image = function_exists( 'get_the_post_thumbnail_url' )
            ? (string) ( get_the_post_thumbnail_url( $post_id, $cover_size ) ?: '' )
            : '';

        $resolved = [
            'post_id' => $post_id,
            'playback_url' => $playback_url,
            'download_url' => $download_url,
            'duration' => $duration['label'],
            'duration_seconds' => $duration['seconds'],
            'source' => '' !== self::valid_url( $powerpress['url'] ?? '' ) ? 'powerpress' : ( '' !== self::valid_url( $direct['url'] ?? '' ) ? 'audio' : 'enclosure' ),
            'title' => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
            'permalink' => self::valid_url( get_permalink( $post_id ) ),
            'image' => self::valid_url( $image ),
        ];

        return (array) apply_filters( 'smp_podcast_audio_source', $resolved, $post_id );
    }

    /** @return array{label:string,seconds:int} */
    public static function duration( mixed $value ): array {
        if ( is_numeric( $value ) ) {
            $seconds = max( 0, (int) round( (float) $value ) );
            return [ 'label' => self::format_duration( $seconds ), 'seconds' => $seconds ];
        }

        $label = trim( wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' ) );
        if ( '' === $label || ! preg_match( '/^\d{1,3}(?::\d{1,2}){1,2}$/', $label ) ) {
            return [ 'label' => '', 'seconds' => 0 ];
        }

        $parts = array_map( 'intval', explode( ':', $label ) );
        $seconds = 0;
        foreach ( $parts as $part ) {
            $seconds = ( $seconds * 60 ) + $part;
        }

        return [ 'label' => self::format_duration( $seconds ), 'seconds' => $seconds ];
    }

    /** @return array<string,mixed> */
    private static function powerpress( int $post_id ): array {
        if ( ! function_exists( 'powerpress_get_enclosure_data' ) ) {
            return [];
        }

        $feed_slug = (string) apply_filters( 'smp_podcast_powerpress_feed_slug', 'podcast', $post_id );
        try {
            $data = powerpress_get_enclosure_data( $post_id, $feed_slug );
        } catch ( \Throwable ) {
            return [];
        }
        if ( ! is_array( $data ) ) {
            return [];
        }

        return [
            'url' => $data['url'] ?? $data['media_url'] ?? '',
            'duration' => $data['duration'] ?? '',
        ];
    }

    /** @return array{url:string} */
    private static function direct_audio( int $post_id ): array {
        $values = [];
        if ( function_exists( 'get_field' ) ) {
            $values[] = get_field( 'audio', $post_id );
            $values[] = get_field( 'audio', $post_id, false );
            $values[] = get_field( 'audio_url', $post_id );
        }
        $values[] = get_post_meta( $post_id, 'audio', true );
        $values[] = get_post_meta( $post_id, 'audio_url', true );

        foreach ( $values as $value ) {
            $url = self::url_from_value( $value );
            if ( '' !== $url ) {
                return [ 'url' => $url ];
            }
        }

        return [ 'url' => '' ];
    }

    /** @return array{url:string,duration:string} */
    private static function enclosure( int $post_id ): array {
        $value = get_post_meta( $post_id, 'enclosure', true );
        if ( is_array( $value ) ) {
            return [
                'url' => self::url_from_value( $value['url'] ?? reset( $value ) ),
                'duration' => is_scalar( $value['duration'] ?? '' ) ? (string) $value['duration'] : '',
            ];
        }

        $lines = preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? trim( (string) $value ) : '' ) ?: [];
        return [
            'url' => self::valid_url( $lines[0] ?? '' ),
            'duration' => '',
        ];
    }

    private static function url_from_value( mixed $value ): string {
        if ( is_object( $value ) ) {
            $value = $value->url ?? $value->ID ?? $value->id ?? '';
        }
        if ( is_array( $value ) ) {
            $value = $value['url'] ?? $value['URL'] ?? $value['ID'] ?? $value['id'] ?? $value['value'] ?? '';
        }
        if ( is_numeric( $value ) && function_exists( 'wp_get_attachment_url' ) ) {
            $value = wp_get_attachment_url( (int) $value );
        }
        return self::valid_url( $value );
    }

    private static function valid_url( mixed $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $url = trim( (string) $value );
        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
            return '';
        }
        if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
            return '';
        }
        return function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( $url ) : ( filter_var( $url, FILTER_SANITIZE_URL ) ?: '' );
    }

    private static function format_duration( int $seconds ): string {
        if ( $seconds < 1 ) {
            return '';
        }
        $hours = intdiv( $seconds, 3600 );
        $minutes = intdiv( $seconds % 3600, 60 );
        $remaining = $seconds % 60;
        return $hours > 0
            ? sprintf( '%d:%02d:%02d', $hours, $minutes, $remaining )
            : sprintf( '%d:%02d', $minutes, $remaining );
    }
}
