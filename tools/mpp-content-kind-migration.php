<?php

use SMP\Podcast\Migration\EpisodeContentKindMigration;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
    throw new RuntimeException( 'Run this artifact with WP-CLI eval-file inside the owning WordPress installation.' );
}

require_once __DIR__ . '/EpisodeContentKindMigration.php';

/** @var array<int,string> $args */
$arguments = isset( $args ) && is_array( $args ) ? $args : [];
$operation = array_shift( $arguments ) ?: '';
$options = [];
foreach ( $arguments as $argument ) {
    if ( '--execute' === $argument ) {
        $options['execute'] = true;
        continue;
    }
    if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) {
        $options[ $match[1] ] = $match[2];
    }
}

$manifest_path = (string) ( $options['manifest'] ?? '' );
if ( '' === $manifest_path || '/' !== $manifest_path[0] || 'json' !== strtolower( pathinfo( $manifest_path, PATHINFO_EXTENSION ) ) ) {
    WP_CLI::error( 'Provide an explicit .json path with --manifest=/absolute/path/manifest.json.' );
}
if ( is_link( $manifest_path ) ) {
    WP_CLI::error( 'Manifest path must not be a symbolic link.' );
}

try {
    if ( 'plan' === $operation ) {
        $seed_path = (string) ( $options['seed'] ?? '' );
        if ( '' === $seed_path || '/' !== $seed_path[0] || 'json' !== strtolower( pathinfo( $seed_path, PATHINFO_EXTENSION ) ) ) {
            throw new RuntimeException( 'Planning requires --seed=/absolute/path/reviewed-ids.json.' );
        }
        if ( is_link( $seed_path ) || ! is_file( $seed_path ) || ! is_readable( $seed_path ) ) {
            throw new RuntimeException( 'Reviewed seed must be a readable, regular, non-symbolic-link file.' );
        }
        $seed_permissions = fileperms( $seed_path );
        if ( false === $seed_permissions || 0 !== ( $seed_permissions & 0222 ) ) {
            throw new RuntimeException( 'Reviewed seed must be made read-only before planning (for example chmod 0400).' );
        }
        if ( realpath( $seed_path ) === realpath( $manifest_path ) ) {
            throw new RuntimeException( 'Reviewed seed and generated manifest must use different files.' );
        }
        if ( file_exists( $manifest_path ) && empty( $options['overwrite'] ) ) {
            throw new RuntimeException( 'Manifest already exists; add --overwrite=1 only after reviewing that path.' );
        }
        $parent = realpath( dirname( $manifest_path ) );
        if ( false === $parent || ! is_dir( $parent ) || ! is_writable( $parent ) ) {
            throw new RuntimeException( 'Manifest parent directory must already exist and be writable.' );
        }
        $seed = json_decode( (string) file_get_contents( $seed_path ), true, 512, JSON_THROW_ON_ERROR );
        if ( ! is_array( $seed ) ) {
            throw new RuntimeException( 'Reviewed seed JSON did not decode to an object.' );
        }
        $manifest = EpisodeContentKindMigration::build_manifest( $seed );
        $json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || false === file_put_contents( $manifest_path, $json . "\n", LOCK_EX ) ) {
            throw new RuntimeException( 'Could not write the migration manifest.' );
        }
        chmod( $manifest_path, 0600 );
        WP_CLI::success( 'Guarded manifest written. No WordPress metadata was changed.' );
        return;
    }

    if ( ! in_array( $operation, [ 'apply', 'rollback' ], true ) ) {
        throw new RuntimeException( 'Choose plan, apply, or rollback.' );
    }
    if ( ! is_readable( $manifest_path ) ) {
        throw new RuntimeException( 'Manifest is not readable.' );
    }
    $manifest = json_decode( (string) file_get_contents( $manifest_path ), true, 512, JSON_THROW_ON_ERROR );
    if ( ! is_array( $manifest ) ) {
        throw new RuntimeException( 'Manifest JSON did not decode to an object.' );
    }

    $method = 'apply' === $operation ? 'apply' : 'rollback';
    $report = EpisodeContentKindMigration::$method(
        $manifest,
        ! empty( $options['execute'] ),
        (string) ( $options['confirm'] ?? '' ),
        (string) ( $options['confirm-site'] ?? '' )
    );
    WP_CLI::log( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    WP_CLI::success( ! empty( $report['executed'] ) ? ucfirst( $operation ) . ' completed.' : ucfirst( $operation ) . ' dry run completed; no metadata changed.' );
} catch ( Throwable $error ) {
    WP_CLI::error( $error->getMessage() );
}
