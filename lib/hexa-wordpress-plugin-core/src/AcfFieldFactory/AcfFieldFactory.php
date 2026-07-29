<?php

namespace Hexa\PluginCore\AcfFieldFactory;

final class AcfFieldFactory {
    public static function multiPostObject( array $args ): array {
        $post_types = $args["post_types"] ?? [ "post", "page" ];
        if ( ! is_array( $post_types ) || empty( $post_types ) ) {
            $post_types = [ "post", "page" ];
        }

        $field = [
            "key" => (string) ( $args["key"] ?? "" ),
            "label" => (string) ( $args["label"] ?? "" ),
            "name" => (string) ( $args["name"] ?? "" ),
            "type" => "post_object",
            "instructions" => (string) ( $args["instructions"] ?? "" ),
            "post_type" => array_values( array_filter( array_map( "sanitize_key", $post_types ) ) ),
            "return_format" => "id",
            "multiple" => 1,
            "allow_null" => 1,
            "ui" => 1,
        ];

        foreach ( [ "required", "wrapper", "conditional_logic" ] as $optional ) {
            if ( array_key_exists( $optional, $args ) ) {
                $field[ $optional ] = $args[ $optional ];
            }
        }

        return $field;
    }
}
