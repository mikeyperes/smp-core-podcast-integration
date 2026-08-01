<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Config;
use SMP\Podcast\Settings\PlaybackSettings;

final class PersistentPlayer implements ModuleInterface {
    private bool $rendered = false;

    public function register(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ $this, 'render' ], 90 );
        add_filter( 'script_loader_tag', [ $this, 'protect_runtime_script' ], 10, 3 );
    }

    public function enqueue_assets(): void {
        if ( ! $this->enabled() ) {
            return;
        }

        $url = plugin_dir_url( SMP_PODCAST_PLUGIN_FILE );
        wp_enqueue_style( 'smp-podcast-player', $url . 'assets/frontend-player.css', [], Config::VERSION );
        wp_enqueue_script( 'smp-podcast-player', $url . 'assets/frontend-player.js', [], Config::VERSION, true );
        wp_script_add_data( 'smp-podcast-player', 'strategy', 'defer' );
        wp_localize_script(
            'smp-podcast-player',
            'smpPodcastPlayerConfig',
            array_merge(
                PlaybackSettings::public_config(),
                [
                    'homeUrl' => home_url( '/' ),
                    'siteName' => get_bloginfo( 'name' ),
                    'assetUrl' => $url,
                    'rootFallbacks' => [
                        '[data-elementor-type="wp-page"]',
                        '[data-elementor-type="wp-post"]',
                        '[data-elementor-type="single-post"]',
                        '[data-elementor-type="single"]',
                        '[data-elementor-type="archive"]',
                        '.elementor-location-single',
                        '.elementor-location-archive',
                        'main.site-main',
                        'main#content',
                    ],
                    'strings' => [
                        'play' => 'Play episode',
                        'pause' => 'Pause episode',
                        'loading' => 'Loading episode',
                        'navigationLoading' => 'Loading page',
                        'navigationFailed' => 'Opening page normally',
                        'playerClosed' => 'Player closed',
                    ],
                ]
            )
        );
    }

    public function render(): void {
        if ( $this->rendered || ! $this->enabled() ) {
            return;
        }
        $this->rendered = true;
        $settings = PlaybackSettings::get();
        ?>
        <aside id="smp-podcast-player" class="smp-podcast-player" data-smp-player hidden aria-label="Persistent podcast player">
            <audio data-smp-audio preload="metadata"></audio>
            <div class="smp-podcast-player__inner">
                <div class="smp-podcast-player__identity">
                    <img data-smp-cover class="smp-podcast-player__cover" alt="" hidden<?php echo empty( $settings['show_cover'] ) ? ' data-smp-control-disabled' : ''; ?>>
                    <div class="smp-podcast-player__copy">
                        <span class="smp-podcast-player__eyebrow">Now playing</span>
                        <a data-smp-title class="smp-podcast-player__title">Select an episode</a>
                    </div>
                </div>
                <div class="smp-podcast-player__transport">
                    <div class="smp-podcast-player__buttons">
                        <button type="button" data-smp-back class="smp-podcast-player__button smp-podcast-player__skip" aria-label="Skip backward <?php echo (int) $settings['skip_back']; ?> seconds"<?php echo empty( $settings['show_skip'] ) ? ' hidden' : ''; ?>>
                            <span aria-hidden="true">↺</span><small><?php echo (int) $settings['skip_back']; ?></small>
                        </button>
                        <button type="button" data-smp-toggle class="smp-podcast-player__button smp-podcast-player__toggle" aria-label="Play episode" aria-pressed="false">
                            <span data-smp-play-icon aria-hidden="true">▶</span>
                            <span data-smp-pause-icon aria-hidden="true" hidden>Ⅱ</span>
                        </button>
                        <button type="button" data-smp-forward class="smp-podcast-player__button smp-podcast-player__skip" aria-label="Skip forward <?php echo (int) $settings['skip_forward']; ?> seconds"<?php echo empty( $settings['show_skip'] ) ? ' hidden' : ''; ?>>
                            <span aria-hidden="true">↻</span><small><?php echo (int) $settings['skip_forward']; ?></small>
                        </button>
                    </div>
                    <div class="smp-podcast-player__timeline">
                        <span data-smp-elapsed>0:00</span>
                        <label class="screen-reader-text" for="smp-podcast-seek">Episode position</label>
                        <input id="smp-podcast-seek" data-smp-seek type="range" min="0" max="1" step="0.1" value="0" aria-valuetext="0 minutes 0 seconds">
                        <span data-smp-duration>0:00</span>
                    </div>
                </div>
                <div class="smp-podcast-player__utilities">
                    <label class="smp-podcast-player__rate"<?php echo empty( $settings['show_rate'] ) ? ' hidden' : ''; ?>>
                        <span class="screen-reader-text">Playback speed</span>
                        <select data-smp-rate aria-label="Playback speed">
                            <option value="0.75">0.75×</option>
                            <option value="1" selected>1×</option>
                            <option value="1.25">1.25×</option>
                            <option value="1.5">1.5×</option>
                            <option value="1.75">1.75×</option>
                            <option value="2">2×</option>
                        </select>
                    </label>
                    <div class="smp-podcast-player__volume"<?php echo empty( $settings['show_volume'] ) ? ' hidden' : ''; ?>>
                        <button type="button" data-smp-mute class="smp-podcast-player__utility" aria-label="Mute audio" aria-pressed="false"><span aria-hidden="true">◕</span></button>
                        <label class="screen-reader-text" for="smp-podcast-volume">Volume</label>
                        <input id="smp-podcast-volume" data-smp-volume type="range" min="0" max="1" step="0.05" value="1">
                    </div>
                    <a data-smp-download class="smp-podcast-player__utility" href="#" download aria-label="Download episode"<?php echo empty( $settings['show_download'] ) ? ' hidden' : ''; ?>><span aria-hidden="true">⇩</span></a>
                    <button type="button" data-smp-close class="smp-podcast-player__utility smp-podcast-player__close" aria-label="Close player"<?php echo empty( $settings['show_close'] ) ? ' hidden' : ''; ?>><span aria-hidden="true">×</span></button>
                </div>
            </div>
            <span data-smp-status class="screen-reader-text" aria-live="polite"></span>
        </aside>
        <?php
    }

    public function protect_runtime_script( string $tag, string $handle, string $src = '' ): string {
        unset( $src );
        if ( 'smp-podcast-player' !== $handle || str_contains( $tag, 'data-no-optimize' ) ) {
            return $tag;
        }
        return str_replace( '<script ', '<script data-no-optimize="1" data-cfasync="false" ', $tag );
    }

    private function enabled(): bool {
        if ( is_admin() || ( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_embed' ) && is_embed() ) ) {
            return false;
        }
        $settings = PlaybackSettings::get();
        return (bool) apply_filters( 'smp_podcast_persistent_player_enabled', (bool) $settings['enabled'] );
    }
}
