<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;

final class InternalFeed implements ModuleInterface {
    public function register(): void {
        add_action( 'init', [ $this, 'maybe_register_feed' ], 9 );
        add_action( 'update_option_enable_rss', [ $this, 'schedule_rewrite_flush' ], 10, 2 );
        add_action( 'add_option_enable_rss', [ $this, 'schedule_rewrite_flush' ], 10, 2 );
    }

    public function maybe_register_feed(): void {
        if ( (bool) get_option( 'enable_rss', false ) ) {
            $this->register_feed();
        }
    }

    public function schedule_rewrite_flush( mixed $before = null, mixed $after = null ): void {
        unset( $before, $after );
        \SMP\Podcast\Support\RewriteLifecycle::schedule();
    }

    public function register_feed(): void {
        add_feed( 'internal-rss', [ $this, 'render' ] );
    }

    public function render(): void {
        $query = new \WP_Query(
            PodcastSettings::scoped_query_args(
                [
                'post_type' => PodcastSettings::content_type(),
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                ]
            )
        );

        status_header( 200 );
        header( 'Content-Type: ' . feed_content_type( 'rss-http' ) . '; charset=' . get_option( 'blog_charset' ), true );
        echo '<?xml version="1.0" encoding="' . esc_attr( (string) get_option( 'blog_charset' ) ) . '"?>';
        ?>
        <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom" <?php do_action( 'rss2_ns' ); ?>>
        <channel>
            <title><?php bloginfo_rss( 'name' ); ?> - Internal Feed</title>
            <atom:link href="<?php self_link(); ?>" rel="self" type="application/rss+xml" />
            <link><?php bloginfo_rss( 'url' ); ?></link>
            <lastBuildDate><?php echo esc_html( mysql2date( 'D, d M Y H:i:s +0000', get_lastpostmodified( 'GMT' ), false ) ); ?></lastBuildDate>
            <language><?php echo esc_html( (string) get_option( 'rss_language' ) ); ?></language>
            <?php do_action( 'rss2_head' ); ?>
            <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                <?php $this->item(); ?>
            <?php endwhile; ?>
        </channel>
        </rss>
        <?php
        wp_reset_postdata();
    }

    private function item(): void {
        $post_id = get_the_ID();
        $urls = function_exists( 'get_field' ) ? (array) get_field( 'urls', $post_id ) : [];
        $hosts = function_exists( 'get_field' ) ? (array) get_field( 'hosts', $post_id ) : [];
        ?>
        <item>
            <title><?php the_title_rss(); ?></title>
            <link><?php the_permalink_rss(); ?></link>
            <pubDate><?php echo esc_html( mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ) ); ?></pubDate>
            <dc:creator><?php the_author(); ?></dc:creator>
            <guid isPermaLink="false"><?php the_guid(); ?></guid>
            <?php rss_enclosure(); ?>
            <?php do_action( 'rss2_item' ); ?>
            <?php foreach ( $hosts as $host ) : ?>
                <?php $host_id = is_object( $host ) ? (int) $host->ID : (int) $host; ?>
                <host><![CDATA[<?php echo wp_strip_all_tags( get_the_title( $host_id ) ); ?>]]></host>
            <?php endforeach; ?>
            <summary><![CDATA[<?php echo wp_kses_post( (string) get_post_meta( $post_id, 'summary', true ) ); ?>]]></summary>
            <audio_url><?php echo esc_url( (string) get_post_meta( $post_id, 'audio_url', true ) ); ?></audio_url>
            <episode_number><?php echo esc_html( (string) get_post_meta( $post_id, 'number', true ) ); ?></episode_number>
            <active><?php echo get_post_meta( $post_id, 'active', true ) ? 'true' : 'false'; ?></active>
            <season><?php echo esc_html( (string) get_post_meta( $post_id, 'season', true ) ); ?></season>
            <duration><?php echo esc_html( (string) get_post_meta( $post_id, 'duration', true ) ); ?></duration>
            <same_as><![CDATA[<?php echo wp_kses_post( (string) get_post_meta( $post_id, 'same_as', true ) ); ?>]]></same_as>
            <citations><![CDATA[<?php echo wp_kses_post( (string) get_post_meta( $post_id, 'citations', true ) ); ?>]]></citations>
            <?php foreach ( $urls as $key => $url ) : ?>
                <?php $key = sanitize_key( (string) $key ); ?>
                <?php if ( '' !== $key && is_scalar( $url ) && '' !== trim( (string) $url ) ) : ?>
                    <<?php echo esc_attr( $key ); ?>><![CDATA[<?php echo esc_url( (string) $url ); ?>]]></<?php echo esc_attr( $key ); ?>>
                <?php endif; ?>
            <?php endforeach; ?>
        </item>
        <?php
    }
}
