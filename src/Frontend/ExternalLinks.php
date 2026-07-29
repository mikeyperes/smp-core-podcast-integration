<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;

final class ExternalLinks implements ModuleInterface {
    public function register(): void {
        if ( ! (bool) get_option( 'enable_podcast_external_link_icons', false ) ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue(): void {
        wp_register_style( 'smp-podcast-external-links', false, [], \SMP\Podcast\Config::VERSION );
        wp_enqueue_style( 'smp-podcast-external-links' );
        wp_add_inline_style(
            'smp-podcast-external-links',
            '.elementor-location-footer :is(.column-1,.column-2,.column-3) a[target="_blank"]::after{background:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27currentColor%27 stroke-width=%272%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27%3E%3Cpath d=%27M15 3h6v6%27/%3E%3Cpath d=%27M10 14 21 3%27/%3E%3Cpath d=%27M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6%27/%3E%3C/svg%3E") center/contain no-repeat;content:"";display:inline-block;height:.8em;margin-left:.4em;width:.8em}'
        );
    }
}
