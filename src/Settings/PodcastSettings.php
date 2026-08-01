<?php

namespace SMP\Podcast\Settings;

use SMP\Podcast\Content\ContentKind;

final class PodcastSettings {
    public const OPTIONS_POST_ID = 'option_podcast';
    public const LEGACY_MARKER_FALLBACK_OPTION = 'smp_podcast_legacy_marker_fallback_enabled';
    // `profiles` belongs to ordinary editorial relationships and must never
    // turn an unclassified article into a legacy podcast episode.
    private const PODCAST_META_KEYS = [ 'audio', 'audio_url', 'enclosure', 'hosts', 'guests' ];

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

    /** @return array<int,string> */
    public static function legacy_marker_keys(): array {
        return self::PODCAST_META_KEYS;
    }

    /**
     * Compatibility is enabled only until the reviewed content-kind backfill
     * completes. Missing means enabled so an upgrade cannot hide legacy shows;
     * the guarded migration writes an explicit false cutover value.
     */
    public static function legacy_marker_fallback_enabled(): bool {
        $missing = new \stdClass();
        $value = get_option( self::LEGACY_MARKER_FALLBACK_OPTION, $missing );
        if ( $missing === $value ) {
            return true;
        }
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return 0 !== (int) $value;
        }
        if ( is_string( $value ) ) {
            return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'false', 'off', 'no' ], true );
        }
        return (bool) $value;
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public static function scoped_query_args( array $args = [] ): array {
        $post_type = self::content_type();
        $args['post_type'] = $post_type;
        $content_scope = self::content_scope_clause();

        if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
            $args['meta_query'] = [ 'relation' => 'AND', $args['meta_query'], $content_scope ];
        } else {
            $args['meta_query'] = [ $content_scope ];
        }

        $duplicate_ids = ContentKind::duplicate_post_ids( $post_type );
        if ( null === $duplicate_ids ) {
            // Never broaden a podcast query when row integrity is unknown.
            $args['post__in'] = [ 0 ];
            unset( $args['post__not_in'] );
        } elseif ( $duplicate_ids ) {
            if ( isset( $args['post__in'] ) ) {
                $included = array_values( array_unique( array_filter( array_map( 'absint', (array) $args['post__in'] ) ) ) );
                $included = array_values( array_diff( $included, $duplicate_ids ) );
                $args['post__in'] = $included ?: [ 0 ];
            } else {
                $excluded = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['post__not_in'] ?? [] ) ) ) ) );
                $args['post__not_in'] = array_values( array_unique( array_merge( $excluded, $duplicate_ids ) ) );
                sort( $args['post__not_in'], SORT_NUMERIC );
            }
        }

        return $args;
    }

    /** @return array<string,mixed> */
    public static function content_scope_clause(): array {
        $explicit_episode = [
            'key' => ContentKind::META_KEY,
            'value' => ContentKind::EPISODE,
            'compare' => '=',
        ];
        $unclassified = [
            'key' => ContentKind::META_KEY,
            'compare' => 'NOT EXISTS',
        ];

        // A dedicated episode CPT is authoritative unless it is explicitly
        // vetoed as an article. Invalid explicit values also fail closed.
        if ( 'post' !== self::content_type() ) {
            return [ 'relation' => 'OR', $explicit_episode, $unclassified ];
        }

        if ( ! self::legacy_marker_fallback_enabled() ) {
            return $explicit_episode;
        }

        $legacy_markers = [
            'key' => self::legacy_marker_keys(),
            'compare_key' => 'IN',
            'compare' => 'EXISTS',
        ];

        // Explicit episode is authoritative. Only truly unclassified legacy
        // posts may fall back to historical metadata markers; an explicit
        // article can therefore never leak into podcast queries.
        return [
            'relation' => 'OR',
            $explicit_episode,
            [ 'relation' => 'AND', $unclassified, $legacy_markers ],
        ];
    }

    public static function is_podcast_content( int $post_id, bool $require_marker = true ): bool {
        if ( $post_id < 1 || self::content_type() !== get_post_type( $post_id ) ) {
            return false;
        }

        if ( ContentKind::has_explicit_value( $post_id ) ) {
            if ( ContentKind::is_article( $post_id ) ) {
                return false;
            }

            // Only a valid explicit episode has authority. Existing invalid
            // protected metadata does not regain access through legacy fields.
            return ContentKind::is_episode( $post_id );
        }

        if ( 'post' !== self::content_type() ) {
            return true;
        }
        if ( ! self::legacy_marker_fallback_enabled() ) {
            return false;
        }
        if ( ! $require_marker ) {
            return true;
        }
        foreach ( self::legacy_marker_keys() as $key ) {
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
