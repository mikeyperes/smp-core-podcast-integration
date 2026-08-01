<?php

namespace SMP\Podcast\Frontend;

final class VideoSourceResolver {
    /** @return array{id:string,watch_url:string,embed_url:string}|array{} */
    public static function resolve( int $post_id ): array {
        if ( $post_id < 1 || ! get_post( $post_id ) ) {
            return [];
        }

        $values = [];
        if ( function_exists( 'get_field' ) ) {
            $values[] = get_field( 'urls_youtube', $post_id );
            $urls = get_field( 'urls', $post_id );
            if ( is_array( $urls ) ) {
                $values[] = $urls['youtube'] ?? '';
            }
        }
        $values[] = get_post_meta( $post_id, 'urls_youtube', true );

        foreach ( $values as $value ) {
            $video_id = self::video_id( $value );
            if ( '' === $video_id ) {
                continue;
            }

            $resolved = [
                'id' => $video_id,
                'watch_url' => 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ),
                'embed_url' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $video_id ),
            ];
            return (array) apply_filters( 'smp_podcast_video_source', $resolved, $post_id );
        }

        return [];
    }

    public static function video_id( mixed $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( preg_match( '/^([A-Za-z0-9_-]{11})(?:[?&].*)?$/', $value, $direct_match ) ) {
            return $direct_match[1];
        }

        if ( ! preg_match( '#^https?://#i', $value ) ) {
            return '';
        }

        $parts = wp_parse_url( $value );
        if ( ! is_array( $parts ) ) {
            return '';
        }
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $host = preg_replace( '/^www\./', '', $host ) ?: '';
        $path = trim( (string) ( $parts['path'] ?? '' ), '/' );
        $candidate = '';

        if ( 'youtu.be' === $host ) {
            $candidate = explode( '/', $path )[0] ?? '';
        } elseif ( in_array( $host, [ 'youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com' ], true ) ) {
            if ( 'watch' === $path ) {
                parse_str( (string) ( $parts['query'] ?? '' ), $query );
                $candidate = is_scalar( $query['v'] ?? '' ) ? (string) $query['v'] : '';
            } elseif ( preg_match( '#^(?:embed|shorts|live)/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $match ) ) {
                $candidate = $match[1];
            }
        }

        return preg_match( '/^[A-Za-z0-9_-]{11}$/', $candidate ) ? $candidate : '';
    }
}
