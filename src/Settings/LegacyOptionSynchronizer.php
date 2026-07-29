<?php

namespace SMP\Podcast\Settings;

use Hexa\PluginCore\CoreContracts\ModuleInterface;

final class LegacyOptionSynchronizer implements ModuleInterface {
    public function register(): void {
        add_action( 'update_option_regsiter_acf_post_podcast', [ $this, 'sync_episode_fields' ], 10, 2 );
        add_action( 'add_option_regsiter_acf_post_podcast', [ $this, 'sync_episode_fields' ], 10, 2 );
        add_action( 'update_option_register_theme_options', [ $this, 'sync_option_fields' ], 10, 2 );
        add_action( 'add_option_register_theme_options', [ $this, 'sync_option_fields' ], 10, 2 );
    }

    public function sync_episode_fields( mixed $before, mixed $after ): void {
        unset( $before );
        $enabled = (bool) $after;
        $settings = get_option( 'smp_podcast_content_types', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $id = 'post' === PodcastSettings::content_type() ? 'podcast-post-integration' : 'podcast-episode';
        $current = is_array( $settings[ $id ] ?? null ) ? $settings[ $id ] : [];
        $current['enabled'] = $enabled ? 1 : 0;
        $groups = is_array( $current['field_groups'] ?? null ) ? $current['field_groups'] : [];
        $groups['episode-fields'] = $enabled ? 1 : 0;
        $current['field_groups'] = $groups;
        $settings[ $id ] = $current;
        update_option( 'smp_podcast_content_types', $settings, false );
    }

    public function sync_option_fields( mixed $before, mixed $after ): void {
        unset( $before );
        $settings = get_option( 'smp_podcast_acf_groups', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $settings['podcast-settings'] = (bool) $after ? 1 : 0;
        update_option( 'smp_podcast_acf_groups', $settings, false );
    }
}
