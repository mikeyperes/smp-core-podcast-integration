<?php

namespace SMP\Podcast\Support;

final class BootstrapMigration {
    public static function register( string $folder, string $canonical_file ): void {
        add_action(
            'plugins_loaded',
            static function () use ( $folder, $canonical_file ): void {
                self::migrate( $folder, $canonical_file );
            },
            1
        );
    }

    private static function migrate( string $folder, string $canonical_file ): void {
        $canonical = trim( $folder, '/' ) . '/' . basename( $canonical_file );
        $legacy = trim( $folder, '/' ) . '/initialization.php';
        if ( $canonical === $legacy ) {
            return;
        }

        $active = (array) get_option( 'active_plugins', [] );
        $changed = false;
        foreach ( $active as $index => $plugin ) {
            if ( $legacy === $plugin ) {
                $active[ $index ] = $canonical;
                $changed = true;
            }
        }

        if ( $changed ) {
            update_option( 'active_plugins', array_values( array_unique( $active ) ), false );
        }

        if ( ! is_multisite() ) {
            return;
        }

        $network = (array) get_site_option( 'active_sitewide_plugins', [] );
        if ( isset( $network[ $legacy ] ) ) {
            $network[ $canonical ] = $network[ $legacy ];
            unset( $network[ $legacy ] );
            update_site_option( 'active_sitewide_plugins', $network );
        }
    }
}
