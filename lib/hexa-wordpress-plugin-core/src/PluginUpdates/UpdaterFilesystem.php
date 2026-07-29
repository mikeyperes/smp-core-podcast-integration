<?php

namespace Hexa\PluginCore\PluginUpdates;

final class UpdaterFilesystem {
    private const IGNORED_PACKAGE_DIRECTORIES = [
        '.git' => true,
        '.svn' => true,
        '.hg'  => true,
        '.bzr' => true,
    ];

    private const IGNORED_PACKAGE_FILES = [
        '.DS_Store' => true,
        'Thumbs.db' => true,
    ];

    public static function delete_directory( string $dir ): void {
        self::delete_path( $dir );
    }

    public static function delete_path( string $path ): bool {
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return true;
        }

        if ( is_link( $path ) || is_file( $path ) ) {
            return @unlink( $path );
        }

        if ( ! is_dir( $path ) ) {
            return false;
        }

        $scan = @scandir( $path );
        if ( false === $scan ) {
            return false;
        }

        $files = array_diff( $scan, [ '.', '..' ] );

        foreach ( $files as $file ) {
            if ( ! self::delete_path( $path . '/' . $file ) ) {
                return false;
            }
        }

        return @rmdir( $path );
    }

    public static function is_ignored_package_path( string $path, string $root = '' ): bool {
        $relative_path = self::relative_path( $path, $root );

        if ( '' === $relative_path ) {
            return false;
        }

        $segments = array_values( array_filter( explode( '/', $relative_path ), static fn( $segment ) => '' !== $segment ) );
        foreach ( $segments as $segment ) {
            if ( isset( self::IGNORED_PACKAGE_DIRECTORIES[ $segment ] ) ) {
                return true;
            }
        }

        $basename = basename( $relative_path );

        return isset( self::IGNORED_PACKAGE_FILES[ $basename ] );
    }

    /**
     * @return array<int,string>|\WP_Error
     */
    public static function purge_ignored_package_paths( string $root, bool $strict = false ): array|\WP_Error {
        if ( ! is_dir( $root ) ) {
            return [];
        }

        $removed = [];
        $failed  = [];

        self::purge_ignored_from_directory( self::normalize_path( $root ), self::normalize_path( $root ), $removed, $failed );

        if ( $strict && $failed ) {
            return new \WP_Error(
                'hexa_plugin_core_package_metadata_locked',
                'Could not remove package metadata before update: ' . implode( ', ', array_slice( $failed, 0, 8 ) )
            );
        }

        return $removed;
    }

    public static function copy_directory_clean( string $source, string $destination ): true|\WP_Error {
        $source      = untrailingslashit( $source );
        $destination = untrailingslashit( $destination );

        if ( ! is_dir( $source ) ) {
            return new \WP_Error( 'hexa_plugin_core_copy_source_missing', 'Source folder does not exist: ' . $source );
        }

        $purged = self::purge_ignored_package_paths( $destination, true );
        if ( is_wp_error( $purged ) ) {
            return $purged;
        }

        $copied = self::copy_entry_clean( $source, $destination, $source );

        if ( is_wp_error( $copied ) ) {
            return $copied;
        }

        return true;
    }

    public static function add_folder_to_zip( \ZipArchive $zip, string $folder, string $base_path ): void {
        self::add_directory_to_zip( $zip, untrailingslashit( $folder ), $base_path, untrailingslashit( $folder ) );
    }

    /**
     * @param array<int,string> $removed
     * @param array<int,string> $failed
     */
    private static function purge_ignored_from_directory( string $dir, string $root, array &$removed, array &$failed ): void {
        $scan = @scandir( $dir );
        if ( false === $scan ) {
            return;
        }

        $files = array_diff( $scan, [ '.', '..' ] );

        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;

            if ( self::is_ignored_package_path( $path, $root ) ) {
                if ( self::delete_path( $path ) ) {
                    $removed[] = $path;
                } else {
                    $failed[] = $path;
                }

                continue;
            }

            if ( is_dir( $path ) && ! is_link( $path ) ) {
                self::purge_ignored_from_directory( $path, $root, $removed, $failed );
            }
        }
    }

    private static function add_directory_to_zip( \ZipArchive $zip, string $dir, string $base_path, string $root ): void {
        $scan = @scandir( $dir );
        if ( false === $scan ) {
            return;
        }

        $files = array_diff( $scan, [ '.', '..' ] );
        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;

            if ( self::is_ignored_package_path( $path, $root ) ) {
                continue;
            }

            if ( is_dir( $path ) && ! is_link( $path ) ) {
                self::add_directory_to_zip( $zip, $path, $base_path, $root );
                continue;
            }

            if ( ! is_file( $path ) ) {
                continue;
            }

            $relative_path = $base_path . '/' . substr( self::normalize_path( $path ), strlen( self::normalize_path( $root ) ) + 1 );
            $zip->addFile( $path, $relative_path );
        }
    }

    private static function copy_entry_clean( string $source, string $destination, string $source_root ): true|\WP_Error {
        if ( self::is_ignored_package_path( $source, $source_root ) ) {
            return true;
        }

        if ( is_link( $source ) ) {
            return true;
        }

        if ( is_dir( $source ) ) {
            if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
                return new \WP_Error( 'hexa_plugin_core_copy_directory_failed', 'Could not create folder: ' . $destination );
            }

            $scan = @scandir( $source );
            if ( false === $scan ) {
                return new \WP_Error( 'hexa_plugin_core_copy_scan_failed', 'Could not scan folder: ' . $source );
            }

            $files = array_diff( $scan, [ '.', '..' ] );
            foreach ( $files as $file ) {
                $result = self::copy_entry_clean( $source . '/' . $file, $destination . '/' . $file, $source_root );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            return true;
        }

        if ( ! is_file( $source ) ) {
            return true;
        }

        $parent = dirname( $destination );
        if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
            return new \WP_Error( 'hexa_plugin_core_copy_parent_failed', 'Could not create folder: ' . $parent );
        }

        if ( ! @copy( $source, $destination ) ) {
            return new \WP_Error( 'hexa_plugin_core_copy_file_failed', 'Could not copy file: ' . $source );
        }

        @chmod( $destination, fileperms( $source ) & 0777 );

        return true;
    }

    private static function relative_path( string $path, string $root = '' ): string {
        $path = self::normalize_path( $path );
        $root = self::normalize_path( $root );

        if ( '' !== $root ) {
            if ( $path === $root ) {
                return '';
            }

            if ( str_starts_with( $path, $root . '/' ) ) {
                return substr( $path, strlen( $root ) + 1 );
            }
        }

        return ltrim( $path, '/' );
    }

    private static function normalize_path( string $path ): string {
        $path = str_replace( '\\', '/', $path );

        return rtrim( $path, '/' );
    }
}
