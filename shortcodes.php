<?php
namespace smp_core_podcast_functionality;

// register the shortcode in this namespace
add_shortcode( 'podcast_url', __NAMESPACE__ . '\\podcast_url_shortcode' );
// 1) Shortcode to output any ACF field by name or from a group sub‑field
add_shortcode( 'episode_fields', __NAMESPACE__ . '\\episode_fields_shortcode' );
add_shortcode( 'article_guests', __NAMESPACE__ . '\\article_guests_links' );
add_shortcode( 'podcast_hosts', __NAMESPACE__ . '\\podcast_hosts_links' );
add_shortcode( 'display_single_episode_hosts', __NAMESPACE__ . '\\display_single_episode_hosts' );




/**
 * Register the [guest_grid] shortcode.
 */
\add_action( 'init', function() {
    \add_shortcode( 'guest_grid', __NAMESPACE__ . '\\display_users_grid' );
} );
























// -----------------------------------------------------------------------------
// Shortcode [display_single_episode_hosts]
// -----------------------------------------------------------------------------
if ( ! function_exists( __NAMESPACE__ . '\\display_single_episode_hosts' ) ) {
    /**
     * Shortcode: [display_single_episode_hosts must_have_thumbnail="true|false"]
     *
     * Displays hosts of a podcast episode in a 6-column grid (2 cols on mobile),
     * pulling an Elementor loop-item template with its CSS inline.
     *
     * @param array $atts {
     *   @type bool $must_have_thumbnail If true, only include hosts that have a featured image.
     * }
     * @return string HTML or inline style to hide container.
     */
    function display_single_episode_hosts( $atts = [] ) {
        global $post;
        $empty_response = '<style>.display_single_episode_hosts{display:none !important;}</style>';

        // 1) Parse shortcode attributes
        $atts       = shortcode_atts( [
            'must_have_thumbnail' => false,
        ], $atts, 'display_single_episode_hosts' );
        $must_thumb = filter_var( $atts['must_have_thumbnail'], FILTER_VALIDATE_BOOLEAN );

        // 2) Only run on single post pages
        if ( ! is_single() ) {
            return 'This shortcode is only for single post pages.';
        }



        // 5) Gather host IDs from the 'hosts' field
        $host_field = get_field( 'hosts', $post->ID );
        if ( empty( $host_field ) || ! is_array( $host_field ) ) {
            return $empty_response;
        }

        $host_ids = [];
        foreach ( $host_field as $host ) {
            // support object or ID
            if ( is_object( $host ) && isset( $host->ID ) ) {
                $hid = $host->ID;
            } elseif ( is_numeric( $host ) ) {
                $hid = (int) $host;
            } else {
                continue;
            }
            if ( $must_thumb && ! has_post_thumbnail( $hid ) ) {
                continue;
            }
            $host_ids[] = $hid;
        }
        if ( empty( $host_ids ) ) {
            return $empty_response;
        }

        // 6) Render the Elementor template for each host
        $original_post = $post;
        $frontend      = \Elementor\Plugin::instance()->frontend;

        ob_start();
        ?>
        <style>
        .display_single_episode_hosts{width:100%;display:block}
        .display_single_episode_hosts .shortcode {
          width:100%;
          display:grid;
          grid-template-columns:repeat(6,1fr);
          gap:1rem;
        }
        @media (max-width:600px){
          .display_single_episode_hosts .shortcode {
            grid-template-columns:repeat(2,1fr)!important;
          }
        }
        </style>
        <div class="display_single_episode_hosts">
          <div class="shortcode">
        <?php
        foreach ( $host_ids as $hid ) {
            $host_post = get_post( $hid );
            if ( ! $host_post ) {
                continue;
            }
            $GLOBALS['post'] = $host_post;
            setup_postdata( $host_post );
            echo $frontend->get_builder_content_for_display( 20001, true );
        }
        ?>
          </div>
        </div>
        <?php
        wp_reset_postdata();
        $GLOBALS['post'] = $original_post;

        return ob_get_clean();
    }
}





// -----------------------------------------------------------------------------
// Shortcode [article_guests]
// -----------------------------------------------------------------------------
if ( ! function_exists( __NAMESPACE__ . '\\article_guests_links' ) ) {
    function article_guests_links() {
        global $post;
        static $css_added = false;

        // --- NO GUESTS? inject REAL CSS in the <head> then bail
        if ( ! function_exists('have_rows') || ! have_rows('profiles', $post->ID) ) {
            if ( ! $css_added ) {
                \add_action( 'wp_head', function(){
                    echo '<style>.shortcode_article_guests{display:none!important;}</style>';
                } );
                $css_added = true;
            }
            return '';
        }

        // --- YES GUESTS: build your links
        $links = [];
        while ( have_rows('profiles', $post->ID) ) {
            the_row();
            $pid = get_sub_field('profile');
            if ( empty($pid) || ! is_numeric($pid) ) {
                continue;
            }
            $url  = get_permalink($pid);
            $name = get_the_title($pid);
            if ( $url && $name ) {
                $links[] = '<a href="'. esc_url($url) .'">'. esc_html($name) .'</a>';
            }
        }
        if ( function_exists('reset_rows') ) {
            reset_rows();
        }

        // wrap the guest links
        return '<span class="shortcode_article_guests">'. implode(', ', $links) .'</span>';
    }

}





// -----------------------------------------------------------------------------
// Shortcode [podcast_hosts]
// -----------------------------------------------------------------------------
if ( ! function_exists( __NAMESPACE__ . '\\podcast_hosts_links' ) ) {
    function podcast_hosts_links() {
        global $post;
        static $css_added = false;

        // --- NO HOSTS? inject CSS in the <head> then bail
        $hosts = function_exists('get_field') ? get_field('hosts', $post->ID) : false;
        if ( empty( $hosts ) || ! is_array( $hosts ) ) {
            if ( ! $css_added ) {
                add_action( 'wp_head', function(){
                    echo '<style>.shortcode_podcast_hosts{display:none!important;}</style>';
                } );
                $css_added = true;
            }
            return '';
        }

        // --- YES HOSTS: build your links
        $links = [];
        foreach ( $hosts as $host ) {
            // support object or ID
            $host_id = is_object( $host ) && isset( $host->ID ) 
                ? $host->ID 
                : ( is_numeric( $host ) ? intval( $host ) : 0 );
            if ( ! $host_id ) {
                continue;
            }
            $url  = get_permalink( $host_id );
            $name = get_the_title( $host_id );
            if ( $url && $name ) {
                $links[] = '<a href="'. esc_url( $url ) .'">'. esc_html( $name ) .'</a>';
            }
        }

        return '<span class="shortcode_podcast_hosts">'. implode( ', ', $links ) .'</span>';
    }
}




/**
 * Shortcode: [guest_grid]
 * Outputs a responsive grid of user cards with six columns per row and a simpler button style.
 */
function display_users_grid() {
    $args = [
        'role__in' => [
            'Administrator',
            'Editor',
            'Author',
            'Contributor',
            'Subscriber',
            'Verified Profile Manager',
            'Customer',
        ],
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'number'  => -1,
    ];
    $users = \get_users( $args );

    if ( empty( $users ) ) {
        return '';
    }

    \ob_start();
    ?>
    <style>
        .user-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }
        .user-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 1rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
        }
        .user-card img {
            border-radius: 50%;
            width: 100%;
            max-width: 100px;
            height: auto;
            margin-bottom: 0.75rem;
        }
        .user-card h3 {
            margin: 0.5rem 0;
            font-size: 1rem;
            color: #333;
        }
        .view-member-button {
            margin-top: 0.5rem;
            padding: 0.4rem 0.8rem;
            background: transparent;
            color: #0073aa;
            text-decoration: none;
            border: 1px solid #0073aa;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .view-member-button:hover {
            background: #0073aa;
            color: #fff;
        }
        @media (max-width: 1024px) {
            .user-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (max-width: 640px) {
            .user-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>

    <div class="user-grid">
        <?php foreach ( $users as $user ) : ?>
            <div class="user-card">
                <?php echo \get_avatar( $user->ID, 100 ); ?>
                <h3><?php echo \esc_html( $user->display_name ); ?></h3>
                <a href="<?php echo \esc_url( \get_author_posts_url( $user->ID ) ); ?>"
                   class="view-member-button">
                    View Member
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return \ob_get_clean();
}
















function podcast_url_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'social' => '',
    ], $atts, 'podcast_url' );

    $key = sanitize_key( $atts['social'] );
    if ( ! $key ) {
        return '';
    }

    $url    = '';
    $source = '';
  
    $website = get_field('website', 'option');


  
    // 2) if we found a website.user, pull that user's URLs
    if ( is_array( $website ) && ! empty( $website['user']['ID'] ) ) {
        $user_id   = $website['user']['ID'];
        $user_urls = get_field( 'urls', 'user_' . $user_id );
        if ( is_array( $user_urls ) && ! empty( $user_urls[ $key ] ) ) {
            $url    = $user_urls[ $key ];
            $source = 'user_' . $user_id;
        }
    }

    // 3) fallback to podcast_urls on the default options bucket
    if ( ! $url ) {
        $options_urls = get_field( 'podcast_urls', 'option' );
        if ( is_array( $options_urls ) && ! empty( $options_urls[ $key ] ) ) {
            $url    = $options_urls[ $key ];
            $source = 'options';
        }
    }


    return $url ? esc_url( $url ) : '';
}






function episode_fields_shortcode( $atts ) {
    $atts     = shortcode_atts( [ 'name' => '' ], $atts, 'episode_fields' );
    $field    = $atts['name'];
    $post_id  = get_the_ID();

    if ( ! $field ) {
        return '';
    }

    // support group sub‑fields: e.g. urls_pandora, urls_facebook, etc.
    if ( strpos( $field, '_' ) !== false ) {
        list( $group_key, $sub_key ) = explode( '_', $field, 2 );
        $group = get_field( $group_key, $post_id );
        if ( is_array( $group ) && array_key_exists( $sub_key, $group ) ) {
            $value = $group[ $sub_key ];
        } else {
            return '';
        }
    } else {
        // top‑level field
        $value = get_field( $field, $post_id );
        if ( $value === null ) {
            return '';
        }
    }

    // if it's empty or false
    if ( empty( $value ) ) {
        return '';
    }

    // if it's an array, join with commas
    if ( is_array( $value ) ) {
        return implode( ', ', $value );
    }

    return $value;
}
