<?php

namespace SMP\Podcast\Support;

final class SnippetDefinitions {
    /** @return array<int,array<string,mixed>> */
    public static function all(): array {
        return [
            [
                'id' => 'enable_powerpress_post_functionality',
                'name' => 'PowerPress synchronization',
                'description' => 'Synchronize audio URL, duration, summary, episode number, season, and enclosure metadata.',
                'category' => 'integrations',
                'default_enabled' => false,
                'test_rules' => [
                    [ 'id' => 'option', 'label' => 'Feature option is enabled', 'type' => 'option_enabled', 'required' => true ],
                    [ 'id' => 'module', 'label' => 'Synchronization module is callable', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => method_exists( \SMP\Podcast\Integrations\PowerPressSync::class, 'register' ), 'required' => true ],
                    [ 'id' => 'powerpress', 'label' => 'PowerPress API is available', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => Dependencies::powerpress_ready(), 'required' => true ],
                ],
            ],
            [
                'id' => 'enable_post_functionality',
                'name' => 'Default podcast host',
                'description' => 'Preselect the configured host for new podcast content without overwriting an existing host.',
                'category' => 'content',
                'default_enabled' => false,
                'test_rules' => [
                    [ 'id' => 'option', 'label' => 'Feature option is enabled', 'type' => 'option_enabled', 'required' => true ],
                    [ 'id' => 'module', 'label' => 'Default-host module is callable', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => method_exists( \SMP\Podcast\Content\DefaultHost::class, 'register' ), 'required' => true ],
                ],
            ],
            [
                'id' => 'enable_rss',
                'name' => 'Internal podcast RSS',
                'description' => 'Adds /feed/internal-rss/ for internal integrations. The public PowerPress feed is unaffected.',
                'category' => 'feeds',
                'default_enabled' => false,
                'test_rules' => [
                    [ 'id' => 'option', 'label' => 'Feature option is enabled', 'type' => 'option_enabled', 'required' => true ],
                    [ 'id' => 'module', 'label' => 'Feed module is callable', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => method_exists( \SMP\Podcast\Frontend\InternalFeed::class, 'register' ), 'required' => true ],
                ],
            ],
            [
                'id' => 'register_theme_options',
                'name' => 'Podcast settings fields',
                'description' => 'Registers the canonical Podcast Settings ACF group and option object.',
                'category' => 'custom-fields',
                'default_enabled' => true,
                'test_rules' => [
                    [ 'id' => 'option', 'label' => 'Field structure is enabled', 'type' => 'option_enabled', 'required' => true ],
                    [ 'id' => 'acf', 'label' => 'ACF is available', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => Dependencies::acf_ready(), 'required' => true ],
                ],
            ],
            [
                'id' => 'regsiter_acf_post_podcast',
                'name' => 'Podcast episode fields',
                'description' => 'Registers the existing episode field keys against the configured podcast content type.',
                'category' => 'custom-fields',
                'default_enabled' => true,
                'test_rules' => [
                    [ 'id' => 'option', 'label' => 'Field structure is enabled', 'type' => 'option_enabled', 'required' => true ],
                    [ 'id' => 'acf', 'label' => 'ACF is available', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => Dependencies::acf_ready(), 'required' => true ],
                ],
            ],
            [
                'id' => 'enable_podcast_external_link_icons',
                'name' => 'External-link indicators',
                'description' => 'Adds a small external-link indicator to front-end links that open a new tab.',
                'category' => 'display',
                'default_enabled' => false,
                'test_rules' => [
                    [ 'id' => 'option', 'label' => 'Feature option is enabled', 'type' => 'option_enabled', 'required' => true ],
                    [ 'id' => 'module', 'label' => 'Display module is callable', 'type' => 'callback', 'callback' => static fn( ...$unused ): bool => method_exists( \SMP\Podcast\Frontend\ExternalLinks::class, 'register' ), 'required' => true ],
                ],
            ],
        ];
    }
}
