<?php

namespace SMP\Podcast\Settings;

final class PodcastSettings {
    public const OPTIONS_POST_ID = 'option_podcast';
    private const PODCAST_META_KEYS = [ 'audio', 'audio_url', 'enclosure', 'hosts', 'profiles', 'guests' ];

    /** @return array{post_type:string,singular:string,plural:string,rewrite_slug:string} */
    public static function content(): array {
        $legacy_slug = self::first_option(
            [
                'option_podcast_general_settings_podcast_cpt_podcast_slug',
                'options_general_settings_podcast_cpt_podcast_slug',
            ],
            'post'
        );
        $legacy_singular = self::first_option(
            [
                'option_podcast_general_settings_podcast_cpt_podcast_singular_name',
                'options_general_settings_podcast_cpt_podcast_singular_name',
            ],
            'Post'
        );
        $legacy_plural = self::first_option(
            [
                'option_podcast_general_settings_podcast_cpt_podcast_plural_name',
                'options_general_settings_podcast_cpt_podcast_plural_name',
            ],
            'Posts'
        );

        $model = sanitize_key( (string) get_option( 'smp_podcast_content_model', '' ) );
        if ( '' === $model ) {
            $model = 'post' === sanitize_key( $legacy_slug ) ? 'post' : 'episode';
        }

        if ( 'post' === $model ) {
            return [
                'post_type' => 'post',
                'singular' => 'Post',
                'plural' => 'Posts',
                'rewrite_slug' => 'post',
            ];
        }

        return [
            'post_type' => 'episode',
            'singular' => sanitize_text_field( $legacy_singular ?: 'Podcast Episode' ),
            'plural' => sanitize_text_field( $legacy_plural ?: 'Podcast Episodes' ),
            'rewrite_slug' => sanitize_title( 'post' !== $legacy_slug ? $legacy_slug : 'podcast' ),
        ];
    }

    public static function content_type(): string {
        return self::content()['post_type'];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public static function scoped_query_args( array $args = [] ): array {
        $args['post_type'] = self::content_type();
        if ( 'post' !== self::content_type() ) {
            return $args;
        }

        $podcast_markers = [ 'relation' => 'OR' ];
        foreach ( self::PODCAST_META_KEYS as $key ) {
            $podcast_markers[] = [ 'key' => $key, 'compare' => 'EXISTS' ];
        }

        if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
            $args['meta_query'] = [ 'relation' => 'AND', $args['meta_query'], $podcast_markers ];
        } else {
            $args['meta_query'] = $podcast_markers;
        }

        return $args;
    }

    public static function is_podcast_content( int $post_id, bool $require_marker = true ): bool {
        if ( $post_id < 1 || self::content_type() !== get_post_type( $post_id ) ) {
            return false;
        }
        if ( 'post' !== self::content_type() || ! $require_marker ) {
            return true;
        }
        foreach ( self::PODCAST_META_KEYS as $key ) {
            if ( metadata_exists( 'post', $post_id, $key ) ) {
                return true;
            }
        }
        return false;
    }

    public static function default_host_id(): int {
        foreach ( [ 'option_podcast_default_host', 'options_default_host' ] as $key ) {
            $value = get_option( $key, null );
            if ( is_numeric( $value ) && (int) $value > 0 ) {
                return (int) $value;
            }
        }

        if ( function_exists( 'get_field' ) ) {
            foreach ( [ self::OPTIONS_POST_ID, 'option' ] as $context ) {
                $value = get_field( 'default_host', $context, false );
                if ( $value instanceof \WP_Post ) {
                    return (int) $value->ID;
                }
                if ( is_array( $value ) ) {
                    $value = $value['ID'] ?? $value['value'] ?? reset( $value );
                }
                if ( is_object( $value ) && isset( $value->ID ) ) {
                    return (int) $value->ID;
                }
                if ( is_numeric( $value ) && (int) $value > 0 ) {
                    return (int) $value;
                }
            }
        }

        return 0;
    }

    /** @return array<string,string> */
    public static function podcast_urls(): array {
        foreach ( [ 'option_podcast_podcast_urls', 'options_podcast_urls' ] as $prefix ) {
            $rows = [];
            foreach ( self::url_keys() as $key ) {
                $value = get_option( $prefix . '_' . $key, '' );
                if ( is_string( $value ) && '' !== trim( $value ) ) {
                    $rows[ $key ] = trim( $value );
                }
            }
            if ( $rows ) {
                return $rows;
            }
        }

        return [];
    }

    /** @return array<int,string> */
    public static function url_keys(): array {
        return [
            'youtube', 'listen_notes', 'spotify', 'soundcloud', 'google_podcast',
            'apple_podcast', 'amazon', 'pandora', 'iheart', 'stitcher', 'blubrry',
            'podchaser', 'deezer', 'tunein', 'anghami', 'jiosaavn', 'instagram',
            'rss', 'facebook', 'inc_verified_profile', 'audible', 'imdb', 'gaana',
        ];
    }

    private static function first_option( array $keys, string $default ): string {
        foreach ( $keys as $key ) {
            $value = get_option( $key, '' );
            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                return trim( (string) $value );
            }
        }

        return $default;
    }
}
