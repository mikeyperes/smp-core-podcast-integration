<?php

namespace SMP\Podcast\Content;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Acf\EpisodeFieldGroup;
use SMP\Podcast\Settings\PodcastSettings;

/**
 * Owns the explicit content-kind boundary for mixed post installations.
 *
 * The metadata contract is deliberately small and fail-closed. Legacy posts
 * without the metadata key remain eligible through PodcastSettings' podcast
 * marker fallback; once the key exists, only one of the two canonical values
 * can influence runtime behavior.
 */
final class ContentKind implements ModuleInterface {
    public const META_KEY = '_mpp_content_kind';
    public const EPISODE = 'episode';
    public const ARTICLE = 'article';

    /** @var array<string,array<int,int>|null> */
    private static array $duplicate_ids_cache = [];

    public function register(): void {
        add_action( 'init', [ $this, 'register_meta' ], 20 );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_post', [ $this, 'save_meta_box' ], 10, 3 );
        add_action( 'save_post_episode', [ $this, 'save_meta_box' ], 10, 3 );
        add_action( 'added_post_meta', [ self::class, 'invalidate_duplicate_ids' ], 10, 4 );
        add_action( 'updated_post_meta', [ self::class, 'invalidate_duplicate_ids' ], 10, 4 );
        add_action( 'deleted_post_meta', [ self::class, 'invalidate_duplicate_ids' ], 10, 4 );
        add_filter( 'acf/location/match_rule', [ $this, 'match_episode_field_group_location' ], 20, 4 );
        add_filter( 'acf/pre_update_value', [ $this, 'prevent_article_episode_field_update' ], 20, 4 );
    }

    public function register_meta(): void {
        foreach ( array_unique( [ 'post', PodcastSettings::content_type() ] ) as $post_type ) {
            register_post_meta(
                $post_type,
                self::META_KEY,
                [
                    'type' => 'string',
                    'single' => true,
                    'description' => 'ScaleMyPodcast content kind: episode or article.',
                    'sanitize_callback' => [ self::class, 'sanitize' ],
                    'auth_callback' => [ self::class, 'authorize' ],
                    // Classification has transition side effects (including
                    // first-episode default-host assignment), so the normal
                    // nonce-protected editor control is the sole authoring
                    // path. Exposing this as writable REST meta would bypass
                    // that lifecycle and create a partially classified post.
                    'show_in_rest' => false,
                ]
            );
        }
    }

    public static function sanitize( mixed $value ): string {
        $value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
        return in_array( $value, self::values(), true ) ? $value : '';
    }

    /**
     * Protected post meta is writable only by a user who can edit that post.
     *
     * @param array<int,string> $caps
     */
    public static function authorize(
        bool $allowed,
        string $meta_key,
        int $post_id,
        int $user_id = 0,
        string $cap = '',
        array $caps = []
    ): bool {
        unset( $allowed, $meta_key, $user_id, $cap, $caps );
        return $post_id > 0 && current_user_can( 'edit_post', $post_id );
    }

    /** @return array{0:string,1:string} */
    public static function values(): array {
        return [ self::EPISODE, self::ARTICLE ];
    }

    public static function has_explicit_value( int $post_id ): bool {
        return $post_id > 0 && [] !== self::raw_values( $post_id );
    }

    /**
     * Returns a canonical value, or an empty string for absent/invalid data.
     * Use has_explicit_value() when absent and invalid must be distinguished.
     */
    public static function get( int $post_id ): string {
        $values = self::raw_values( $post_id );
        if ( 1 !== count( $values ) || ! is_scalar( $values[0] ) ) {
            return '';
        }

        return self::sanitize( $values[0] );
    }

    public static function is_episode( int $post_id ): bool {
        return self::EPISODE === self::get( $post_id );
    }

    public static function is_article( int $post_id ): bool {
        return self::ARTICLE === self::get( $post_id );
    }

    /** Explicit article and malformed explicit values both fail closed. */
    public static function blocks_podcast_behavior( int $post_id ): bool {
        return self::has_explicit_value( $post_id ) && ! self::is_episode( $post_id );
    }

    /**
     * Find objects whose protected single-value contract has multiple rows.
     * A null result means integrity could not be established and callers must
     * fail closed. Results are invalidated by WordPress metadata mutations.
     *
     * @return array<int,int>|null
     */
    public static function duplicate_post_ids( string $post_type ): ?array {
        $post_type = sanitize_key( $post_type );
        if ( '' === $post_type ) {
            return null;
        }
        if ( array_key_exists( $post_type, self::$duplicate_ids_cache ) ) {
            return self::$duplicate_ids_cache[ $post_type ];
        }

        global $wpdb;
        if ( ! is_object( $wpdb )
            || ! isset( $wpdb->posts, $wpdb->postmeta )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'get_col' ) ) {
            self::$duplicate_ids_cache[ $post_type ] = null;
            return null;
        }

        $query = $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} AS pm
             INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND p.post_type = %s
             GROUP BY pm.post_id
             HAVING COUNT(*) > 1",
            self::META_KEY,
            $post_type
        );
        if ( ! is_string( $query ) || '' === $query ) {
            self::$duplicate_ids_cache[ $post_type ] = null;
            return null;
        }

        $ids = $wpdb->get_col( $query );
        if ( ! is_array( $ids ) || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ) {
            self::$duplicate_ids_cache[ $post_type ] = null;
            return null;
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        sort( $ids, SORT_NUMERIC );
        self::$duplicate_ids_cache[ $post_type ] = $ids;
        return $ids;
    }

    public static function invalidate_duplicate_ids(
        mixed $meta_id = null,
        int $post_id = 0,
        string $meta_key = '',
        mixed $value = null
    ): void {
        unset( $meta_id, $post_id, $value );
        if ( '' === $meta_key || self::META_KEY === $meta_key ) {
            self::$duplicate_ids_cache = [];
        }
    }

    /** @return array<int,mixed> */
    public static function raw_values( int $post_id ): array {
        if ( $post_id < 1 || ! metadata_exists( 'post', $post_id, self::META_KEY ) ) {
            return [];
        }
        $values = get_post_meta( $post_id, self::META_KEY, false );
        return is_array( $values ) ? array_values( $values ) : [];
    }

    /**
     * Veto the owned episode field group's supported ACF location match unless
     * the object is an explicit episode or a genuinely marked legacy episode.
     * Returning false is ACF's documented field-group suppression contract.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $screen
     * @param array<string,mixed> $field_group
     */
    public function match_episode_field_group_location(
        bool $matches,
        array $rule,
        array $screen,
        array $field_group
    ): bool {
        unset( $rule );
        if ( ! $matches || EpisodeFieldGroup::GROUP_KEY !== ( $field_group['key'] ?? '' ) ) {
            return $matches;
        }

        $post_id = self::screen_post_id( $screen );
        return $post_id > 0 && PodcastSettings::is_podcast_content( $post_id );
    }

    public function add_meta_box(): void {
        foreach ( array_unique( [ 'post', PodcastSettings::content_type() ] ) as $post_type ) {
            add_meta_box(
                'smp-podcast-content-kind',
                'ScaleMyPodcast Content Kind',
                [ $this, 'render_meta_box' ],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box( \WP_Post $post ): void {
        $kind = self::get( (int) $post->ID );
        wp_nonce_field( 'smp_podcast_content_kind', 'smp_podcast_content_kind_nonce' );
        ?>
        <p><label for="smp-podcast-content-kind-value">Choose how this post is treated by podcast templates and integrations.</label></p>
        <select id="smp-podcast-content-kind-value" name="<?php echo esc_attr( self::META_KEY ); ?>" class="widefat">
            <option value="">Select content kind</option>
            <option value="episode"<?php selected( $kind, self::EPISODE ); ?>>Podcast episode</option>
            <option value="article"<?php selected( $kind, self::ARTICLE ); ?>>Editorial article</option>
        </select>
        <p class="description">Save once after selecting. Episode fields appear only for an episode; unclassified new posts receive no podcast behavior.</p>
        <?php
    }

    public function save_meta_box( int $post_id, \WP_Post $post, bool $update ): void {
        unset( $post, $update );
        if ( ! isset( $_POST['smp_podcast_content_kind_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['smp_podcast_content_kind_nonce'] ) ), 'smp_podcast_content_kind' )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_revision( $post_id )
            || wp_is_post_autosave( $post_id )
            || ! current_user_can( 'edit_post', $post_id )
            || ! array_key_exists( self::META_KEY, $_POST ) ) {
            return;
        }

        $kind = self::sanitize( wp_unslash( is_scalar( $_POST[ self::META_KEY ] ) ? (string) $_POST[ self::META_KEY ] : '' ) );
        if ( ! in_array( $kind, self::values(), true ) ) {
            return;
        }

        $previous_kind = self::get( $post_id );
        $previous_rows = self::raw_values( $post_id );
        if ( [ $kind ] === $previous_rows ) {
            return;
        }

        // Normalize any externally-created duplicate rows into the single
        // registered contract before saving the explicit editor choice.
        delete_post_meta( $post_id, self::META_KEY );
        if ( [] !== self::raw_values( $post_id ) ) {
            // The delete did not take effect; preserve the untouched state.
            return;
        }
        if ( ! add_post_meta( $post_id, self::META_KEY, $kind, true ) ) {
            // Do not turn a failed normalization into silent data loss.
            foreach ( $previous_rows as $previous_row ) {
                add_post_meta( $post_id, self::META_KEY, $previous_row, false );
            }
            return;
        }
        if ( [ $kind ] !== self::raw_values( $post_id ) ) {
            // A storage layer violated the registered single-value contract.
            // Best-effort restoration keeps this path fail-closed.
            delete_post_meta( $post_id, self::META_KEY );
            foreach ( $previous_rows as $previous_row ) {
                add_post_meta( $post_id, self::META_KEY, $previous_row, false );
            }
            return;
        }

        /**
         * Fires after an editor has persisted one canonical content kind.
         *
         * @param int    $post_id       Saved post ID.
         * @param string $kind          New canonical kind.
         * @param string $previous_kind Previous canonical kind, or empty for
         *                              absent/invalid/duplicate metadata.
         */
        do_action( 'smp_podcast_content_kind_saved', $post_id, $kind, $previous_kind );
    }

    /**
     * A hidden field group is not a write boundary by itself. Short-circuit
     * ACF's value update for every owned episode field on explicit articles
     * (and malformed explicit values), including programmatic and REST-backed
     * ACF saves.
     *
     * @param mixed $check ACF pre-update short-circuit value; null continues.
     * @param mixed $value Proposed field value.
     * @param mixed $post_id ACF post identifier.
     * @param mixed $field ACF field definition.
     * @return mixed
     */
    public function prevent_article_episode_field_update( mixed $check, mixed $value, mixed $post_id, mixed $field ): mixed {
        unset( $value );
        if ( null !== $check || ! is_numeric( $post_id ) || ! is_array( $field ) ) {
            return $check;
        }

        $post_id = (int) $post_id;
        $field_key = (string) ( $field['key'] ?? '' );
        if ( $post_id < 1 || PodcastSettings::is_podcast_content( $post_id ) || ! isset( self::episode_field_keys()[ $field_key ] ) ) {
            return $check;
        }

        return false;
    }

    /** @return array<string,bool> */
    private static function episode_field_keys(): array {
        static $keys = null;
        if ( is_array( $keys ) ) {
            return $keys;
        }

        $keys = [];
        $collect = static function ( array $fields ) use ( &$collect, &$keys ): void {
            foreach ( $fields as $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }
                $key = (string) ( $field['key'] ?? '' );
                if ( '' !== $key ) {
                    $keys[ $key ] = true;
                }
                if ( is_array( $field['sub_fields'] ?? null ) ) {
                    $collect( $field['sub_fields'] );
                }
            }
        };
        $definition = EpisodeFieldGroup::definition();
        $collect( is_array( $definition['fields'] ?? null ) ? $definition['fields'] : [] );
        return $keys;
    }

    /** @param array<string,mixed> $screen */
    private static function screen_post_id( array $screen ): int {
        foreach ( [ 'post_id', 'post' ] as $key ) {
            $value = $screen[ $key ] ?? null;
            if ( is_object( $value ) && isset( $value->ID ) ) {
                $value = $value->ID;
            }
            if ( is_numeric( $value ) && (int) $value > 0 ) {
                return (int) $value;
            }
        }
        return self::current_post_id();
    }

    /**
     * Resolve the edited/rendered post without trusting a single request path.
     */
    public static function current_post_id(): int {
        if ( function_exists( 'acf_get_form_data' ) ) {
            $acf_post_id = acf_get_form_data( 'post_id' );
            if ( is_numeric( $acf_post_id ) && (int) $acf_post_id > 0 ) {
                return (int) $acf_post_id;
            }
        }

        foreach ( [ 'post_ID', 'post_id', 'post' ] as $key ) {
            $value = $_POST[ $key ] ?? $_GET[ $key ] ?? null;
            if ( is_scalar( $value ) && absint( $value ) > 0 ) {
                return absint( $value );
            }
        }

        $post = $GLOBALS['post'] ?? null;
        if ( is_object( $post ) && isset( $post->ID ) ) {
            return absint( $post->ID );
        }

        return function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0;
    }
}
