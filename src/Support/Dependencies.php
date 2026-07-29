<?php

namespace SMP\Podcast\Support;

final class Dependencies {
    /** @return array<string,array<string,mixed>> */
    public static function all(): array {
        return [
            'acf' => [
                'label' => 'Advanced Custom Fields Pro',
                'active' => function_exists( 'acf_add_local_field_group' ) || class_exists( 'ACF' ),
                'required' => true,
                'purpose' => 'Podcast fields and settings.',
            ],
            'powerpress' => [
                'label' => 'PowerPress',
                'active' => function_exists( 'powerpress_get_enclosure_data' ) || defined( 'POWERPRESS_VERSION' ),
                'required' => false,
                'purpose' => 'Two-way audio and enclosure synchronization.',
            ],
            'hws-base-tools' => [
                'label' => 'HWS Base Tools',
                'active' => defined( 'HWS_BASE_TOOLS_VERSION' ) || function_exists( 'hws_base_tools\\hws_get_site_type' ),
                'required' => false,
                'purpose' => 'Shared site policy and plugin inventory.',
            ],
            'verified-profiles' => [
                'label' => 'SMP Verified Profiles',
                'active' => post_type_exists( 'profile' ) || function_exists( 'smp_verified_profiles\\get_verified_profile_settings' ),
                'required' => false,
                'purpose' => 'Podcast host and guest profiles.',
            ],
        ];
    }

    public static function acf_ready(): bool {
        return ! empty( self::all()['acf']['active'] );
    }

    public static function powerpress_ready(): bool {
        return ! empty( self::all()['powerpress']['active'] );
    }

    public static function render_notices(): void {
        foreach ( self::all() as $dependency ) {
            if ( empty( $dependency['required'] ) || ! empty( $dependency['active'] ) ) {
                continue;
            }

            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> requires %s for %s The dashboard remains available so the dependency can be diagnosed.</p></div>',
                esc_html( \SMP\Podcast\Config::NAME ),
                esc_html( (string) $dependency['label'] ),
                esc_html( strtolower( (string) $dependency['purpose'] ) )
            );
        }
    }
}
