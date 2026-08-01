<?php

namespace SMP\Podcast\Admin;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\WpAdminAjax\AjaxActionRegistry;
use Hexa\PluginCore\WpAdminAjax\AjaxRequest;
use SMP\Podcast\Config;
use SMP\Podcast\Settings\PlaybackSettings;

final class PlaybackSettingsController implements ModuleInterface {
    public function register(): void {
        ( new AjaxActionRegistry(
            [
                'capability' => Config::CAPABILITY,
                'nonce_action' => Config::NONCE_ACTION,
                'nonce_field' => 'nonce',
            ]
        ) )->register(
            [
                'smp_podcast_save_playback_settings' => [ 'callback' => [ $this, 'save' ] ],
            ]
        );
    }

    /** @return array<string,mixed> */
    public function save( AjaxRequest $request ): array {
        $before = PlaybackSettings::get();
        $settings = PlaybackSettings::save(
            [
                'enabled' => $request->bool( 'enabled', false, 'post' ),
                'ajax_navigation' => $request->bool( 'ajax_navigation', false, 'post' ),
                'content_selector' => $request->text( 'content_selector', '', 'post' ),
                'excluded_paths' => $request->raw( 'excluded_paths', '', 'post' ),
                'timeout_ms' => $request->int( 'timeout_ms', 10000, 'post' ),
                'transition_ms' => $request->int( 'transition_ms', 180, 'post' ),
                'skip_back' => $request->int( 'skip_back', 15, 'post' ),
                'skip_forward' => $request->int( 'skip_forward', 30, 'post' ),
                'show_cover' => $request->bool( 'show_cover', false, 'post' ),
                'show_skip' => $request->bool( 'show_skip', false, 'post' ),
                'show_rate' => $request->bool( 'show_rate', false, 'post' ),
                'show_volume' => $request->bool( 'show_volume', false, 'post' ),
                'show_download' => $request->bool( 'show_download', false, 'post' ),
                'show_close' => $request->bool( 'show_close', false, 'post' ),
                'media_session' => $request->bool( 'media_session', false, 'post' ),
                'remember_preferences' => $request->bool( 'remember_preferences', false, 'post' ),
            ]
        );
        $changed = $before !== $settings;
        if ( $changed ) {
            do_action( 'litespeed_purge_all' );
        }

        return [
            'changed' => $changed,
            'settings' => $settings,
            'cache_purge_requested' => $changed,
            'message' => $changed ? 'Playback and AJAX settings saved.' : 'Playback and AJAX settings are unchanged.',
        ];
    }
}
