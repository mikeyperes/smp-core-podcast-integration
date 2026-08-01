<?php

namespace SMP\Podcast\Migration;

use RuntimeException;
use SMP\Podcast\Content\ContentKind;
use SMP\Podcast\Settings\PodcastSettings;
use Throwable;

/**
 * One-time, operator-invoked migration for an externally reviewed MPP corpus.
 *
 * This class is intentionally outside src/ and is never booted by the plugin.
 * Counts are supplementary review evidence only: an exact, checksummed ID seed
 * is the immutable identity authority for planning, apply, and rollback.
 */
final class EpisodeContentKindMigration {
    public const SCHEMA_VERSION = 3;
    public const SEED_FORMAT = 'mpp-content-kind-id-seed-v1';
    public const EXPECTED_COUNTS = [
        'publish' => 158,
        'draft' => 6,
        'private' => 1,
    ];
    public const REQUIRED_ZERO_STATUSES = [ 'pending', 'future' ];
    public const EXPECTED_TOTAL = 165;
    public const APPLY_CONFIRMATION = 'BACKFILL_MPP_EPISODES_165';
    public const ROLLBACK_CONFIRMATION = 'ROLLBACK_MPP_EPISODES_165';

    /**
     * @param array<string,mixed> $reviewed_seed
     * @return array<string,mixed>
     */
    public static function build_manifest( array $reviewed_seed ): array {
        $seed = self::validate_seed( $reviewed_seed );
        $ids = $seed['ids'];
        self::assert_live_corpus_matches( $ids );

        $posts = [];
        foreach ( $ids as $post_id ) {
            $rows = self::content_kind_rows( $post_id );
            if ( count( $rows ) > 1 ) {
                throw new RuntimeException( 'Duplicate content-kind metadata rows must be resolved before planning post ' . $post_id . '.' );
            }
            if ( $rows && ( ! is_string( $rows[0] ) || ContentKind::EPISODE !== $rows[0] ) ) {
                throw new RuntimeException( 'Refusing to overwrite a non-episode explicit content kind on post ' . $post_id . '.' );
            }

            $encoded_rows = array_map( 'base64_encode', $rows );
            $evidence = self::review_evidence( $post_id );
            $posts[] = [
                'id' => $post_id,
                'title' => $evidence['title'],
                'permalink' => $evidence['permalink'],
                'status' => $evidence['status'],
                'legacy_marker_keys' => $evidence['legacy_marker_keys'],
                'prior_exists' => [] !== $rows,
                'prior_rows_b64' => $encoded_rows,
                'prior_rows_sha256' => self::rows_checksum( $encoded_rows ),
            ];
        }

        $fallback_state = self::legacy_fallback_state();
        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'site_url' => self::site_url(),
            'seed_format' => self::SEED_FORMAT,
            'seed_checksum_sha256' => self::payload_checksum( $seed ),
            'corpus_ids' => $ids,
            'corpus_ids_sha256' => $seed['ids_sha256'],
            'meta_key' => ContentKind::META_KEY,
            'target_value' => ContentKind::EPISODE,
            'legacy_fallback_option' => PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION,
            'legacy_fallback_prior_exists' => $fallback_state['exists'],
            'legacy_fallback_prior_enabled' => $fallback_state['enabled'],
            'expected_status_counts' => self::EXPECTED_COUNTS,
            'expected_total' => self::EXPECTED_TOTAL,
            'generated_at_utc' => gmdate( 'c' ),
            'posts' => $posts,
        ];
        $manifest['checksum_sha256'] = self::manifest_checksum( $manifest );

        self::validate_manifest( $manifest, true );
        self::assert_prior_state( $manifest );
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public static function apply(
        array $manifest,
        bool $execute = false,
        string $confirmation = '',
        string $confirmed_site = ''
    ): array {
        self::validate_manifest( $manifest, true );
        self::assert_prior_state( $manifest );

        $report = self::report( $manifest, 'apply', $execute );
        if ( ! $execute ) {
            return $report;
        }

        self::authorize_execution( self::APPLY_CONFIRMATION, $confirmation, $confirmed_site );
        self::transaction(
            $manifest,
            static function ( array $entry ): void {
                $post_id = (int) $entry['id'];
                self::replace_content_kind_rows( $post_id, [ ContentKind::EPISODE ] );
                if ( ! ContentKind::has_explicit_value( $post_id ) || ! ContentKind::is_episode( $post_id ) ) {
                    throw new RuntimeException( 'Failed to set one episode content-kind row on post ' . $post_id . '.' );
                }
            },
            static function (): void {
                self::set_legacy_fallback_enabled( false );
            }
        );
        self::assert_episode_state( $manifest );
        self::assert_legacy_fallback_disabled();

        $report['executed'] = true;
        $report['message'] = 'One explicit episode metadata row was applied to every reviewed ID and legacy-marker fallback was disabled.';
        return $report;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public static function rollback(
        array $manifest,
        bool $execute = false,
        string $confirmation = '',
        string $confirmed_site = ''
    ): array {
        self::validate_manifest( $manifest, true );
        self::assert_episode_state( $manifest );
        self::assert_legacy_fallback_disabled();

        $report = self::report( $manifest, 'rollback', $execute );
        if ( ! $execute ) {
            return $report;
        }

        self::authorize_execution( self::ROLLBACK_CONFIRMATION, $confirmation, $confirmed_site );
        self::transaction(
            $manifest,
            static function ( array $entry ): void {
                $post_id = (int) $entry['id'];
                self::replace_content_kind_rows( $post_id, self::decode_prior_rows( $entry ) );
                self::assert_entry_matches_prior( $entry );
            },
            static function () use ( $manifest ): void {
                self::restore_legacy_fallback_state( $manifest );
            }
        );
        self::assert_prior_state( $manifest );

        $report['executed'] = true;
        $report['message'] = 'The exact pre-backfill metadata-row state and reviewed legacy-fallback setting were restored.';
        return $report;
    }

    /** @param array<string,mixed> $manifest */
    public static function validate_manifest( array $manifest, bool $verify_corpus = true ): void {
        if ( self::SCHEMA_VERSION !== (int) ( $manifest['schema_version'] ?? 0 ) ) {
            throw new RuntimeException( 'Unsupported migration manifest schema.' );
        }
        if ( self::site_url() !== self::normalize_url( (string) ( $manifest['site_url'] ?? '' ) ) ) {
            throw new RuntimeException( 'Manifest site URL does not match the active WordPress site.' );
        }
        if ( self::SEED_FORMAT !== ( $manifest['seed_format'] ?? null ) ) {
            throw new RuntimeException( 'Manifest does not identify the reviewed seed format.' );
        }
        if ( ContentKind::META_KEY !== ( $manifest['meta_key'] ?? null ) || ContentKind::EPISODE !== ( $manifest['target_value'] ?? null ) ) {
            throw new RuntimeException( 'Manifest content-kind contract does not match ScaleMyPodcast.' );
        }
        if ( PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION !== ( $manifest['legacy_fallback_option'] ?? null )
            || ! is_bool( $manifest['legacy_fallback_prior_exists'] ?? null )
            || ! is_bool( $manifest['legacy_fallback_prior_enabled'] ?? null )
            || ( false === $manifest['legacy_fallback_prior_exists'] && false === $manifest['legacy_fallback_prior_enabled'] ) ) {
            throw new RuntimeException( 'Manifest legacy-fallback cutover state is invalid.' );
        }
        if ( self::EXPECTED_TOTAL !== (int) ( $manifest['expected_total'] ?? 0 ) ) {
            throw new RuntimeException( 'Manifest total is not the guarded 165-post corpus.' );
        }

        $expected_counts = self::normalized_counts( $manifest['expected_status_counts'] ?? null );
        if ( self::EXPECTED_COUNTS !== $expected_counts ) {
            throw new RuntimeException( 'Manifest status totals are not 158 published, 6 draft, and 1 private.' );
        }

        $ids = self::validated_ids( $manifest['corpus_ids'] ?? null );
        $ids_checksum = (string) ( $manifest['corpus_ids_sha256'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $ids_checksum ) || ! hash_equals( $ids_checksum, self::seed_ids_checksum( $ids ) ) ) {
            throw new RuntimeException( 'Manifest exact-ID checksum is invalid.' );
        }

        $seed = [
            'format' => self::SEED_FORMAT,
            'site_url' => self::site_url(),
            'ids' => $ids,
            'ids_sha256' => $ids_checksum,
        ];
        $seed_checksum = (string) ( $manifest['seed_checksum_sha256'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $seed_checksum ) || ! hash_equals( $seed_checksum, self::payload_checksum( $seed ) ) ) {
            throw new RuntimeException( 'Manifest reviewed-seed checksum is invalid.' );
        }

        $posts = $manifest['posts'] ?? null;
        if ( ! is_array( $posts ) || self::EXPECTED_TOTAL !== count( $posts ) ) {
            throw new RuntimeException( 'Manifest must contain exactly 165 post entries.' );
        }

        $entry_ids = [];
        $actual_counts = array_fill_keys( array_keys( self::EXPECTED_COUNTS ), 0 );
        foreach ( $posts as $entry ) {
            if ( ! is_array( $entry ) ) {
                throw new RuntimeException( 'Manifest contains a malformed post entry.' );
            }
            $post_id = $entry['id'] ?? null;
            $status = $entry['status'] ?? null;
            if ( ! is_int( $post_id ) || $post_id < 1 || isset( $entry_ids[ $post_id ] ) || ! is_string( $status ) || ! array_key_exists( $status, $actual_counts ) ) {
                throw new RuntimeException( 'Manifest contains a duplicate, invalid, or unsupported post entry.' );
            }
            if ( ! is_string( $entry['title'] ?? null ) || ! is_string( $entry['permalink'] ?? null ) || '' === (string) $entry['permalink'] ) {
                throw new RuntimeException( 'Manifest review evidence is incomplete for post ' . $post_id . '.' );
            }
            self::validate_marker_keys( $entry['legacy_marker_keys'] ?? null, $post_id );
            self::validate_prior_encoding( $entry );
            $entry_ids[ $post_id ] = true;
            $actual_counts[ $status ]++;
        }

        if ( self::EXPECTED_COUNTS !== $actual_counts ) {
            throw new RuntimeException( 'Manifest post entries do not match the guarded status totals.' );
        }
        if ( $ids !== array_map( 'intval', array_keys( $entry_ids ) ) ) {
            throw new RuntimeException( 'Manifest post entries do not exactly match the reviewed ID seed.' );
        }

        $expected_checksum = (string) ( $manifest['checksum_sha256'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_checksum ) || ! hash_equals( $expected_checksum, self::manifest_checksum( $manifest ) ) ) {
            throw new RuntimeException( 'Manifest checksum is invalid or the file was modified.' );
        }

        if ( $verify_corpus ) {
            self::assert_live_corpus_matches( $ids );
            foreach ( $posts as $entry ) {
                self::assert_review_evidence( $entry );
            }
        }
    }

    /** @param array<int,int> $ids */
    public static function seed_ids_checksum( array $ids ): string {
        $ids = self::validated_ids( $ids );
        return hash( 'sha256', implode( "\n", $ids ) . "\n" );
    }

    /**
     * @param array<string,mixed> $seed
     * @return array{format:string,site_url:string,ids:array<int,int>,ids_sha256:string}
     */
    private static function validate_seed( array $seed ): array {
        if ( self::SEED_FORMAT !== ( $seed['format'] ?? null ) ) {
            throw new RuntimeException( 'Planning requires an externally reviewed MPP ID seed.' );
        }
        $site_url = self::normalize_url( (string) ( $seed['site_url'] ?? '' ) );
        if ( self::site_url() !== $site_url ) {
            throw new RuntimeException( 'Reviewed seed site URL does not match the active WordPress site.' );
        }
        $ids = self::validated_ids( $seed['ids'] ?? null );
        $checksum = (string) ( $seed['ids_sha256'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) || ! hash_equals( $checksum, self::seed_ids_checksum( $ids ) ) ) {
            throw new RuntimeException( 'Reviewed seed exact-ID checksum is invalid.' );
        }
        return [
            'format' => self::SEED_FORMAT,
            'site_url' => $site_url,
            'ids' => $ids,
            'ids_sha256' => $checksum,
        ];
    }

    /** @return array<int,int> */
    private static function validated_ids( mixed $ids ): array {
        if ( ! is_array( $ids ) || ! self::is_list( $ids ) || self::EXPECTED_TOTAL !== count( $ids ) ) {
            throw new RuntimeException( 'Reviewed corpus must contain exactly 165 ordered IDs.' );
        }
        foreach ( $ids as $post_id ) {
            if ( ! is_int( $post_id ) || $post_id < 1 ) {
                throw new RuntimeException( 'Reviewed corpus IDs must be positive JSON integers.' );
            }
        }
        if ( count( array_unique( $ids, SORT_NUMERIC ) ) !== count( $ids ) ) {
            throw new RuntimeException( 'Reviewed corpus contains duplicate IDs.' );
        }
        $sorted = $ids;
        sort( $sorted, SORT_NUMERIC );
        if ( $sorted !== $ids ) {
            throw new RuntimeException( 'Reviewed corpus IDs must be sorted in ascending order.' );
        }
        return $ids;
    }

    /** @param array<int,int> $expected_ids */
    private static function assert_live_corpus_matches( array $expected_ids ): void {
        $live_ids = self::live_corpus_ids();
        if ( $live_ids !== $expected_ids ) {
            throw new RuntimeException( 'The live corpus does not exactly match the externally reviewed ID seed.' );
        }
    }

    /** @return array<int,int> */
    private static function live_corpus_ids(): array {
        $active_statuses = self::active_post_statuses();
        $ids = get_posts(
            [
                'post_type' => 'post',
                'post_status' => $active_statuses,
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'suppress_filters' => true,
            ]
        );
        $ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) ) );
        sort( $ids, SORT_NUMERIC );

        $counts = array_fill_keys( $active_statuses, 0 );
        foreach ( $ids as $post_id ) {
            if ( 'post' !== get_post_type( $post_id ) ) {
                throw new RuntimeException( 'Corpus query returned a non-post object: ' . $post_id . '.' );
            }
            $status = (string) get_post_status( $post_id );
            if ( ! array_key_exists( $status, $counts ) ) {
                throw new RuntimeException( 'Corpus query returned unsupported status ' . $status . ' for post ' . $post_id . '.' );
            }
            $counts[ $status ]++;
        }
        $expected_counts = array_fill_keys( $active_statuses, 0 );
        foreach ( self::EXPECTED_COUNTS as $status => $count ) {
            $expected_counts[ $status ] = $count;
        }
        if ( self::EXPECTED_TOTAL !== count( $ids ) || $expected_counts !== $counts ) {
            throw new RuntimeException( 'Corpus guard failed; expected publish=158, draft=6, private=1, pending=0, future=0, every other active status=0, total=165.' );
        }
        return $ids;
    }

    /**
     * Query every registered editorial status that can represent a live work
     * item. Trash, auto-drafts, and revision inheritance are not corpus posts.
     * The explicit pending/future fallback keeps those states guarded even in
     * a reduced bootstrap where the status registry is unavailable.
     *
     * @return array<int,string>
     */
    private static function active_post_statuses(): array {
        $statuses = array_merge( array_keys( self::EXPECTED_COUNTS ), self::REQUIRED_ZERO_STATUSES );
        if ( function_exists( 'get_post_stati' ) ) {
            $registered = get_post_stati( [], 'names' );
            if ( is_array( $registered ) ) {
                $statuses = array_merge( $statuses, array_values( $registered ) );
            }
        }

        $ignored = [ 'trash', 'auto-draft', 'inherit' ];
        $statuses = array_values(
            array_unique(
                array_filter(
                    array_map( 'sanitize_key', $statuses ),
                    static fn( string $status ): bool => '' !== $status && ! in_array( $status, $ignored, true )
                )
            )
        );
        sort( $statuses, SORT_STRING );
        return $statuses;
    }

    /** @return array{title:string,permalink:string,status:string,legacy_marker_keys:array<int,string>} */
    private static function review_evidence( int $post_id ): array {
        $title = function_exists( 'get_post_field' )
            ? (string) get_post_field( 'post_title', $post_id, 'raw' )
            : (string) get_the_title( $post_id );
        $permalink = (string) get_permalink( $post_id );
        if ( '' === $permalink ) {
            throw new RuntimeException( 'Could not resolve review permalink for post ' . $post_id . '.' );
        }
        $marker_keys = [];
        foreach ( PodcastSettings::legacy_marker_keys() as $marker_key ) {
            if ( metadata_exists( 'post', $post_id, $marker_key ) ) {
                $marker_keys[] = $marker_key;
            }
        }
        return [
            'title' => $title,
            'permalink' => $permalink,
            'status' => (string) get_post_status( $post_id ),
            'legacy_marker_keys' => $marker_keys,
        ];
    }

    /** @param array<string,mixed> $entry */
    private static function assert_review_evidence( array $entry ): void {
        $post_id = (int) $entry['id'];
        if ( 'post' !== get_post_type( $post_id ) ) {
            throw new RuntimeException( 'Post identity drifted for reviewed ID ' . $post_id . '.' );
        }
        $live = self::review_evidence( $post_id );
        foreach ( [ 'title', 'permalink', 'status', 'legacy_marker_keys' ] as $key ) {
            if ( $live[ $key ] !== $entry[ $key ] ) {
                throw new RuntimeException( 'Review evidence drifted for post ' . $post_id . ': ' . $key . '.' );
            }
        }
    }

    private static function validate_marker_keys( mixed $keys, int $post_id ): void {
        if ( ! is_array( $keys ) || ! self::is_list( $keys ) ) {
            throw new RuntimeException( 'Legacy-marker review evidence is malformed for post ' . $post_id . '.' );
        }
        $allowed = PodcastSettings::legacy_marker_keys();
        $canonical = [];
        foreach ( $keys as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) || in_array( $key, $canonical, true ) ) {
                throw new RuntimeException( 'Legacy-marker review evidence is invalid for post ' . $post_id . '.' );
            }
            $canonical[] = $key;
        }
        $ordered = array_values( array_filter( $allowed, static fn( string $key ): bool => in_array( $key, $canonical, true ) ) );
        if ( $ordered !== $canonical ) {
            throw new RuntimeException( 'Legacy-marker review evidence is not in canonical order for post ' . $post_id . '.' );
        }
    }

    /** @return array<int,mixed> */
    private static function content_kind_rows( int $post_id ): array {
        $exists = metadata_exists( 'post', $post_id, ContentKind::META_KEY );
        $rows = get_post_meta( $post_id, ContentKind::META_KEY, false );
        if ( ! is_array( $rows ) ) {
            throw new RuntimeException( 'Could not read complete content-kind rows for post ' . $post_id . '.' );
        }
        $rows = array_values( $rows );
        if ( $exists !== ( [] !== $rows ) ) {
            throw new RuntimeException( 'Content-kind existence and row state disagree for post ' . $post_id . '.' );
        }
        return $rows;
    }

    /** @param array<string,mixed> $manifest */
    private static function assert_prior_state( array $manifest ): void {
        $fallback_state = self::legacy_fallback_state();
        if ( $fallback_state['exists'] !== $manifest['legacy_fallback_prior_exists']
            || $fallback_state['enabled'] !== $manifest['legacy_fallback_prior_enabled'] ) {
            throw new RuntimeException( 'Legacy-marker fallback state drifted from the reviewed manifest.' );
        }
        foreach ( $manifest['posts'] as $entry ) {
            self::assert_entry_matches_prior( $entry );
        }
    }

    /** @return array{exists:bool,enabled:bool} */
    private static function legacy_fallback_state(): array {
        $missing = new \stdClass();
        $value = get_option( PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION, $missing );
        return [
            'exists' => $missing !== $value,
            'enabled' => PodcastSettings::legacy_marker_fallback_enabled(),
        ];
    }

    private static function set_legacy_fallback_enabled( bool $enabled ): void {
        $state = self::legacy_fallback_state();
        if ( ! $state['exists'] ) {
            add_option( PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION, $enabled, '', false );
        } else {
            // Omit the autoload argument so an already-reviewed row preserves
            // its exact autoload policy in both apply and rollback directions.
            update_option( PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION, $enabled );
        }

        $state = self::legacy_fallback_state();
        if ( ! $state['exists'] || $state['enabled'] !== $enabled ) {
            throw new RuntimeException( 'Could not persist the legacy-marker fallback cutover.' );
        }
    }

    /** @param array<string,mixed> $manifest */
    private static function restore_legacy_fallback_state( array $manifest ): void {
        if ( true === $manifest['legacy_fallback_prior_exists'] ) {
            self::set_legacy_fallback_enabled( (bool) $manifest['legacy_fallback_prior_enabled'] );
        } else {
            if ( ! delete_option( PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION ) ) {
                throw new RuntimeException( 'Could not delete the post-backfill legacy-marker fallback option.' );
            }
        }

        $state = self::legacy_fallback_state();
        if ( $state['exists'] !== $manifest['legacy_fallback_prior_exists']
            || $state['enabled'] !== $manifest['legacy_fallback_prior_enabled'] ) {
            throw new RuntimeException( 'Could not restore the reviewed legacy-marker fallback state.' );
        }
    }

    private static function assert_legacy_fallback_disabled(): void {
        $state = self::legacy_fallback_state();
        if ( ! $state['exists'] || $state['enabled'] ) {
            throw new RuntimeException( 'Legacy-marker fallback was not disabled after the reviewed backfill.' );
        }
    }

    /** @param array<string,mixed> $entry */
    private static function assert_entry_matches_prior( array $entry ): void {
        $post_id = (int) $entry['id'];
        $rows = self::content_kind_rows( $post_id );
        foreach ( $rows as $row ) {
            if ( ! is_string( $row ) ) {
                throw new RuntimeException( 'Content-kind row became non-string for post ' . $post_id . '.' );
            }
        }
        $encoded_rows = array_map( 'base64_encode', $rows );
        if ( ( [] !== $rows ) !== $entry['prior_exists']
            || $encoded_rows !== $entry['prior_rows_b64']
            || ! hash_equals( (string) $entry['prior_rows_sha256'], self::rows_checksum( $encoded_rows ) ) ) {
            throw new RuntimeException( 'Content-kind row state drifted for post ' . $post_id . '.' );
        }
    }

    /** @param array<string,mixed> $manifest */
    private static function assert_episode_state( array $manifest ): void {
        foreach ( $manifest['posts'] as $entry ) {
            $post_id = (int) $entry['id'];
            if ( [ ContentKind::EPISODE ] !== ContentKind::raw_values( $post_id ) ) {
                throw new RuntimeException( 'Post ' . $post_id . ' is not in the expected one-row episode state.' );
            }
        }
    }

    /** @param array<int,string> $rows */
    private static function replace_content_kind_rows( int $post_id, array $rows ): void {
        delete_post_meta( $post_id, ContentKind::META_KEY );
        foreach ( $rows as $row ) {
            if ( false === add_post_meta( $post_id, ContentKind::META_KEY, $row, false ) ) {
                throw new RuntimeException( 'Could not write the exact content-kind row state for post ' . $post_id . '.' );
            }
        }
        if ( $rows !== self::content_kind_rows( $post_id ) ) {
            throw new RuntimeException( 'Content-kind row verification failed for post ' . $post_id . '.' );
        }
    }

    /** @param array<string,mixed> $manifest */
    private static function transaction( array $manifest, callable $mutation, ?callable $after_mutation = null ): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
            throw new RuntimeException( 'Could not start a database transaction.' );
        }

        try {
            foreach ( $manifest['posts'] as $entry ) {
                $mutation( $entry );
            }
            if ( null !== $after_mutation ) {
                $after_mutation();
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                throw new RuntimeException( 'Could not commit the database transaction.' );
            }
            self::clear_legacy_fallback_option_cache();
        } catch ( Throwable $error ) {
            $rolled_back = false !== $wpdb->query( 'ROLLBACK' );
            self::clear_legacy_fallback_option_cache();
            foreach ( $manifest['posts'] as $entry ) {
                clean_post_cache( (int) $entry['id'] );
            }
            if ( ! $rolled_back ) {
                throw new RuntimeException(
                    'Migration failed and SQL ROLLBACK could not be confirmed; database state is indeterminate. Original error: ' . $error->getMessage(),
                    0,
                    $error
                );
            }
            throw $error;
        }

        foreach ( $manifest['posts'] as $entry ) {
            clean_post_cache( (int) $entry['id'] );
        }
    }

    /** Resynchronize only the option caches touched by the transaction. */
    private static function clear_legacy_fallback_option_cache(): void {
        if ( ! function_exists( 'wp_cache_delete' ) ) {
            return;
        }

        wp_cache_delete( PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    private static function authorize_execution( string $required_token, string $confirmation, string $confirmed_site ): void {
        if ( ! hash_equals( $required_token, $confirmation ) ) {
            throw new RuntimeException( 'The direction-specific execution confirmation token is missing or invalid.' );
        }
        if ( self::site_url() !== self::normalize_url( $confirmed_site ) ) {
            throw new RuntimeException( 'The explicit site confirmation does not match the active WordPress site.' );
        }
    }

    /** @param array<string,mixed> $entry */
    private static function validate_prior_encoding( array $entry ): void {
        if ( ! is_bool( $entry['prior_exists'] ?? null ) ) {
            throw new RuntimeException( 'Manifest prior-existence flags must be booleans.' );
        }
        $encoded_rows = $entry['prior_rows_b64'] ?? null;
        $hash = $entry['prior_rows_sha256'] ?? null;
        if ( ! is_array( $encoded_rows ) || ! self::is_list( $encoded_rows ) || ! is_string( $hash ) ) {
            throw new RuntimeException( 'Manifest prior-row payload is malformed.' );
        }
        if ( count( $encoded_rows ) > 1 ) {
            throw new RuntimeException( 'Migration manifests cannot contain duplicate prior metadata rows.' );
        }
        if ( ( [] !== $encoded_rows ) !== $entry['prior_exists'] ) {
            throw new RuntimeException( 'Manifest prior-row existence flag is inconsistent.' );
        }
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! hash_equals( $hash, self::rows_checksum( $encoded_rows ) ) ) {
            throw new RuntimeException( 'Manifest prior-row checksum is corrupt.' );
        }
        foreach ( $encoded_rows as $encoded ) {
            if ( ! is_string( $encoded ) ) {
                throw new RuntimeException( 'Manifest prior rows must be encoded strings.' );
            }
            $decoded = base64_decode( $encoded, true );
            if ( false === $decoded || ContentKind::EPISODE !== $decoded ) {
                throw new RuntimeException( 'Manifest would overwrite a non-episode explicit content kind.' );
            }
        }
    }

    /** @param array<string,mixed> $entry @return array<int,string> */
    private static function decode_prior_rows( array $entry ): array {
        self::validate_prior_encoding( $entry );
        $rows = [];
        foreach ( $entry['prior_rows_b64'] as $encoded ) {
            $decoded = base64_decode( (string) $encoded, true );
            if ( false === $decoded ) {
                throw new RuntimeException( 'Could not decode a prior metadata row.' );
            }
            $rows[] = $decoded;
        }
        return $rows;
    }

    /** @param array<int,string> $encoded_rows */
    private static function rows_checksum( array $encoded_rows ): string {
        return self::payload_checksum( $encoded_rows );
    }

    /** @param array<string,mixed> $manifest */
    private static function manifest_checksum( array $manifest ): string {
        unset( $manifest['checksum_sha256'] );
        return self::payload_checksum( $manifest );
    }

    private static function payload_checksum( mixed $value ): string {
        $json = function_exists( 'wp_json_encode' )
            ? wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            throw new RuntimeException( 'Could not serialize migration review data.' );
        }
        return hash( 'sha256', $json );
    }

    private static function canonicalize( mixed $value ): mixed {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( ! self::is_list( $value ) ) {
            ksort( $value, SORT_STRING );
        }
        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::canonicalize( $item );
        }
        return $value;
    }

    /** PHP 8.0-compatible equivalent of array_is_list(), added in PHP 8.1. */
    private static function is_list( array $value ): bool {
        $expected = 0;
        foreach ( array_keys( $value ) as $key ) {
            if ( $key !== $expected ) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    /** @return array<string,int> */
    private static function normalized_counts( mixed $counts ): array {
        if ( ! is_array( $counts ) ) {
            return [];
        }
        $normalized = [];
        foreach ( array_keys( self::EXPECTED_COUNTS ) as $status ) {
            $normalized[ $status ] = (int) ( $counts[ $status ] ?? -1 );
        }
        return $normalized;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private static function report( array $manifest, string $operation, bool $execute ): array {
        return [
            'operation' => $operation,
            'executed' => false,
            'dry_run' => ! $execute,
            'site_url' => self::site_url(),
            'meta_key' => ContentKind::META_KEY,
            'target_value' => ContentKind::EPISODE,
            'status_counts' => self::EXPECTED_COUNTS,
            'total' => count( $manifest['posts'] ),
            'corpus_ids_sha256' => (string) $manifest['corpus_ids_sha256'],
            'manifest_checksum' => (string) $manifest['checksum_sha256'],
            'message' => $execute ? 'Execution guards passed; mutation is pending.' : 'Dry run passed; no metadata was changed.',
        ];
    }

    private static function site_url(): string {
        return self::normalize_url( (string) home_url( '/' ) );
    }

    private static function normalize_url( string $url ): string {
        return rtrim( trim( $url ), '/' );
    }
}
