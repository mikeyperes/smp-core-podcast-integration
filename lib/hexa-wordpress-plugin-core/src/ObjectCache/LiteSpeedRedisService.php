<?php

namespace Hexa\PluginCore\ObjectCache;

final class LiteSpeedRedisService {
    private string $plugin_file = 'litespeed-cache/litespeed-cache.php';

    /**
     * @return array<string,mixed>
     */
    public function status(): array {
        $this->load_plugin_functions();

        $installed = defined( 'WP_PLUGIN_DIR' ) && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $this->plugin_file );
        $active    = $installed && function_exists( 'is_plugin_active' ) && is_plugin_active( $this->plugin_file );

        $object_enabled = $this->truthy_option( 'litespeed.conf.object' );
        $object_kind    = get_option( 'litespeed.conf.object-kind', '' );
        $driver_redis   = $this->truthy_value( $object_kind );
        $host           = trim( (string) get_option( 'litespeed.conf.object-host', 'localhost' ) );
        $port           = (int) get_option( 'litespeed.conf.object-port', 6379 );
        $db_index       = (int) get_option( 'litespeed.conf.object-db_id', 0 );
        $dropin_path    = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
        $dropin_present = '' !== $dropin_path && file_exists( $dropin_path );
        $dropin_lscwp   = $dropin_present && is_readable( $dropin_path ) && false !== strpos( (string) file_get_contents( $dropin_path, false, null, 0, 5000 ), 'LSCWP_OBJECT_CACHE' );

        $raw = $this->redis_connection_status( $host, $port, $db_index );
        $wp  = $this->wp_object_cache_round_trip();

        $enabled = $active && $object_enabled && $driver_redis && '' !== $host && $dropin_present;
        $active_running = $enabled && ! empty( $raw['connected'] ) && ! empty( $wp['success'] );

        return [
            'installed'        => $installed,
            'plugin_active'    => $active,
            'enabled'          => $enabled,
            'active'           => $active_running,
            'object_enabled'   => $object_enabled,
            'driver_redis'     => $driver_redis,
            'host'             => $host,
            'port'             => $port,
            'db_index'         => $db_index,
            'dropin_present'   => $dropin_present,
            'dropin_litespeed' => $dropin_lscwp,
            'wp_using_ext'     => function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : false,
            'wp_round_trip'    => $wp,
            'redis'            => $raw,
            'message'          => $this->status_message( $installed, $active, $enabled, $active_running, $raw, $wp ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function enable(): array {
        $this->load_plugin_functions();

        $before = $this->status();
        $log    = [
            $this->log_entry( 'info', 'Checking LiteSpeed Cache and Redis object-cache settings.' ),
        ];

        if ( ! $before['installed'] ) {
            return [
                'success' => false,
                'message' => 'LiteSpeed Cache is not installed.',
                'before'  => $before,
                'after'   => $before,
                'log'     => array_merge( $log, [ $this->log_entry( 'error', 'LiteSpeed Cache plugin file is missing.' ) ] ),
            ];
        }

        if ( ! $before['plugin_active'] && function_exists( 'activate_plugin' ) ) {
            $result = activate_plugin( $this->plugin_file );
            if ( is_wp_error( $result ) ) {
                return [
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'before'  => $before,
                    'after'   => $this->status(),
                    'log'     => array_merge( $log, [ $this->log_entry( 'error', $result->get_error_message() ) ] ),
                ];
            }
            $log[] = $this->log_entry( 'success', 'Activated LiteSpeed Cache.' );
        }

        $host = trim( (string) get_option( 'litespeed.conf.object-host', '' ) );
        $port = (int) get_option( 'litespeed.conf.object-port', 0 );

        if ( '' === $host || 'localhost' === strtolower( $host ) || '127.0.0.1' === $host ) {
            $host = '' !== $host ? $host : 'localhost';
        }

        if ( $port <= 0 || 11211 === $port ) {
            $port = 6379;
        }

        update_option( 'litespeed.conf.object', 1 );
        update_option( 'litespeed.conf.object-kind', 1 );
        update_option( 'litespeed.conf.object-host', $host );
        update_option( 'litespeed.conf.object-port', $port );
        update_option( 'litespeed.conf.object-db_id', max( 0, (int) get_option( 'litespeed.conf.object-db_id', 0 ) ) );
        update_option( 'litespeed.conf.object-persistent', 1 );
        update_option( 'litespeed.conf.object-admin', 1 );
        update_option( 'litespeed.conf.object-transients', 1 );

        $log[] = $this->log_entry( 'success', 'Saved LiteSpeed object cache settings for Redis.' );

        if ( class_exists( '\LiteSpeed\Activation' ) && method_exists( '\LiteSpeed\Activation', 'cls' ) ) {
            try {
                \LiteSpeed\Activation::cls()->update_files();
                $log[] = $this->log_entry( 'success', 'Asked LiteSpeed Cache to refresh its managed cache files.' );
            } catch ( \Throwable $throwable ) {
                $log[] = $this->log_entry( 'warning', 'LiteSpeed file refresh reported: ' . $throwable->getMessage() );
            }
        } else {
            $log[] = $this->log_entry( 'warning', 'LiteSpeed Activation class was not available for file refresh.' );
        }

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        $after   = $this->status();
        $success = ! empty( $after['enabled'] ) && ! empty( $after['active'] );

        return [
            'success' => $success,
            'message' => $success ? 'LiteSpeed Redis object cache is enabled and active.' : (string) $after['message'],
            'before'  => $before,
            'after'   => $after,
            'log'     => $log,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function redis_connection_status( string $host, int $port, int $db_index ): array {
        $result = [
            'extension' => extension_loaded( 'redis' ),
            'connected' => false,
            'version'   => '',
            'memory'    => '',
            'keys'      => null,
            'error'     => '',
        ];

        if ( ! $result['extension'] ) {
            $result['error'] = 'Redis PHP extension is not installed.';
            return $result;
        }

        if ( '' === $host ) {
            $result['error'] = 'Redis host is empty.';
            return $result;
        }

        try {
            $redis = new \Redis();
            if ( ! @$redis->connect( $host, $port > 0 ? $port : 6379, 2.0 ) ) {
                $result['error'] = 'Redis connection failed.';
                return $result;
            }

            $password = (string) get_option( 'litespeed.conf.object-pswd', '' );
            $user     = (string) get_option( 'litespeed.conf.object-user', '' );
            if ( '' !== $password ) {
                '' !== $user ? $redis->auth( [ $user, $password ] ) : $redis->auth( $password );
            }

            if ( $db_index > 0 ) {
                $redis->select( $db_index );
            }

            $pong = $redis->rawCommand( 'PING' );
            if ( ! in_array( $pong, [ 'PONG', '+PONG', true ], true ) ) {
                $result['error'] = 'Redis PING did not return PONG.';
                return $result;
            }

            $info = @$redis->info();
            $result['connected'] = true;
            if ( is_array( $info ) ) {
                $result['version'] = (string) ( $info['redis_version'] ?? '' );
                $result['memory']  = (string) ( $info['used_memory_human'] ?? '' );
            }
            $result['keys'] = @$redis->dbSize();
            @$redis->close();
        } catch ( \Throwable $throwable ) {
            $result['error'] = $throwable->getMessage();
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function wp_object_cache_round_trip(): array {
        if ( ! function_exists( 'wp_cache_set' ) || ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_delete' ) ) {
            return [
                'success' => false,
                'message' => 'WordPress object-cache functions are unavailable.',
            ];
        }

        $key   = 'hpc_litespeed_redis_' . wp_generate_uuid4();
        $value = 'ok-' . microtime( true );

        $set = wp_cache_set( $key, $value, 'hpc_object_cache', 60 );
        $got = wp_cache_get( $key, 'hpc_object_cache' );
        wp_cache_delete( $key, 'hpc_object_cache' );

        $success = false !== $set && $got === $value;

        return [
            'success' => $success,
            'message' => $success ? 'WordPress object-cache set/get/delete succeeded.' : 'WordPress object-cache round trip failed.',
        ];
    }

    private function status_message( bool $installed, bool $active, bool $enabled, bool $active_running, array $raw, array $wp ): string {
        if ( ! $installed ) {
            return 'LiteSpeed Cache is not installed.';
        }
        if ( ! $active ) {
            return 'LiteSpeed Cache is installed but inactive.';
        }
        if ( ! $enabled ) {
            return 'Redis object cache is not fully enabled in LiteSpeed.';
        }
        if ( empty( $raw['connected'] ) ) {
            return (string) ( $raw['error'] ?? 'Redis connection failed.' );
        }
        if ( empty( $wp['success'] ) ) {
            return (string) ( $wp['message'] ?? 'WordPress object-cache test failed.' );
        }

        return $active_running ? 'Redis object cache is enabled and active.' : 'Redis object cache needs attention.';
    }

    private function truthy_option( string $option ): bool {
        return $this->truthy_value( get_option( $option, false ) );
    }

    private function truthy_value( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value === 1;
        }

        return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on', 'redis' ], true );
    }

    private function load_plugin_functions(): void {
        if ( defined( 'ABSPATH' ) && ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function log_entry( string $level, string $message ): array {
        return [
            'time'    => function_exists( 'current_time' ) ? current_time( 'H:i:s' ) : gmdate( 'H:i:s' ),
            'level'   => $level,
            'message' => $message,
            'context' => [],
        ];
    }
}
