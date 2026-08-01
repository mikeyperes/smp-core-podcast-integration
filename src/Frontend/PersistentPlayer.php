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
                        'loadingVideo' => 'Loading episode video',
                        'switchAudio' => 'Switch to audio',
                        'switchVideo' => 'Switch to video',
                        'videoUnavailable' => 'This episode does not have a video.',
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
        <aside id="smp-podcast-player" class="smp-podcast-player" data-smp-player data-smp-mode="audio" hidden aria-label="Persistent podcast media player">
            <audio data-smp-audio preload="metadata"></audio>
            <div class="smp-podcast-player__inner">
                <div class="smp-podcast-player__identity">
                    <div class="smp-podcast-player__stage" data-smp-stage>
                        <div data-smp-video-shell class="smp-podcast-player__video" hidden>
                            <iframe data-smp-video title="Episode video" allow="autoplay; encrypted-media; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                        </div>
                    </div>
                    <div class="smp-podcast-player__copy">
                        <span class="smp-podcast-player__eyebrow">Now playing <span aria-hidden="true">/</span> <span data-smp-kind>Audio</span></span>
                        <a data-smp-title class="smp-podcast-player__title">Select an episode</a>
                        <div class="smp-podcast-player__modes" data-smp-modes role="group" aria-label="Playback format"<?php echo empty( $settings['show_mode_switch'] ) ? ' hidden data-smp-control-disabled' : ''; ?>>
                            <button type="button" data-smp-mode-button="audio" class="smp-podcast-player__mode is-active" aria-pressed="true">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3a8 8 0 0 0-8 8v7a3 3 0 0 0 3 3h2v-8H6v-2a6 6 0 0 1 12 0v2h-3v8h2a3 3 0 0 0 3-3v-7a8 8 0 0 0-8-8Z"/></svg>
                                <span>Audio</span>
                            </button>
                            <button type="button" data-smp-mode-button="video" class="smp-podcast-player__mode" aria-pressed="false">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 7.2a2.7 2.7 0 0 0-1.9-1.9C17.4 4.8 12 4.8 12 4.8s-5.4 0-7.1.5A2.7 2.7 0 0 0 3 7.2 28 28 0 0 0 2.5 12 28 28 0 0 0 3 16.8a2.7 2.7 0 0 0 1.9 1.9c1.7.5 7.1.5 7.1.5s5.4 0 7.1-.5a2.7 2.7 0 0 0 1.9-1.9 28 28 0 0 0 .5-4.8 28 28 0 0 0-.5-4.8ZM10 15.5v-7l6 3.5-6 3.5Z"/></svg>
                                <span>Video</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="smp-podcast-player__transport">
                    <div class="smp-podcast-player__buttons">
                        <button type="button" data-smp-back class="smp-podcast-player__button smp-podcast-player__skip" aria-label="Skip backward <?php echo (int) $settings['skip_back']; ?> seconds"<?php echo empty( $settings['show_skip'] ) ? ' hidden' : ''; ?>>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7.6 7.4 4 8.2l.8-3.6 1.4 1.5A8.8 8.8 0 1 1 3.2 13h2A6.8 6.8 0 1 0 7.6 7.4Z"/></svg><small><?php echo (int) $settings['skip_back']; ?></small>
                        </button>
                        <button type="button" data-smp-toggle class="smp-podcast-player__button smp-podcast-player__toggle" aria-label="Play episode" aria-pressed="false">
                            <span data-smp-play-icon aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M8 5v14l11-7z"/></svg></span>
                            <span data-smp-pause-icon aria-hidden="true" hidden><svg viewBox="0 0 24 24" focusable="false"><path d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg></span>
                        </button>
                        <button type="button" data-smp-forward class="smp-podcast-player__button smp-podcast-player__skip" aria-label="Skip forward <?php echo (int) $settings['skip_forward']; ?> seconds"<?php echo empty( $settings['show_skip'] ) ? ' hidden' : ''; ?>>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m16.4 7.4 3.6.8-.8-3.6-1.4 1.5A8.8 8.8 0 1 0 20.8 13h-2a6.8 6.8 0 1 1-2.4-5.6Z"/></svg><small><?php echo (int) $settings['skip_forward']; ?></small>
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
                        <button type="button" data-smp-mute class="smp-podcast-player__utility" aria-label="Mute audio" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9v6h4l5 4V5L8 9H4Zm12.5-1.5a6.4 6.4 0 0 1 0 9l-1.4-1.4a4.4 4.4 0 0 0 0-6.2l1.4-1.4Z"/></svg></button>
                        <label class="screen-reader-text" for="smp-podcast-volume">Volume</label>
                        <input id="smp-podcast-volume" data-smp-volume type="range" min="0" max="1" step="0.05" value="1">
                    </div>
                    <a data-smp-download class="smp-podcast-player__utility smp-podcast-player__download" href="#" download aria-label="Download episode"<?php echo empty( $settings['show_download'] ) ? ' hidden' : ''; ?>><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M11 4h2v9l3-3 1.4 1.4L12 16.8l-5.4-5.4L8 10l3 3V4Zm-5 14h12v2H6v-2Z"/></svg></a>
                    <button type="button" data-smp-close class="smp-podcast-player__utility smp-podcast-player__close" aria-label="Close player"<?php echo empty( $settings['show_close'] ) ? ' hidden' : ''; ?>><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6.4 5 5.6 5.6L17.6 5 19 6.4 13.4 12l5.6 5.6-1.4 1.4-5.6-5.6L6.4 19 5 17.6l5.6-5.6L5 6.4 6.4 5Z"/></svg></button>
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
