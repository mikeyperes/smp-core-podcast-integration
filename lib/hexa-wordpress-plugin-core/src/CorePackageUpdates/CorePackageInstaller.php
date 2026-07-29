<?php

namespace Hexa\PluginCore\CorePackageUpdates;

use Hexa\PluginCore\PluginUpdates\UpdaterFilesystem;
use Hexa\PluginCore\PluginUpdates\UpdateProgressStore;
use WP_Error;

final class CorePackageInstaller {
    private CorePackageConfig $config;

    private ?UpdateProgressStore $progress;

    public function __construct( CorePackageConfig $config, ?UpdateProgressStore $progress = null ) {
        $this->config = $config;
        $this->progress = $progress;
    }

    public function run(): array|WP_Error {
        $this->reset_progress();
        $this->step( 'Preparing WordPress filesystem access.' );

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'unzip_file' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'copy_dir' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        $this->step( 'Downloading latest Hexa WordPress Plugin Core from ' . $this->config->github_repo() . ' (' . $this->config->github_branch() . ').' );
        $tmp_zip = download_url( $this->config->zip_url(), (int) $this->config->get( 'timeout', 15 ) );
        if ( is_wp_error( $tmp_zip ) ) {
            return $this->fail( $tmp_zip );
        }

        $extract_to = trailingslashit( get_temp_dir() ) . 'hexa-plugin-core-' . wp_generate_uuid4();
        wp_mkdir_p( $extract_to );

        $this->step( 'Unzipping downloaded core package.' );
        $unzipped = unzip_file( $tmp_zip, $extract_to );
        wp_delete_file( $tmp_zip );

        if ( is_wp_error( $unzipped ) ) {
            return $this->fail( $unzipped );
        }

        $this->step( 'Verifying downloaded package structure.' );
        $source = $this->locate_source( $extract_to );
        if ( ! $source ) {
            $this->delete_dir( $extract_to );

            return $this->fail( new WP_Error( 'hexa_core_source_missing', 'Downloaded core package did not contain VERSION and src.' ) );
        }

        $this->step( 'Copying core files into the vendored package folder without VCS metadata.' );
        $result = UpdaterFilesystem::copy_directory_clean( $source, $this->config->core_root() );
        $this->delete_dir( $extract_to );

        if ( is_wp_error( $result ) ) {
            return $this->fail( $result );
        }

        $this->step( 'Clearing cached Git version data.' );
        ( new CorePackageVersionClient( $this->config ) )->clear_cache();

        $new_version = $this->config->current_version();
        $this->step( 'Confirmed vendored core version ' . $new_version . '.', 'done' );
        $this->finish( 'done', 'Updated Hexa WordPress Plugin Core to v' . $new_version . '.' );

        return [
            'message'     => 'Hexa WordPress Plugin Core updated.',
            'new_version' => $new_version,
            'core_root'   => $this->config->core_root(),
        ];
    }

    private function reset_progress(): void {
        if ( $this->progress ) {
            $this->progress->reset();
        }
    }

    private function step( string $message, string $status = 'running' ): void {
        if ( $this->progress ) {
            $this->progress->step( $message, $status );
        }
    }

    private function finish( string $state, string $message ): void {
        if ( $this->progress ) {
            $this->progress->finish( $state, $message );
        }
    }

    private function fail( WP_Error $error ): WP_Error {
        $this->finish( 'error', $error->get_error_message() );

        return $error;
    }

    private function locate_source( string $extract_to ): string|false {
        $entries = glob( trailingslashit( $extract_to ) . '*' );
        if ( ! $entries ) {
            return false;
        }

        foreach ( $entries as $entry ) {
            if (
                is_dir( $entry )
                && is_readable( trailingslashit( $entry ) . $this->config->version_file() )
                && is_dir( trailingslashit( $entry ) . 'src' )
            ) {
                return $entry;
            }
        }

        return false;
    }

    private function delete_dir( string $path ): void {
        global $wp_filesystem;

        if ( $wp_filesystem ) {
            $wp_filesystem->delete( $path, true );
        }
    }
}
