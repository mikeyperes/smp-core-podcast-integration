<?php
namespace smp_core_podcast_functionality;

/**
 * Call this to register the feed.
 */
function enable_rss(): void {
    add_action('init', __NAMESPACE__ . '\\register_internal_rss');
}

/**
 * Registers the “internal-rss” feed.
 */
function register_internal_rss(): void {
    add_feed('internal-rss', __NAMESPACE__ . '\\internal_rss_feed');
}

/**
 * Outputs the RSS XML, pulling in your custom fields.
 */
function internal_rss_feed(): void {
    // load up to 100 episodes; adjust as needed
    query_posts([
        'posts_per_page' => 100,
        'post_type'      => 'episode',
    ]);

    header(
        'Content-Type: ' . feed_content_type('rss-http') .
        '; charset=' . get_option('blog_charset'),
        true
    );

    echo '<?xml version="1.0" encoding="' . get_option('blog_charset') . '"?' . '>';
    ?>
    <rss version="2.0"
         xmlns:content="http://purl.org/rss/1.0/modules/content/"
         xmlns:dc="http://purl.org/dc/elements/1.1/"
         xmlns:atom="http://www.w3.org/2005/Atom"
         <?php do_action('rss2_ns'); ?>>
    <channel>
        <title><?php bloginfo_rss('name'); ?> – Internal Feed</title>
        <atom:link href="<?php self_link(); ?>" rel="self" type="application/rss+xml"/>
        <link><?php bloginfo_rss('url'); ?></link>
        <lastBuildDate><?php echo mysql2date('D, d M Y H:i:s +0000', get_lastpostmodified('GMT'), false); ?></lastBuildDate>
        <language><?php echo get_option('rss_language'); ?></language>
        <?php do_action('rss2_head'); ?>

        <?php while ( have_posts() ) : the_post(); 
            // pull your ACF group + individual fields
            $urls      = get_field('urls') ?: [];
            $hosts     = get_field('hosts') ?: [];
            $summary   = get_field('summary');
            $audio_url = get_field('audio_url');
            $audio_id  = get_field('audio');
            $number    = get_field('number');
            $active    = get_field('active');
            $season    = get_field('season');
            $duration  = get_field('duration');
            $same_as   = get_field('same_as');
            $citations = get_field('citations');
        ?>
        <item>
            <title><?php the_title_rss(); ?></title>
            <link><?php the_permalink_rss(); ?></link>
            <pubDate><?php echo mysql2date('D, d M Y H:i:s +0000', get_post_time('Y-m-d H:i:s', true), false); ?></pubDate>
            <dc:creator><?php the_author(); ?></dc:creator>
            <guid isPermaLink="false"><?php the_guid(); ?></guid>
            <?php rss_enclosure(); ?>
            <?php do_action('rss2_item'); ?>

            <!-- Hosts -->
            <?php if ( $hosts ): ?>
                <?php foreach ( $hosts as $host ): ?>
                    <host><![CDATA[<?php echo esc_html( get_the_title( $host->ID ) ); ?>]]></host>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Summary -->
            <summary><![CDATA[<?php echo $summary; ?>]]></summary>

            <!-- Audio -->
            <audio_url><?php echo esc_url( $audio_url ); ?></audio_url>
            <?php if ( $audio_id ): ?>
                <audio_file><?php echo esc_url( wp_get_attachment_url( $audio_id ) ); ?></audio_file>
            <?php endif; ?>

            <!-- Episode details -->
            <episode_number><?php echo esc_html( $number ); ?></episode_number>
            <active><?php echo $active ? 'true' : 'false'; ?></active>
            <season><?php echo esc_html( $season ); ?></season>
            <duration><?php echo esc_html( $duration ); ?></duration>

            <!-- Schema dump & citations -->
            <same_as><![CDATA[<?php echo $same_as; ?>]]></same_as>
            <citations><![CDATA[<?php echo $citations; ?>]]></citations>

            <!-- URL group -->
            <?php foreach ( [
                'spotify','pandora','soundcloud','google','deezer','amazon',
                'apple','iheart','audible','stitcher','blubrry','gaana',
                'podchaser','jiosaavn','tunein','imdb','anghami','youtube',
                'instagram','listennotes','rss'
            ] as $key ):
                if ( ! empty( $urls[ $key ] ) ): ?>
                    <<?php echo $key; ?>><![CDATA[<?php echo esc_url( $urls[ $key ] ); ?>]]></<?php echo $key; ?>>
                <?php endif;
            endforeach; ?>

        </item>
        <?php endwhile; wp_reset_query(); ?>
    </channel>
    </rss>
    <?php
}

// kick it off
enable_rss();
