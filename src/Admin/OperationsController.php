<?php

namespace SMP\Podcast\Admin;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\WpAdminAjax\AjaxActionRegistry;
use Hexa\PluginCore\WpAdminAjax\AjaxFailure;
use Hexa\PluginCore\WpAdminAjax\AjaxRequest;
use SMP\Podcast\Config;
use SMP\Podcast\Content\DefaultHost;
use SMP\Podcast\Integrations\PowerPressSync;
use SMP\Podcast\Settings\PodcastSettings;
use SMP\Podcast\Support\RewriteLifecycle;

final class OperationsController implements ModuleInterface {
    private const OPERATIONS = [ 'default-host', 'audio', 'migrate-urls' ];

    public function register(): void {
        ( new AjaxActionRegistry(
            [
                'capability' => Config::CAPABILITY,
                'nonce_action' => Config::NONCE_ACTION,
                'nonce_field' => 'nonce',
            ]
        ) )->register(
            [
                'smp_podcast_operation_index' => [ 'callback' => [ $this, 'index' ] ],
                'smp_podcast_operation_item' => [ 'callback' => [ $this, 'item' ] ],
                'smp_podcast_save_content_model' => [ 'callback' => [ $this, 'save_content_model' ] ],
            ]
        );
    }

    /** @return array<string,mixed> */
    public function index( AjaxRequest $request ): array {
        $operation = $this->operation( $request );
        $ids = get_posts(
            PodcastSettings::scoped_query_args(
                [
                'post_type' => PodcastSettings::content_type(),
                'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ],
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'suppress_filters' => true,
                ]
            )
        );

        return [
            'operation' => $operation,
            'post_type' => PodcastSettings::content_type(),
            'ids' => array_map( 'intval', $ids ),
            'total' => count( $ids ),
            'message' => count( $ids ) . ' podcast item(s) queued.',
        ];
    }

    /** @return array<string,mixed> */
    public function item( AjaxRequest $request ): array {
        $operation = $this->operation( $request );
        $post_id = $request->int( 'post_id', 0, 'post' );
        $post = get_post( $post_id );
        if ( ! $post || ! PodcastSettings::is_podcast_content( $post_id ) ) {
            throw AjaxFailure::not_found( 'Podcast content was not found.' );
        }

        $report = match ( $operation ) {
            'default-host' => DefaultHost::apply( $post_id ),
            'audio' => PowerPressSync::sync_audio( $post_id ),
            'migrate-urls' => $this->migrate_urls( $post_id ),
        };

        return array_merge(
            $report,
            [
                'operation' => $operation,
                'post_id' => $post_id,
                'title' => get_the_title( $post_id ),
                'edit_url' => get_edit_post_link( $post_id, 'raw' ),
                'view_url' => get_permalink( $post_id ),
            ]
        );
    }

    /** @return array<string,mixed> */
    public function save_content_model( AjaxRequest $request ): array {
        $model = $request->key( 'model', '', 'post' );
        if ( ! in_array( $model, [ 'post', 'episode' ], true ) ) {
            throw AjaxFailure::bad_request( 'Select either existing posts or the dedicated episode post type.' );
        }

        $before = PodcastSettings::content_type();
        update_option( 'smp_podcast_content_model', $model, false );
        RewriteLifecycle::schedule();

        return [
            'before' => $before,
            'after' => $model,
            'changed' => $before !== $model,
            'message' => $before !== $model ? 'Podcast content model saved. Reloading the dashboard.' : 'Podcast content model is unchanged.',
            'reload_url' => admin_url( 'options-general.php?page=' . Config::SETTINGS_PAGE . '&tab=settings' ),
        ];
    }

    private function operation( AjaxRequest $request ): string {
        $operation = $request->key( 'operation', '', 'post' );
        if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
            throw AjaxFailure::bad_request( 'Unknown podcast operation.' );
        }
        return $operation;
    }

    /** @return array<string,mixed> */
    private function migrate_urls( int $post_id ): array {
        $map = [
            'urls_spotify' => 'urls_spotify',
            'urls_pandora' => 'urls_pandora',
            'urls_soundcloud' => 'urls_soundcloud',
            'urls_google' => 'urls_google',
            'urls_deezer' => 'urls_deezer',
            'urls_amazon' => 'urls_amazon',
            'urls_apple' => 'urls_apple',
            'urls_iheart' => 'urls_iheart',
            'urls_audible' => 'urls_audible',
            'urls_stitcher' => 'urls_stitcher',
            'urls_blubrry' => 'urls_blubrry',
            'urls_gaana' => 'urls_gaana',
            'urls_podchaser' => 'urls_podchaser',
            'urls_jiosaavn' => 'urls_jiosaavn',
            'urls_tunein' => 'urls_tunein',
            'urls_imdb' => 'urls_imdb',
            'urls_anghami' => 'urls_anghami',
            'urls_youtube_id' => 'urls_youtube',
            'urls_instagram' => 'urls_instagram',
            'urls_listennotes' => 'urls_listennotes',
            'urls_rss' => 'urls_rss',
        ];
        $changed = [];
        foreach ( $map as $old_key => $new_key ) {
            if ( $old_key === $new_key ) {
                continue;
            }
            $old = get_post_meta( $post_id, $old_key, true );
            $new = get_post_meta( $post_id, $new_key, true );
            if ( '' === (string) $new && '' !== (string) $old ) {
                if ( function_exists( 'update_field' ) ) {
                    update_field( $new_key, $old, $post_id );
                } else {
                    update_post_meta( $post_id, $new_key, $old );
                }
                $changed[] = $old_key . ' to ' . $new_key;
            }
        }

        return [
            'changed' => [] !== $changed,
            'before' => [ 'legacy_values_pending' => count( $changed ) ],
            'after' => [ 'fields_migrated' => count( $changed ) ],
            'message' => $changed ? 'Migrated ' . implode( ', ', $changed ) . '.' : 'No legacy URL fields required migration.',
        ];
    }
}
