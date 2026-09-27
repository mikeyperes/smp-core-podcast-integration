<?php

namespace Hexa\PluginCore\DirectorySearch;

use Hexa\PluginCore\PublicComponents\ProfileStore;

/**
 * Holds the normalized directory search profiles registered during a request.
 *
 * Hosts register profiles from their own boot code, or late through the
 * `hexa_plugin_core_directory_search_register` action, which fires once the
 * first time a profile is resolved.
 */
final class DirectorySearchRegistry {
    public const REGISTER_ACTION = 'hexa_plugin_core_directory_search_register';

    private static ?ProfileStore $store = null;

    /** @param array<string,mixed> $config */
    public static function register( string $id, array $config ): void {
        self::store()->register( $id, $config );
    }

    /** @return array<string,mixed>|null */
    public static function get( string $id ): ?array {
        return self::store()->get( $id );
    }

    /** @return string[] */
    public static function ids(): array {
        return array_keys( self::store()->all() );
    }

    /** Test helper: forget every registered profile. */
    public static function reset(): void {
        self::store()->reset();
    }

    private static function store(): ProfileStore {
        return self::$store ??= new ProfileStore( self::REGISTER_ACTION, [ DirectorySearchProfile::class, 'normalize' ] );
    }
}
