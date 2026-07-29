<?php

namespace smp_core_podcast_functionality;

function get_podcast_settings(): array {
    $settings = \SMP\Podcast\Settings\PodcastSettings::content();
    return [
        'singular' => $settings['singular'],
        'plural' => $settings['plural'],
        'slug' => $settings['post_type'],
        'rewrite_slug' => $settings['rewrite_slug'],
    ];
}

function get_settings_snippets(): array {
    return array_map(
        static fn( \Hexa\PluginCore\SnippetRegistry\SnippetDefinition $definition ): array => $definition->to_array(),
        array_values( \SMP\Podcast\Bootstrap\Registries::snippets()->all() )
    );
}

function apply_default_host_to_post( int $post_id ): bool {
    return ! empty( \SMP\Podcast\Content\DefaultHost::apply( $post_id )['changed'] );
}

function sync_acf_audio_to_powerpress( int $post_id ): bool {
    return ! empty( \SMP\Podcast\Integrations\PowerPressSync::sync_audio( $post_id )['changed'] );
}

function podcast_url_shortcode( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::podcast_url( $atts );
}

function episode_fields_shortcode( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::episode_fields( $atts );
}

function podcast_host_shortcode( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::podcast_host( $atts );
}

function article_guests_links( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::article_guests( $atts );
}

function podcast_hosts_links( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::podcast_hosts( $atts );
}

function display_single_episode_hosts( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::episode_hosts( $atts );
}

function display_users_grid( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::guest_grid( $atts );
}

function display_guest_profile_info( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::guest_profiles( $atts );
}

function display_host_profile_info( array $atts = [] ): string {
    return \SMP\Podcast\Frontend\ShortcodeCallbacks::host_profiles( $atts );
}
