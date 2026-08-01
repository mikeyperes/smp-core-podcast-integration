<?php

declare( strict_types=1 );

namespace Hexa\PluginCore\CoreContracts {
    interface ModuleInterface {
        public function register(): void;
    }
}

namespace {
    const ABSPATH = '/tmp/wordpress/';

    $GLOBALS['migration_posts'] = [];
    /** @var array<string,mixed> simulated option-table rows */
    $GLOBALS['migration_options'] = [];
    /** @var array<string,bool> simulated option-table autoload column */
    $GLOBALS['migration_option_autoload'] = [];
    /** @var array<string,mixed> simulated individual `options` cache entries */
    $GLOBALS['migration_option_cache'] = [];
    /** @var array<string,bool> simulated `notoptions` cache */
    $GLOBALS['migration_notoptions'] = [];
    /** @var array<int,array{key:string,group:string}> */
    $GLOBALS['migration_cache_deletions'] = [];
    $GLOBALS['migration_fail_commit_count'] = 0;
    $GLOBALS['migration_fail_rollback_count'] = 0;
    $GLOBALS['migration_fail_delete_option_count'] = 0;
    /** @var array<int,array<string,array<int,mixed>>> */
    $GLOBALS['migration_meta'] = [];
    $GLOBALS['migration_fail_add_id'] = 0;
    $GLOBALS['migration_checks'] = 0;
    $GLOBALS['migration_failures'] = [];

    final class MigrationTestWpdb {
        /** @var array<int,array<string,array<int,mixed>>> */
        private array $snapshot = [];

        /** @var array<string,mixed> */
        private array $option_snapshot = [];

        /** @var array<string,bool> */
        private array $option_autoload_snapshot = [];

        /** @var array<int,string> */
        public array $queries = [];

        public function query( string $sql ): int|false {
            $this->queries[] = $sql;
            if ( 'START TRANSACTION' === $sql ) {
                $this->snapshot = $GLOBALS['migration_meta'];
                $this->option_snapshot = $GLOBALS['migration_options'];
                $this->option_autoload_snapshot = $GLOBALS['migration_option_autoload'];
            } elseif ( 'ROLLBACK' === $sql ) {
                if ( $GLOBALS['migration_fail_rollback_count'] > 0 ) {
                    $GLOBALS['migration_fail_rollback_count']--;
                    return false;
                }
                $GLOBALS['migration_meta'] = $this->snapshot;
                $GLOBALS['migration_options'] = $this->option_snapshot;
                $GLOBALS['migration_option_autoload'] = $this->option_autoload_snapshot;
                $this->snapshot = [];
                $this->option_snapshot = [];
                $this->option_autoload_snapshot = [];
            } elseif ( 'COMMIT' === $sql ) {
                if ( $GLOBALS['migration_fail_commit_count'] > 0 ) {
                    $GLOBALS['migration_fail_commit_count']--;
                    return false;
                }
                $this->snapshot = [];
                $this->option_snapshot = [];
                $this->option_autoload_snapshot = [];
            }
            return 1;
        }
    }

    $GLOBALS['wpdb'] = new MigrationTestWpdb();

    function migration_check( bool $passed, string $label ): void {
        $GLOBALS['migration_checks']++;
        if ( $passed ) {
            echo "PASS {$label}\n";
            return;
        }
        $GLOBALS['migration_failures'][] = $label;
        echo "FAIL {$label}\n";
    }

    function migration_throws( callable $callback, string $label ): void {
        try {
            $callback();
            migration_check( false, $label );
        } catch ( \RuntimeException ) {
            migration_check( true, $label );
        }
    }

    function migration_throws_containing( callable $callback, string $needle, string $label ): void {
        try {
            $callback();
            migration_check( false, $label );
        } catch ( \RuntimeException $error ) {
            migration_check( str_contains( strtolower( $error->getMessage() ), strtolower( $needle ) ), $label );
        }
    }

    function sanitize_key( string $value ): string {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?: '' );
    }

    function sanitize_text_field( string $value ): string {
        return trim( strip_tags( $value ) );
    }

    function sanitize_title( string $value ): string {
        return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?: '' ), '-' );
    }

    function current_user_can( string $capability, mixed ...$args ): bool {
        unset( $capability, $args );
        return true;
    }

    function get_option( string $key, mixed $default = false ): mixed {
        if ( isset( $GLOBALS['migration_notoptions'][ $key ] ) ) {
            return $default;
        }
        if ( array_key_exists( $key, $GLOBALS['migration_option_cache'] ) ) {
            return $GLOBALS['migration_option_cache'][ $key ];
        }
        if ( array_key_exists( $key, $GLOBALS['migration_options'] ) ) {
            $GLOBALS['migration_option_cache'][ $key ] = $GLOBALS['migration_options'][ $key ];
            return $GLOBALS['migration_options'][ $key ];
        }

        $GLOBALS['migration_notoptions'][ $key ] = true;
        return $default;
    }

    function update_option( string $key, mixed $value, bool|null $autoload = null ): bool {
        $exists = array_key_exists( $key, $GLOBALS['migration_options'] );
        $old_value = get_option( $key, false );
        if ( $old_value === $value ) {
            // Mirrors core: absent and false is an unchanged false, so
            // update_option() does not create the required explicit row.
            return false;
        }
        if ( ! $exists ) {
            return add_option( $key, $value, '', $autoload );
        }

        $GLOBALS['migration_options'][ $key ] = $value;
        if ( null !== $autoload ) {
            $GLOBALS['migration_option_autoload'][ $key ] = $autoload;
        }
        $GLOBALS['migration_option_cache'][ $key ] = $value;
        unset( $GLOBALS['migration_notoptions'][ $key ] );
        return true;
    }

    function add_option( string $key, mixed $value = '', string $deprecated = '', bool|null $autoload = null ): bool {
        unset( $deprecated );
        if ( array_key_exists( $key, $GLOBALS['migration_options'] ) ) {
            return false;
        }
        $GLOBALS['migration_options'][ $key ] = $value;
        $GLOBALS['migration_option_autoload'][ $key ] = $autoload ?? true;
        $GLOBALS['migration_option_cache'][ $key ] = $value;
        unset( $GLOBALS['migration_notoptions'][ $key ] );
        return true;
    }

    function delete_option( string $key ): bool {
        $exists = array_key_exists( $key, $GLOBALS['migration_options'] );
        if ( $GLOBALS['migration_fail_delete_option_count'] > 0 ) {
            $GLOBALS['migration_fail_delete_option_count']--;
            // Core removes the object-cache entry around a failed DB delete;
            // the table row deliberately remains for this failure fixture.
            unset( $GLOBALS['migration_option_cache'][ $key ] );
            $GLOBALS['migration_notoptions'][ $key ] = true;
            return false;
        }
        unset( $GLOBALS['migration_options'][ $key ] );
        unset( $GLOBALS['migration_option_autoload'][ $key ] );
        unset( $GLOBALS['migration_option_cache'][ $key ] );
        $GLOBALS['migration_notoptions'][ $key ] = true;
        return $exists;
    }

    function wp_cache_delete( string $key, string $group = '' ): bool {
        $GLOBALS['migration_cache_deletions'][] = [ 'key' => $key, 'group' => $group ];
        if ( 'options' !== $group ) {
            return false;
        }
        if ( 'notoptions' === $key ) {
            $GLOBALS['migration_notoptions'] = [];
        } elseif ( 'alloptions' !== $key ) {
            unset( $GLOBALS['migration_option_cache'][ $key ] );
        }
        return true;
    }

    function metadata_exists( string $type, int $post_id, string $key ): bool {
        return 'post' === $type && ! empty( $GLOBALS['migration_meta'][ $post_id ][ $key ] );
    }

    function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
        if ( '' === $key ) {
            return $GLOBALS['migration_meta'][ $post_id ] ?? [];
        }
        $rows = array_values( $GLOBALS['migration_meta'][ $post_id ][ $key ] ?? [] );
        return $single ? ( $rows[0] ?? '' ) : $rows;
    }

    function add_post_meta( int $post_id, string $key, mixed $value, bool $unique = false ): int|false {
        if ( $post_id === $GLOBALS['migration_fail_add_id'] ) {
            return false;
        }
        $rows = get_post_meta( $post_id, $key, false );
        if ( $unique && $rows ) {
            return false;
        }
        $rows[] = $value;
        $GLOBALS['migration_meta'][ $post_id ][ $key ] = $rows;
        return count( $rows );
    }

    function update_post_meta( int $post_id, string $key, mixed $value ): int|false {
        if ( $post_id === $GLOBALS['migration_fail_add_id'] ) {
            return false;
        }
        $GLOBALS['migration_meta'][ $post_id ][ $key ] = [ $value ];
        return 1;
    }

    function delete_post_meta( int $post_id, string $key ): bool {
        $existed = metadata_exists( 'post', $post_id, $key );
        unset( $GLOBALS['migration_meta'][ $post_id ][ $key ] );
        if ( [] === ( $GLOBALS['migration_meta'][ $post_id ] ?? [] ) ) {
            unset( $GLOBALS['migration_meta'][ $post_id ] );
        }
        return $existed;
    }

    function clean_post_cache( int $post_id ): void {
        unset( $post_id );
    }

    function get_post_type( int $post_id ): string|false {
        return $GLOBALS['migration_posts'][ $post_id ]['type'] ?? false;
    }

    function get_post_status( int $post_id ): string|false {
        return $GLOBALS['migration_posts'][ $post_id ]['status'] ?? false;
    }

    /** @return array<int,string> */
    function get_post_stati( array $args = [], string $output = 'names', string $operator = 'and' ): array {
        unset( $args, $output, $operator );
        return [ 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'review' ];
    }

    function get_post_field( string $field, int $post_id, string $context = 'display' ): mixed {
        unset( $context );
        return 'post_title' === $field ? ( $GLOBALS['migration_posts'][ $post_id ]['title'] ?? '' ) : '';
    }

    function get_the_title( int $post_id ): string {
        return (string) ( $GLOBALS['migration_posts'][ $post_id ]['title'] ?? '' );
    }

    function get_permalink( int $post_id ): string|false {
        return $GLOBALS['migration_posts'][ $post_id ]['permalink'] ?? false;
    }

    /** @return array<int,int> */
    function get_posts( array $args = [] ): array {
        $statuses = array_map( 'strval', (array) ( $args['post_status'] ?? [] ) );
        $post_type = (string) ( $args['post_type'] ?? 'post' );
        $ids = [];
        foreach ( $GLOBALS['migration_posts'] as $post_id => $post ) {
            if ( $post_type === $post['type'] && in_array( $post['status'], $statuses, true ) ) {
                $ids[] = (int) $post_id;
            }
        }
        sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    function home_url( string $path = '' ): string {
        return 'https://podcast.example.test/' . ltrim( $path, '/' );
    }

    function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
        return json_encode( $value, $flags );
    }

    require dirname( __DIR__ ) . '/src/Content/ContentKind.php';
    require dirname( __DIR__ ) . '/src/Settings/PodcastSettings.php';
    require dirname( __DIR__ ) . '/tools/EpisodeContentKindMigration.php';

    use SMP\Podcast\Content\ContentKind;
    use SMP\Podcast\Migration\EpisodeContentKindMigration;

    for ( $post_id = 1; $post_id <= 165; $post_id++ ) {
        $status = $post_id <= 158 ? 'publish' : ( $post_id <= 164 ? 'draft' : 'private' );
        $GLOBALS['migration_posts'][ $post_id ] = [
            'type' => 'post',
            'status' => $status,
            'title' => 'Reviewed Episode ' . $post_id,
            'permalink' => 'https://podcast.example.test/episode-' . $post_id . '/',
        ];
    }

    $seed_ids = range( 1, 165 );
    $seed = [
        'format' => EpisodeContentKindMigration::SEED_FORMAT,
        'site_url' => 'https://podcast.example.test',
        'ids' => $seed_ids,
        'ids_sha256' => EpisodeContentKindMigration::seed_ids_checksum( $seed_ids ),
    ];

    $fallback_option = SMP\Podcast\Settings\PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION;
    migration_check(
        false === update_option( $fallback_option, false, false )
        && ! array_key_exists( $fallback_option, $GLOBALS['migration_options'] ),
        'core option semantics do not create an absent option whose requested value is false'
    );
    $GLOBALS['migration_option_cache'] = [];
    $GLOBALS['migration_notoptions'] = [];
    $GLOBALS['migration_cache_deletions'] = [];

    migration_throws(
        static fn() => EpisodeContentKindMigration::build_manifest( [] ),
        'planner refuses to infer identity without an externally reviewed seed'
    );
    $bad_seed = $seed;
    $bad_seed['ids_sha256'] = str_repeat( '0', 64 );
    migration_throws(
        static fn() => EpisodeContentKindMigration::build_manifest( $bad_seed ),
        'planner rejects an altered reviewed-ID seed'
    );

    $GLOBALS['migration_meta'][2]['audio'] = [ 'https://media.example.test/two.mp3' ];
    $GLOBALS['migration_meta'][3][ContentKind::META_KEY] = [ ContentKind::EPISODE ];
    $GLOBALS['migration_meta'][4]['profiles'] = [ [ [ 'profile' => 44 ] ] ];
    $baseline_meta = $GLOBALS['migration_meta'];
    $baseline_options = $GLOBALS['migration_options'];

    $manifest = EpisodeContentKindMigration::build_manifest( $seed );
    migration_check( 3 === ( $manifest['schema_version'] ?? 0 ), 'manifest uses the exact-ID, row-safe, fallback-cutover schema' );
    migration_check( $seed_ids === ( $manifest['corpus_ids'] ?? [] ), 'manifest stores every externally reviewed ID exactly' );
    migration_check( $seed['ids_sha256'] === ( $manifest['corpus_ids_sha256'] ?? '' ), 'manifest stores the reviewed exact-ID checksum' );
    migration_check( 165 === count( $manifest['posts'] ?? [] ), 'manifest captures exactly 165 reviewed posts' );
    migration_check(
        [ 'publish' => 158, 'draft' => 6, 'private' => 1 ] === ( $manifest['expected_status_counts'] ?? [] ),
        'manifest records the supplementary 158/6/1 status contract'
    );
    migration_check( 64 === strlen( (string) ( $manifest['checksum_sha256'] ?? '' ) ), 'manifest is checksummed' );
    migration_check(
        'Reviewed Episode 2' === ( $manifest['posts'][1]['title'] ?? '' )
        && 'https://podcast.example.test/episode-2/' === ( $manifest['posts'][1]['permalink'] ?? '' )
        && [ 'audio' ] === ( $manifest['posts'][1]['legacy_marker_keys'] ?? null ),
        'manifest includes title, permalink, status-adjacent marker review evidence'
    );
    migration_check(
        [] === ( $manifest['posts'][3]['legacy_marker_keys'] ?? null ),
        'editorial profiles metadata is excluded from podcast marker evidence'
    );
    migration_check(
        [ base64_encode( ContentKind::EPISODE ) ] === ( $manifest['posts'][2]['prior_rows_b64'] ?? null ),
        'manifest records the complete one-row prior metadata state'
    );
    migration_check(
        SMP\Podcast\Settings\PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION === ( $manifest['legacy_fallback_option'] ?? '' )
        && false === ( $manifest['legacy_fallback_prior_exists'] ?? null )
        && true === ( $manifest['legacy_fallback_prior_enabled'] ?? null ),
        'manifest records the exact absent-but-enabled legacy-fallback state'
    );
    EpisodeContentKindMigration::validate_manifest( $manifest );
    migration_check( true, 'fresh manifest validates against the unchanged exact-ID corpus' );

    $dry_run = EpisodeContentKindMigration::apply( $manifest );
    migration_check( true === $dry_run['dry_run'] && false === $dry_run['executed'], 'apply defaults to a mutation-free dry run' );
    migration_check( $baseline_meta === $GLOBALS['migration_meta'] && $baseline_options === $GLOBALS['migration_options'], 'dry-run apply writes no metadata or options' );

    add_option( $fallback_option, false, '', false );
    migration_throws(
        static fn() => EpisodeContentKindMigration::apply( $manifest ),
        'apply refuses legacy-fallback option drift after manifest review'
    );
    delete_option( $fallback_option );

    migration_throws(
        static fn() => EpisodeContentKindMigration::apply( $manifest, true, 'wrong-token', 'https://podcast.example.test' ),
        'apply refuses an invalid direction-specific confirmation token'
    );
    migration_check( $baseline_meta === $GLOBALS['migration_meta'], 'failed confirmation writes no metadata' );

    $tampered = $manifest;
    $tampered['posts'][0]['status'] = 'draft';
    migration_throws(
        static fn() => EpisodeContentKindMigration::validate_manifest( $tampered ),
        'manifest tampering is rejected before mutation'
    );

    unset( $GLOBALS['migration_posts'][1] );
    $GLOBALS['migration_posts'][166] = [
        'type' => 'post',
        'status' => 'publish',
        'title' => 'Replacement Episode 166',
        'permalink' => 'https://podcast.example.test/episode-166/',
    ];
    migration_throws(
        static fn() => EpisodeContentKindMigration::build_manifest( $seed ),
        'same-count replacement IDs cannot produce a new manifest from the reviewed seed'
    );
    migration_throws(
        static fn() => EpisodeContentKindMigration::validate_manifest( $manifest ),
        'same-count replacement IDs invalidate an existing manifest'
    );
    unset( $GLOBALS['migration_posts'][166] );
    $GLOBALS['migration_posts'][1] = [
        'type' => 'post',
        'status' => 'publish',
        'title' => 'Reviewed Episode 1',
        'permalink' => 'https://podcast.example.test/episode-1/',
    ];

    foreach ( [ 'pending', 'future', 'review' ] as $unexpected_status ) {
        $GLOBALS['migration_posts'][166] = [
            'type' => 'post',
            'status' => $unexpected_status,
            'title' => 'Unexpected ' . ucfirst( $unexpected_status ) . ' Episode',
            'permalink' => 'https://podcast.example.test/unexpected-' . $unexpected_status . '/',
        ];
        migration_throws(
            static fn() => EpisodeContentKindMigration::build_manifest( $seed ),
            'planner rejects an additional active ' . $unexpected_status . ' post outside the reviewed corpus'
        );
        migration_throws(
            static fn() => EpisodeContentKindMigration::validate_manifest( $manifest ),
            'existing manifest is invalidated by an additional active ' . $unexpected_status . ' post'
        );
        unset( $GLOBALS['migration_posts'][166] );
    }

    $GLOBALS['migration_posts'][1]['title'] = 'Drifted title';
    migration_throws(
        static fn() => EpisodeContentKindMigration::validate_manifest( $manifest ),
        'title evidence drift invalidates the reviewed manifest'
    );
    $GLOBALS['migration_posts'][1]['title'] = 'Reviewed Episode 1';
    $GLOBALS['migration_meta'][5]['audio_url'] = [ 'https://media.example.test/new.mp3' ];
    migration_throws(
        static fn() => EpisodeContentKindMigration::validate_manifest( $manifest ),
        'legacy-marker evidence drift invalidates the reviewed manifest'
    );
    unset( $GLOBALS['migration_meta'][5] );

    $GLOBALS['migration_meta'][17][ContentKind::META_KEY] = [ ContentKind::EPISODE, ContentKind::EPISODE ];
    migration_throws(
        static fn() => EpisodeContentKindMigration::build_manifest( $seed ),
        'planner refuses duplicate identical protected-meta rows'
    );
    $GLOBALS['migration_meta'][17][ContentKind::META_KEY] = [ ContentKind::EPISODE, ContentKind::ARTICLE ];
    migration_throws(
        static fn() => EpisodeContentKindMigration::build_manifest( $seed ),
        'planner refuses duplicate conflicting protected-meta rows'
    );
    $GLOBALS['migration_meta'][17][ContentKind::META_KEY] = [ ContentKind::ARTICLE ];
    migration_throws(
        static fn() => EpisodeContentKindMigration::build_manifest( $seed ),
        'planner refuses to overwrite a pre-existing explicit article'
    );
    unset( $GLOBALS['migration_meta'][17] );

    $GLOBALS['migration_meta'][3][ContentKind::META_KEY][] = ContentKind::EPISODE;
    migration_throws(
        static fn() => EpisodeContentKindMigration::apply( $manifest ),
        'prior-state verification rejects duplicate rows created after planning'
    );
    $GLOBALS['migration_meta'] = $baseline_meta;

    $GLOBALS['migration_fail_add_id'] = 83;
    migration_throws(
        static fn() => EpisodeContentKindMigration::apply(
            $manifest,
            true,
            EpisodeContentKindMigration::APPLY_CONFIRMATION,
            'https://podcast.example.test'
        ),
        'a mid-backfill row-write failure aborts the transaction'
    );
    migration_check( $baseline_meta === $GLOBALS['migration_meta'], 'transaction rollback restores the complete pre-backfill state' );
    migration_check( $baseline_options === $GLOBALS['migration_options'], 'failed transaction restores the reviewed legacy-fallback state' );
    migration_check( in_array( 'ROLLBACK', $GLOBALS['wpdb']->queries, true ), 'failed transaction explicitly issues ROLLBACK' );

    $GLOBALS['migration_fail_rollback_count'] = 1;
    migration_throws_containing(
        static fn() => EpisodeContentKindMigration::apply(
            $manifest,
            true,
            EpisodeContentKindMigration::APPLY_CONFIRMATION,
            'https://podcast.example.test'
        ),
        'indeterminate',
        'failed SQL ROLLBACK is surfaced as an indeterminate database state'
    );
    $GLOBALS['migration_meta'] = $baseline_meta;
    $GLOBALS['migration_options'] = $baseline_options;
    $GLOBALS['migration_option_cache'] = [];
    $GLOBALS['migration_notoptions'] = [];

    $GLOBALS['migration_fail_add_id'] = 0;
    $cache_deletions_before = count( $GLOBALS['migration_cache_deletions'] );
    $GLOBALS['migration_fail_commit_count'] = 1;
    migration_throws(
        static fn() => EpisodeContentKindMigration::apply(
            $manifest,
            true,
            EpisodeContentKindMigration::APPLY_CONFIRMATION,
            'https://podcast.example.test'
        ),
        'apply aborts when COMMIT fails after the fallback cutover mutation'
    );
    $rollback_cache_keys = array_column( array_slice( $GLOBALS['migration_cache_deletions'], $cache_deletions_before ), 'key' );
    migration_check(
        $baseline_meta === $GLOBALS['migration_meta']
        && $baseline_options === $GLOBALS['migration_options']
        && SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(),
        'failed apply COMMIT restores database state and does not leak a stale disabled fallback value'
    );
    migration_check(
        [] === array_diff( [ $fallback_option, 'alloptions', 'notoptions' ], $rollback_cache_keys ),
        'failed apply COMMIT evicts the specific, alloptions, and notoptions caches'
    );

    $applied = EpisodeContentKindMigration::apply(
        $manifest,
        true,
        EpisodeContentKindMigration::APPLY_CONFIRMATION,
        'https://podcast.example.test'
    );
    migration_check( true === $applied['executed'], 'guarded apply executes with the exact token and site' );
    $episode_rows = array_filter(
        $seed_ids,
        static fn( int $post_id ): bool => [ ContentKind::EPISODE ] === get_post_meta( $post_id, ContentKind::META_KEY, false )
    );
    migration_check( 165 === count( $episode_rows ), 'guarded apply sets exactly one episode row on every reviewed ID' );
    migration_check(
        [ 'https://media.example.test/two.mp3' ] === get_post_meta( 2, 'audio', false ),
        'guarded apply leaves unrelated legacy metadata unchanged'
    );
    migration_check(
        array_key_exists( SMP\Podcast\Settings\PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION, $GLOBALS['migration_options'] )
        && ! SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(),
        'guarded apply disables legacy-marker classification after the exact-ID backfill'
    );

    $rollback_dry_run = EpisodeContentKindMigration::rollback( $manifest );
    migration_check( true === $rollback_dry_run['dry_run'] && 165 === count( $episode_rows ), 'rollback also defaults to a mutation-free dry run' );
    migration_throws(
        static fn() => EpisodeContentKindMigration::rollback(
            $manifest,
            true,
            EpisodeContentKindMigration::ROLLBACK_CONFIRMATION,
            'https://wrong.example.test'
        ),
        'rollback refuses a mismatched site confirmation'
    );
    migration_check( [ ContentKind::EPISODE ] === get_post_meta( 1, ContentKind::META_KEY, false ), 'failed rollback confirmation leaves episode metadata intact' );

    $cache_deletions_before = count( $GLOBALS['migration_cache_deletions'] );
    $GLOBALS['migration_fail_commit_count'] = 1;
    migration_throws(
        static fn() => EpisodeContentKindMigration::rollback(
            $manifest,
            true,
            EpisodeContentKindMigration::ROLLBACK_CONFIRMATION,
            'https://podcast.example.test'
        ),
        'rollback aborts when COMMIT fails after restoring the prior fallback state'
    );
    $rollback_cache_keys = array_column( array_slice( $GLOBALS['migration_cache_deletions'], $cache_deletions_before ), 'key' );
    $episode_rows_after_failed_commit = array_filter(
        $seed_ids,
        static fn( int $post_id ): bool => [ ContentKind::EPISODE ] === get_post_meta( $post_id, ContentKind::META_KEY, false )
    );
    migration_check(
        165 === count( $episode_rows_after_failed_commit )
        && array_key_exists( $fallback_option, $GLOBALS['migration_options'] )
        && ! SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(),
        'failed rollback COMMIT restores episode rows and does not leak a stale enabled fallback value'
    );
    migration_check(
        [] === array_diff( [ $fallback_option, 'alloptions', 'notoptions' ], $rollback_cache_keys ),
        'failed rollback COMMIT evicts the specific, alloptions, and notoptions caches'
    );

    $GLOBALS['migration_fail_delete_option_count'] = 1;
    migration_throws(
        static fn() => EpisodeContentKindMigration::rollback(
            $manifest,
            true,
            EpisodeContentKindMigration::ROLLBACK_CONFIRMATION,
            'https://podcast.example.test'
        ),
        'rollback aborts when core cannot delete the explicit fallback option row'
    );
    $episode_rows_after_failed_delete = array_filter(
        $seed_ids,
        static fn( int $post_id ): bool => [ ContentKind::EPISODE ] === get_post_meta( $post_id, ContentKind::META_KEY, false )
    );
    migration_check(
        165 === count( $episode_rows_after_failed_delete )
        && array_key_exists( $fallback_option, $GLOBALS['migration_options'] )
        && ! SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(),
        'failed option deletion rolls back prior rows and reloads the still-disabled database value instead of trusting stale cache state'
    );

    $GLOBALS['migration_meta'][3][ContentKind::META_KEY][] = ContentKind::EPISODE;
    migration_throws(
        static fn() => EpisodeContentKindMigration::rollback( $manifest ),
        'rollback refuses a duplicate episode row created after apply'
    );
    $GLOBALS['migration_meta'][3][ContentKind::META_KEY] = [ ContentKind::EPISODE ];

    $rolled_back = EpisodeContentKindMigration::rollback(
        $manifest,
        true,
        EpisodeContentKindMigration::ROLLBACK_CONFIRMATION,
        'https://podcast.example.test'
    );
    migration_check( true === $rolled_back['executed'], 'guarded rollback executes with the exact token and site' );
    migration_check( $baseline_meta == $GLOBALS['migration_meta'], 'rollback restores the exact absent/single-row prior state and multiplicity' );
    migration_check(
        $baseline_options === $GLOBALS['migration_options']
        && SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(),
        'rollback restores the exact absent legacy-fallback option state and its enabled default'
    );

    $GLOBALS['migration_posts'][1]['status'] = 'draft';
    migration_throws(
        static fn() => EpisodeContentKindMigration::validate_manifest( $manifest ),
        'status drift invalidates the manifest and corpus guard'
    );
    $GLOBALS['migration_posts'][1]['status'] = 'publish';

    add_option( $fallback_option, true, '', true );
    $autoload_manifest = EpisodeContentKindMigration::build_manifest( $seed );
    EpisodeContentKindMigration::apply(
        $autoload_manifest,
        true,
        EpisodeContentKindMigration::APPLY_CONFIRMATION,
        'https://podcast.example.test'
    );
    migration_check(
        true === ( $GLOBALS['migration_option_autoload'][ $fallback_option ] ?? null )
        && ! SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(),
        'apply preserves a reviewed pre-existing option autoload policy while disabling fallback'
    );
    EpisodeContentKindMigration::rollback(
        $autoload_manifest,
        true,
        EpisodeContentKindMigration::ROLLBACK_CONFIRMATION,
        'https://podcast.example.test'
    );
    migration_check(
        true === ( $GLOBALS['migration_options'][ $fallback_option ] ?? null )
        && true === ( $GLOBALS['migration_option_autoload'][ $fallback_option ] ?? null ),
        'rollback restores a pre-existing enabled option without changing its autoload policy'
    );
    delete_option( $fallback_option );

    echo "\n{$GLOBALS['migration_checks']} migration checks, " . count( $GLOBALS['migration_failures'] ) . " failed.\n";
    if ( $GLOBALS['migration_failures'] ) {
        foreach ( $GLOBALS['migration_failures'] as $failure ) {
            fwrite( STDERR, "- {$failure}\n" );
        }
        exit( 1 );
    }
}
