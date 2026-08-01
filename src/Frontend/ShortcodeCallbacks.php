<?php

namespace SMP\Podcast\Frontend;

use SMP\Podcast\Settings\PodcastSettings;

final class ShortcodeCallbacks {
    /** @param array<string,mixed> $atts */
    public static function listen_button( array $atts = [] ): string {
        $atts = shortcode_atts(
            [
                'post_id' => 0,
                'label' => 'Listen',
                'class' => '',
            ],
            $atts,
            'smp_listen_button'
        );
        $post_id = self::post_id( $atts );
        $source = AudioSourceResolver::resolve( $post_id );
        if ( [] === $source ) {
            return '';
        }

        $classes = [ 'smp-listen-button' ];
        foreach ( preg_split( '/\s+/', (string) $atts['class'] ) ?: [] as $class_name ) {
            $class_name = preg_replace( '/[^A-Za-z0-9_-]/', '', $class_name ) ?: '';
            if ( '' !== $class_name ) {
                $classes[] = $class_name;
            }
        }
        $label = sanitize_text_field( (string) $atts['label'] );
        $label = '' !== $label ? $label : 'Listen';
        $title = (string) ( $source['title'] ?? get_the_title( $post_id ) );

        return '<button type="button" class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '"'
            . ' data-smp-player-trigger'
            . ' data-smp-post-id="' . (int) $post_id . '"'
            . ' data-smp-audio-src="' . esc_attr( (string) $source['playback_url'] ) . '"'
            . ' data-smp-download-src="' . esc_attr( (string) $source['download_url'] ) . '"'
            . ' data-smp-title="' . esc_attr( $title ) . '"'
            . ' data-smp-url="' . esc_attr( (string) $source['permalink'] ) . '"'
            . ' data-smp-image="' . esc_attr( (string) $source['image'] ) . '"'
            . ' data-smp-duration="' . esc_attr( (string) $source['duration'] ) . '"'
            . ' data-smp-duration-seconds="' . (int) $source['duration_seconds'] . '"'
            . ' aria-controls="smp-podcast-player" aria-pressed="false" aria-label="' . esc_attr( $label . ': ' . $title ) . '">'
            . '<span data-smp-listen-icon aria-hidden="true">▶</span><span data-smp-listen-label>' . esc_html( $label ) . '</span>'
            . '</button>';
    }

    /** @param array<string,mixed> $atts */
    public static function podcast_url( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'social' => '' ], $atts, 'podcast_url' );
        $key = sanitize_key( (string) $atts['social'] );
        if ( '' === $key || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $website = get_field( 'website', 'option' );
        $user_id = self::website_user_id( $website );
        if ( $user_id > 0 ) {
            $user_urls = get_field( 'urls', 'user_' . $user_id );
            if ( is_array( $user_urls ) && ! empty( $user_urls[ $key ] ) ) {
                return esc_url( (string) $user_urls[ $key ] );
            }
        }

        $option_urls = get_field( 'podcast_urls', PodcastSettings::OPTIONS_POST_ID );
        if ( is_array( $option_urls ) && ! empty( $option_urls[ $key ] ) ) {
            return esc_url( (string) $option_urls[ $key ] );
        }

        $saved = PodcastSettings::podcast_urls();
        return ! empty( $saved[ $key ] ) ? esc_url( $saved[ $key ] ) : '';
    }

    /** @param array<string,mixed> $atts */
    public static function episode_fields( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'name' => '', 'post_id' => 0 ], $atts, 'episode_fields' );
        $field = sanitize_key( (string) $atts['name'] );
        $post_id = self::post_id( $atts );
        if ( '' === $field || $post_id < 1 || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $value = get_field( $field, $post_id );
        if ( self::empty_field( $value ) && str_contains( $field, '_' ) ) {
            [ $group_key, $sub_key ] = explode( '_', $field, 2 );
            $group = get_field( $group_key, $post_id );
            $value = is_array( $group ) && array_key_exists( $sub_key, $group ) ? $group[ $sub_key ] : null;
        }

        return self::field_output( $value );
    }

    /** @param array<string,mixed> $atts */
    public static function article_guests( array $atts = [] ): string {
        $post_id = self::post_id( $atts );
        if ( $post_id < 1 || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $rows = get_field( 'profiles', $post_id );
        $ids = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $value = is_array( $row ) && array_key_exists( 'profile', $row ) ? $row['profile'] : $row;
            $id = self::object_id( $value );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return self::relationship_links( $ids, 'shortcode_article_guests' );
    }

    /** @param array<string,mixed> $atts */
    public static function podcast_hosts( array $atts = [] ): string {
        $post_id = self::post_id( $atts );
        if ( $post_id < 1 || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $ids = array_map( [ self::class, 'object_id' ], (array) get_field( 'hosts', $post_id ) );
        return self::relationship_links( $ids, 'shortcode_podcast_hosts' );
    }

    /** @param array<string,mixed> $atts */
    public static function episode_hosts( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'must_have_thumbnail' => false, 'post_id' => 0 ], $atts, 'display_single_episode_hosts' );
        $post_id = self::post_id( $atts );
        if ( $post_id < 1 || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $must_have_thumbnail = filter_var( $atts['must_have_thumbnail'], FILTER_VALIDATE_BOOLEAN );
        $ids = [];
        foreach ( (array) get_field( 'hosts', $post_id ) as $host ) {
            $id = self::object_id( $host );
            if ( $id > 0 && ( ! $must_have_thumbnail || has_post_thumbnail( $id ) ) ) {
                $ids[] = $id;
            }
        }
        $ids = array_values( array_unique( $ids ) );
        if ( [] === $ids ) {
            return '';
        }

        $items = '';
        $template_id = absint( apply_filters( 'smp_podcast_host_template_id', 20001, $post_id ) );
        $frontend = class_exists( '\\Elementor\\Plugin' ) ? \Elementor\Plugin::instance()->frontend : null;
        $original_post = $GLOBALS['post'] ?? null;

        foreach ( $ids as $host_id ) {
            $host = get_post( $host_id );
            if ( ! $host ) {
                continue;
            }

            if ( $frontend && $template_id > 0 && method_exists( $frontend, 'get_builder_content_for_display' ) ) {
                $GLOBALS['post'] = $host;
                setup_postdata( $host );
                $rendered = (string) $frontend->get_builder_content_for_display( $template_id, true );
                if ( '' !== trim( $rendered ) ) {
                    $items .= $rendered;
                    continue;
                }
            }

            $items .= self::profile_post_card( $host_id );
        }

        wp_reset_postdata();
        $GLOBALS['post'] = $original_post;
        if ( '' === $items ) {
            return '';
        }

        return self::assets() . '<div class="display_single_episode_hosts"><div class="shortcode">' . $items . '</div></div>';
    }

    /** @param array<string,mixed> $atts */
    public static function guest_grid( array $atts = [] ): string {
        unset( $atts );
        $users = get_users(
            [
                'role__in' => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'verified_profile_manager', 'customer' ],
                'orderby' => 'display_name',
                'order' => 'ASC',
                'number' => -1,
            ]
        );
        if ( ! $users ) {
            return '';
        }

        $items = '';
        foreach ( $users as $user ) {
            $items .= '<article class="user-card smp-podcast-person-card">'
                . get_avatar( $user->ID, 160, '', $user->display_name )
                . '<h3>' . esc_html( $user->display_name ) . '</h3>'
                . '<a class="view-member-button" href="' . esc_url( get_author_posts_url( $user->ID ) ) . '">View Member</a>'
                . '</article>';
        }

        return self::assets() . '<div class="user-grid smp-podcast-user-grid">' . $items . '</div>';
    }

    /** @param array<string,mixed> $atts */
    public static function guest_profiles( array $atts = [] ): string {
        $post_id = self::post_id( $atts );
        if ( $post_id < 1 || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $rows = get_field( 'guests', $post_id );
        if ( ! is_array( $rows ) ) {
            $rows = self::legacy_repeater_rows( $post_id, 'guests', 'guest' );
        }
        $cards = '';
        foreach ( $rows as $row ) {
            $user_id = self::object_id( is_array( $row ) ? ( $row['guest'] ?? 0 ) : $row );
            if ( $user_id > 0 ) {
                $cards .= self::user_card( $user_id );
            }
        }

        return '' !== $cards
            ? self::assets() . '<div class="guest-profile-wrapper smp-podcast-profile-grid">' . $cards . '</div>'
            : '<p>No guest information available.</p>';
    }

    /** @param array<string,mixed> $atts */
    public static function host_profiles( array $atts = [] ): string {
        $post_id = self::post_id( $atts );
        if ( $post_id < 1 || ! function_exists( 'get_field' ) ) {
            return '';
        }

        $cards = '';
        foreach ( (array) get_field( 'hosts', $post_id ) as $host ) {
            $host_id = self::object_id( $host );
            if ( $host_id > 0 ) {
                $cards .= self::profile_post_card( $host_id );
            }
        }

        return '' !== $cards
            ? self::assets() . '<div class="host-profile-wrapper smp-podcast-profile-grid">' . $cards . '</div>'
            : '<p>No host information available.</p>';
    }

    /** @param array<string,mixed> $atts */
    public static function podcast_host( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'id' => 'title' ], $atts, 'podcast_host' );
        $request = strtolower( trim( (string) $atts['id'] ) );
        $profile_id = PodcastSettings::default_host_id();
        if ( $profile_id < 1 || ! get_post( $profile_id ) ) {
            return '';
        }

        if ( 'title' === $request ) {
            return esc_html( get_the_title( $profile_id ) );
        }
        if ( 'biography' === $request && function_exists( 'get_field' ) ) {
            $bio = get_field( 'biography', $profile_id ) ?: get_field( 'bio', $profile_id );
            return is_scalar( $bio ) ? wp_kses_post( (string) $bio ) : '';
        }
        if ( str_starts_with( $request, 'url_' ) && function_exists( 'get_field' ) ) {
            $key = sanitize_key( substr( $request, 4 ) );
            $urls = get_field( 'url', $profile_id );
            return $key && is_array( $urls ) && ! empty( $urls[ $key ] ) ? esc_url( (string) $urls[ $key ] ) : '';
        }

        return '';
    }

    /** @param array<string,mixed> $atts */
    private static function post_id( array $atts ): int {
        $post_id = absint( $atts['post_id'] ?? 0 );
        return $post_id > 0 ? $post_id : (int) get_the_ID();
    }

    private static function website_user_id( mixed $website ): int {
        if ( is_array( $website ) ) {
            $website = $website['user'] ?? $website['primary_user'] ?? 0;
        }
        return self::object_id( $website );
    }

    private static function object_id( mixed $value ): int {
        if ( is_object( $value ) ) {
            return absint( $value->ID ?? $value->id ?? 0 );
        }
        if ( is_array( $value ) ) {
            return absint( $value['ID'] ?? $value['id'] ?? $value['value'] ?? 0 );
        }
        return is_numeric( $value ) ? absint( $value ) : 0;
    }

    /** @return array<int,array<string,mixed>> */
    private static function legacy_repeater_rows( int $post_id, string $field, string $sub_field ): array {
        $count = absint( get_post_meta( $post_id, $field, true ) );
        $rows = [];
        for ( $index = 0; $index < $count; $index++ ) {
            $value = get_post_meta( $post_id, $field . '_' . $index . '_' . $sub_field, true );
            if ( self::object_id( $value ) > 0 ) {
                $rows[] = [ $sub_field => $value ];
            }
        }
        return $rows;
    }

    private static function empty_field( mixed $value ): bool {
        return null === $value || false === $value || '' === $value || [] === $value;
    }

    private static function field_output( mixed $value ): string {
        if ( self::empty_field( $value ) ) {
            return '';
        }
        if ( is_array( $value ) && isset( $value['url'] ) && is_scalar( $value['url'] ) ) {
            return esc_url( (string) $value['url'] );
        }
        if ( is_array( $value ) ) {
            $items = [];
            foreach ( $value as $item ) {
                if ( is_scalar( $item ) ) {
                    $items[] = wp_strip_all_tags( (string) $item );
                } elseif ( $id = self::object_id( $item ) ) {
                    $items[] = get_the_title( $id );
                }
            }
            return esc_html( implode( ', ', array_filter( $items ) ) );
        }
        return is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
    }

    /** @param array<int,int> $ids */
    private static function relationship_links( array $ids, string $class_name ): string {
        $links = [];
        foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
            $url = get_permalink( $id );
            $title = get_the_title( $id );
            if ( $url && $title ) {
                $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
            }
        }
        return $links ? '<span class="' . esc_attr( $class_name ) . '">' . implode( ', ', $links ) . '</span>' : '';
    }

    private static function user_card( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return '';
        }

        $links = [];
        if ( function_exists( 'get_field' ) ) {
            $socials = get_field( 'socials', 'user_' . $user_id );
            foreach ( [ 'facebook', 'linkedin', 'x', 'youtube', 'instagram', 'soundcloud', 'tiktok' ] as $key ) {
                $value = is_array( $socials ) ? ( $socials[ $key ] ?? '' ) : '';
                $value = $value ?: get_field( $key, 'user_' . $user_id );
                if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                    $links[ $key ] = (string) $value;
                }
            }
        }

        $details = '';
        if ( $user->user_email ) {
            $details .= '<p>Email: <a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a></p>';
        }
        if ( $user->user_url ) {
            $details .= '<p>Website: <a href="' . esc_url( $user->user_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $user->user_url ) . '</a></p>';
        }
        foreach ( $links as $key => $url ) {
            $details .= '<p>' . esc_html( ucfirst( $key ) ) . ': <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></p>';
        }

        return '<article class="guest-profile smp-podcast-person-card">'
            . '<div class="guest-photo">' . get_avatar( $user_id, 160, '', $user->display_name ) . '</div>'
            . '<h3>' . esc_html( $user->display_name ) . '</h3>'
            . '<div class="guest-socials">' . $details . '</div>'
            . '</article>';
    }

    private static function profile_post_card( int $post_id ): string {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }

        $image = get_the_post_thumbnail( $post_id, 'medium', [ 'loading' => 'lazy' ] );
        if ( '' === $image ) {
            $image = get_avatar( (int) $post->post_author, 160, '', get_the_title( $post_id ) );
        }
        return '<article class="host-profile smp-podcast-person-card">'
            . '<a href="' . esc_url( get_permalink( $post_id ) ) . '">' . $image . '<h3>' . esc_html( get_the_title( $post_id ) ) . '</h3></a>'
            . '</article>';
    }

    private static function assets(): string {
        static $rendered = false;
        if ( $rendered ) {
            return '';
        }
        $rendered = true;
        return '<style id="smp-podcast-shortcodes-css">'
            . '.display_single_episode_hosts .shortcode,.smp-podcast-user-grid,.smp-podcast-profile-grid{display:grid;gap:16px;grid-template-columns:repeat(6,minmax(0,1fr));width:100%}'
            . '.smp-podcast-person-card{border:1px solid #d9dee6;border-radius:6px;min-width:0;padding:14px;text-align:center}'
            . '.smp-podcast-person-card img{aspect-ratio:1/1;height:auto;max-width:160px;object-fit:cover;width:100%}'
            . '.smp-podcast-person-card h3{font-size:16px;margin:10px 0 0}.smp-podcast-person-card p{font-size:13px;overflow-wrap:anywhere}'
            . '.smp-podcast-person-card a{overflow-wrap:anywhere}.view-member-button{display:inline-block;margin-top:8px}'
            . '@media(max-width:1024px){.display_single_episode_hosts .shortcode,.smp-podcast-user-grid,.smp-podcast-profile-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}'
            . '@media(max-width:640px){.display_single_episode_hosts .shortcode,.smp-podcast-user-grid,.smp-podcast-profile-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            . '</style>';
    }
}
