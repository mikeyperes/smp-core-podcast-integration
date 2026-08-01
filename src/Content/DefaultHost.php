<?php

namespace SMP\Podcast\Content;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;

final class DefaultHost implements ModuleInterface {
    public function register(): void {
        if ( ! (bool) get_option( 'enable_post_functionality', false ) ) {
            return;
        }

        add_filter( 'acf/prepare_field/name=hosts', [ $this, 'prepare_field' ] );
        add_action( 'smp_podcast_content_kind_saved', [ $this, 'assign_after_content_kind' ], 20, 3 );
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    public function prepare_field( array $field ): array {
        if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
            return $field;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'post' !== $screen->base || 'add' !== $screen->action || PodcastSettings::content_type() !== $screen->post_type ) {
            return $field;
        }

        $post_id = ContentKind::current_post_id();
        if ( $post_id < 1 || ! PodcastSettings::is_podcast_content( $post_id ) ) {
            return $field;
        }

        $host_id = PodcastSettings::default_host_id();
        if ( $host_id > 0 && empty( $field['value'] ) ) {
            $field['value'] = [ $host_id ];
        }

        return $field;
    }

    /**
     * The episode ACF group is intentionally hidden while a mixed `post` is
     * unclassified. Assign the default immediately after its first explicit
     * episode classification so editors do not need a second save.
     */
    public function assign_after_content_kind( int $post_id, string $kind, string $previous_kind ): void {
        if ( 'post' !== PodcastSettings::content_type()
            || ContentKind::EPISODE !== $kind
            || ContentKind::EPISODE === $previous_kind ) {
            return;
        }

        self::apply( $post_id );
    }

    /** @return array<string,mixed> */
    public static function apply( int $post_id ): array {
        if ( ! PodcastSettings::is_podcast_content( $post_id ) ) {
            return [ 'changed' => false, 'before' => [], 'after' => [], 'message' => 'Skipped: content type does not match.' ];
        }

        $before = function_exists( 'get_field' ) ? get_field( 'hosts', $post_id, false ) : get_post_meta( $post_id, 'hosts', true );
        $before = is_array( $before ) ? array_values( $before ) : ( empty( $before ) ? [] : [ $before ] );
        if ( $before ) {
            return [ 'changed' => false, 'before' => $before, 'after' => $before, 'message' => 'Skipped: a host is already assigned.' ];
        }

        $host_id = PodcastSettings::default_host_id();
        if ( $host_id < 1 ) {
            return [ 'changed' => false, 'before' => [], 'after' => [], 'message' => 'Skipped: no default host is configured.' ];
        }

        $updated = function_exists( 'update_field' )
            ? update_field( 'hosts', [ $host_id ], $post_id )
            : update_post_meta( $post_id, 'hosts', [ $host_id ] );

        return [
            'changed' => false !== $updated,
            'before' => [],
            'after' => [ $host_id ],
            'message' => false !== $updated ? 'Default host assigned.' : 'The host value did not change.',
        ];
    }
}
