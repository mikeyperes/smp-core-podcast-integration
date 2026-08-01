<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Config;
use WP_Query;

final class HomeInteractions implements ModuleInterface {
    public const SCRIPT_HANDLE = 'smp-podcast-home-interactions';
    public const STYLE_HANDLE = 'smp-podcast-home-interactions';
    public const LEGACY_CUSTOM_CODE_ID = 23128;

    public function register(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'pre_get_posts', [ $this, 'exclude_legacy_custom_code' ], 5 );
        add_filter( 'script_loader_tag', [ $this, 'protect_runtime_script' ], 10, 3 );
    }

    public function enqueue_assets(): void {
        if ( ! $this->enabled() ) {
            return;
        }

        $url = plugin_dir_url( SMP_PODCAST_PLUGIN_FILE );
        wp_enqueue_style( self::STYLE_HANDLE, $url . 'assets/home-interactions.css', [], Config::VERSION );

        // This script deliberately remains parser-blocking in the footer. It
        // must initialize before Elementor Pro prints body-end Custom Code at
        // priority 21, and data-no-optimize prevents an optimizer from moving
        // the compatibility boundary after that legacy inline snippet.
        wp_enqueue_script( self::SCRIPT_HANDLE, $url . 'assets/home-interactions.js', [], Config::VERSION, true );
    }

    /**
     * Retire the exact Elementor Custom Code document that formerly owned the
     * homepage behavior. The post is excluded only when its identity and
     * unique code markers still match, so an unrelated post that happens to
     * reuse the numeric ID is never hidden.
     */
    public function exclude_legacy_custom_code( WP_Query $query ): void {
        if ( ! $this->enabled() || ! $this->is_elementor_snippet_query( $query ) ) {
            return;
        }

        $legacy_ids = $this->legacy_custom_code_ids();
        if ( [] === $legacy_ids ) {
            return;
        }

        $excluded = array_map( 'absint', (array) $query->get( 'post__not_in' ) );
        $query->set( 'post__not_in', array_values( array_unique( array_merge( $excluded, $legacy_ids ) ) ) );
    }

    public function protect_runtime_script( string $tag, string $handle, string $src = '' ): string {
        unset( $src );
        if ( self::SCRIPT_HANDLE !== $handle || str_contains( $tag, 'data-no-optimize' ) ) {
            return $tag;
        }

        return str_replace( '<script ', '<script data-no-optimize="1" data-cfasync="false" ', $tag );
    }

    private function enabled(): bool {
        if ( is_admin()
            || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            || ( function_exists( 'is_feed' ) && is_feed() )
            || ( function_exists( 'is_embed' ) && is_embed() )
        ) {
            return false;
        }

        $assets_ready = is_readable( SMP_PODCAST_PLUGIN_ROOT . '/assets/home-interactions.js' )
            && is_readable( SMP_PODCAST_PLUGIN_ROOT . '/assets/home-interactions.css' );

        return (bool) apply_filters( 'smp_podcast_home_interactions_enabled', $assets_ready );
    }

    private function is_elementor_snippet_query( WP_Query $query ): bool {
        $post_types = array_filter( array_map( 'strval', (array) $query->get( 'post_type' ) ) );
        return in_array( 'elementor_snippet', $post_types, true );
    }

    /** @return int[] */
    private function legacy_custom_code_ids(): array {
        $candidate_ids = (array) apply_filters(
            'smp_podcast_home_interactions_legacy_custom_code_ids',
            [ self::LEGACY_CUSTOM_CODE_ID ]
        );
        $matched = [];

        foreach ( array_values( array_unique( array_map( 'absint', $candidate_ids ) ) ) as $post_id ) {
            if ( $post_id > 0 && $this->is_legacy_custom_code( $post_id ) ) {
                $matched[] = $post_id;
            }
        }

        return $matched;
    }

    private function is_legacy_custom_code( int $post_id ): bool {
        $post = get_post( $post_id );
        if ( ! $post
            || 'elementor_snippet' !== (string) ( $post->post_type ?? '' )
            || 'publish' !== (string) ( $post->post_status ?? '' )
            || 'Podcast Home Interactions' !== trim( (string) ( $post->post_title ?? '' ) )
        ) {
            return false;
        }

        $code = (string) get_post_meta( $post_id, '_elementor_code', true );
        $matches = str_contains( $code, '[data-elementor-id="23095"]' )
            && str_contains( $code, '.mpp-topic-chip' )
            && str_contains( $code, 'MutationObserver' );

        return (bool) apply_filters(
            'smp_podcast_is_legacy_home_interactions_custom_code',
            $matches,
            $post_id,
            $post,
            $code
        );
    }
}
