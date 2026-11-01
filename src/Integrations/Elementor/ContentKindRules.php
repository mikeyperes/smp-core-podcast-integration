<?php

namespace SMP\Podcast\Integrations\Elementor;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Content\ContentKind;
use SMP\Podcast\Settings\PodcastSettings;

/** Plugin-owned conditions preserve the site's existing saved condition keys. */
final class ContentKindRules implements ModuleInterface {
    public function register(): void {
        // The existing helper registers at 20. Replace its condition instances
        // at the same keys without adding duplicate singular child entries.
        add_action( 'elementor/theme/register_conditions', [ $this, 'register_conditions' ], 30 );
    }

    public function register_conditions( mixed $manager ): bool {
        if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base' )
            || ! is_object( $manager ) || ! method_exists( $manager, 'get_condition' ) ) {
            return false;
        }
        $singular = $manager->get_condition( 'singular' );
        if ( ! is_object( $singular ) || ! method_exists( $singular, 'register_sub_condition' ) ) { return false; }
        foreach ( [ new EpisodeCondition(), new ArticleCondition() ] as $condition ) {
            if ( $manager->get_condition( $condition->get_name() ) && method_exists( $manager, 'register_condition_instance' ) ) {
                $manager->register_condition_instance( $condition );
            } else {
                $singular->register_sub_condition( $condition );
            }
        }
        return true;
    }

    public static function matches( string $kind, int $post_id = 0 ): bool {
        $post_id = $post_id > 0 ? $post_id : self::context_post_id();
        if ( $post_id < 1 ) { return false; }
        return match ( $kind ) {
            ContentKind::ARTICLE => 'post' === get_post_type( $post_id ) && ContentKind::is_article( $post_id ),
            ContentKind::EPISODE => PodcastSettings::is_podcast_content( $post_id ),
            default => false,
        };
    }

    public static function context_post_id(): int {
        $candidates = [ function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0 ];
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
            $document = \Elementor\Plugin::$instance->documents->get_current();
            if ( is_object( $document ) && method_exists( $document, 'get_settings' ) ) {
                $candidates[] = (int) $document->get_settings( 'preview_id' );
            }
        }
        $candidates[] = (int) ( $GLOBALS['post']->ID ?? 0 );
        foreach ( array_unique( $candidates ) as $id ) {
            if ( $id > 0 && in_array( get_post_type( $id ), [ 'post', 'episode' ], true ) ) { return $id; }
        }
        return 0;
    }
}
