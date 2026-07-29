<?php

namespace SMP\Podcast\Support;

final class Autoloader {
    public static function register( string $base_dir ): void {
        static $registered = false;

        if ( $registered ) {
            return;
        }

        $base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;
        spl_autoload_register(
            static function ( string $class_name ) use ( $base_dir ): void {
                $prefix = 'SMP\\Podcast\\';
                if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
                    return;
                }

                $relative = substr( $class_name, strlen( $prefix ) );
                $path = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
                if ( is_readable( $path ) ) {
                    require_once $path;
                }
            }
        );

        $registered = true;
    }
}
