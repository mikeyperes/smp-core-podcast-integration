<?php
namespace smp_core_podcast_functionality;

/**
 * Render extra profile info under the ACF post-object field.
 * Uses your "verified_profile" CPT slug from get_verified_profile_settings().
 * Assumes your ACF group is named "url" with sub-fields in lowercase:
 *   wikipedia, facebook, instagram, linkedin, website
 */
add_action(
    'acf/render_field/key=field_6848b7b0373d3',
    __NAMESPACE__ . '\\render_default_host_info',
    20,
    1
);

function render_default_host_info( $field ) {
    static $already = false;
    if ( $already ) {
        return;
    }
    $already = true;

    $profile_id = intval( $field['value'] );
    if ( ! $profile_id ) {
        return;
    }

    // load your CPT slug dynamically
    $settings = \smp_verified_profiles\get_verified_profile_settings();
    $slug     = $settings['slug'] ?? '';
    $profile  = get_post( $profile_id );
    if ( ! $profile || $profile->post_type !== $slug ) {
        return;
    }

    // ACF group "url"
    $urls = get_field( 'url', $profile_id ) ?: [];

    // Basic profile data
    $title     = get_the_title( $profile );
    $edit_link = get_edit_post_link( $profile_id );
    $view_link = get_permalink( $profile_id );
    $thumbnail = get_the_post_thumbnail(
        $profile_id,
        [64,64],
        ['style'=>'float:left;margin-right:12px;border:1px solid #ccc;']
    );

    // Wrapper
    echo '<div class="smp-profile-info" style="
            background:#f9f9f9;
            border:1px solid #ddd;
            padding:12px;
            margin-top:12px;
            border-radius:4px;
            overflow:hidden;
        ">';

        // Thumbnail + Title
        echo $thumbnail;
        echo '<div style="overflow:hidden;">'
             . '<p style="margin:0 0 4px;"><strong>' . esc_html( $title ) . '</strong></p>'
             . '</div>';

        echo '<div style="clear:both;margin-top:8px;"></div>';

        // Edit/View buttons with extra top margin
        echo '<p style="margin:12px 0 0 0;">';
        if ( $edit_link ) {
            echo '<a href="' . esc_url( $edit_link ) . '" target="_blank" class="button" style="margin-right:6px;">'
                 . 'Edit Profile</a>';
        }
        if ( $view_link ) {
            echo '<a href="' . esc_url( $view_link ) . '" target="_blank" class="button">'
                 . 'View Profile</a>';
        }
        echo '</p>';


     /**
 * Developer Shortcodes (title + biography with values)
 */
$bio_val = (string) get_field( 'biography', $profile_id );
if ( $bio_val === '' ) {
    $bio_val = (string) get_field( 'bio', $profile_id );
}
$bio_html = $bio_val !== '' ? wp_kses_post( $bio_val ) : '<em style="color:#6b7280;">(No biography)</em>';

echo '<div class="smp-dev-shortcodes" style="margin:10px 0 14px; padding:12px; background:#fff; border:1px dashed #cbd5e1; border-radius:6px;">';
    // Title
    echo '<div style="margin-bottom:10px;">'
        . '<div style="font-weight:600; margin-bottom:2px;">Title</div>'
        . '<div style="margin:2px 0 6px 0;">' . esc_html( $title ) . '</div>'
        . '<div style="display:inline-block;font-family:ui-monospace,Menlo,Monaco,monospace;font-size:12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;padding:2px 6px;line-height:1.8;">'
            . '[podcast_host id="title"]'
        . '</div>'
    . '</div>';

    // Biography
    echo '<div>'
        . '<div style="font-weight:600; margin-bottom:2px;">Biography</div>'
        . '<div style="margin:2px 0 6px 0;">' . $bio_html . '</div>'
        . '<div style="display:inline-block;font-family:ui-monospace,Menlo,Monaco,monospace;font-size:12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;padding:2px 6px;line-height:1.8;">'
            . '[podcast_host id="biography"]'
        . '</div>'
    . '</div>';
echo '</div>';

// Links list (shortcode badge directly ABOVE each link)
$labels = [
    'wikipedia' => 'Wikipedia',
    'facebook'  => 'Facebook',
    'instagram' => 'Instagram',
    'linkedin'  => 'LinkedIn',
    'website'   => 'Website',
];

$has_any = false;
foreach ( $labels as $key => $label ) {
    if ( ! empty( $urls[ $key ] ) ) { $has_any = true; break; }
}

if ( $has_any ) {
    echo '<div style="margin-top:12px;"><strong>Links:</strong>';

    foreach ( $labels as $key => $label ) {
        if ( empty( $urls[ $key ] ) ) { continue; }

        echo '<div style="margin:10px 0 12px;">';
            // Shortcode badge (above)
            echo '<div style="margin:0 0 4px 0;">'
               . '<span style="display:inline-block;font-family:ui-monospace,Menlo,Monaco,monospace;font-size:12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;padding:2px 6px;line-height:1.8;">'
               . '[podcast_host id="url_' . esc_attr( $key ) . '"]'
               . '</span>'
               . '</div>';

            // Visible link row
            echo '<div>'
               . '<strong>' . esc_html( $label ) . ':</strong> '
               . '<a href="' . esc_url( $urls[ $key ] ) . '" target="_blank">'
               . esc_html( $urls[ $key ] ) . '</a>'
               . '</div>';
        echo '</div>';
    }

    echo '</div>';
}


    echo '</div>';
}














add_action('admin_enqueue_scripts', function( $hook ) {
 
    // 1) make sure this is the right admin page
    $screen = get_current_screen();
    if ( $screen->id !== 'toplevel_page_theme-options' ) {
      //  return;
    }

    // 2) enqueue jQuery
    wp_enqueue_script('jquery');
    
    // 3) grab ACF data
    $website = get_field('website', 'option');
    if ( empty($website['user']['ID']) ) {

 
        

        return;
   
    }


    $user_id = absint( $website['user']['ID'] );
    $urls = get_field('urls', 'user_' . $user_id);
    if ( ! is_array($urls) ) {


        return;
    }

    $user = get_userdata( $user_id );
    $data = [
        'user' => [
            'avatar' => get_avatar_url( $user_id, [ 'size' => 80 ] ),
            'name'   => $user->display_name,
            'edit'   => get_edit_user_link( $user_id ),
            'view'   => get_author_posts_url( $user_id ),
        ],
        'urls' => $urls,
    ];

    // 4) register & enqueue an “empty” handle to attach our inline code
    wp_register_script( 'smp-user-card', false, [ 'jquery' ], null, true );
    wp_enqueue_script( 'smp-user-card' );

    // 5) pass PHP data to JS
    wp_localize_script( 'smp-user-card', 'SMPData', $data );

    // 6) inject the card once under our ACF group label
    wp_add_inline_script( 'smp-user-card', <<<'JS'

jQuery(function($){
    console.log('SMPData:', SMPData);
    var d = SMPData;
    // target by data-name in case key changed
    var g = $('.acf-field-group[data-name="podcast_urls"]');
    if (!g.length) return;

    var label = g.children('.acf-label').find('label');
    if (!label.length) return;

    var card = $('<div class="smp-user-card">').css({
        padding: '12px',
        border: '1px solid #ddd',
        marginBottom: '12px',
        borderRadius: '4px'
    });


      // Developer Shortcodes (title / biography / website)
      var dev = $('<div class="smp-dev-shortcodes">').css({
        marginTop: '12px',
        padding: '10px',
        background: '#fff',
        border: '1px dashed #cbd5e1',
        borderRadius: '6px'
    });

    var codeBadge = function(text){
        return $('<span>').text(text).css({
            display:'inline-block',
            fontFamily:'ui-monospace,Menlo,Monaco,monospace',
            fontSize:'12px',
            background:'#f3f4f6',
            border:'1px solid #e5e7eb',
            borderRadius:'4px',
            padding:'2px 6px',
            lineHeight:1.8
        });
    };


    // avatar + name
    $('<div>').css({ display: 'flex', alignItems: 'center' }).append(
        $('<img>', { src: d.user.avatar, width: 80, height: 80 })
            .css({ borderRadius: '50%', marginRight: '12px' }),
        $('<strong>').text(d.user.name)
    ).appendTo(card);

    // action buttons
    $('<div>').css({ marginTop: '8px' }).append(
        $('<a>', { href: d.user.edit, target: '_blank', class: 'button' }).text('Edit Profile'),
        ' ',
        $('<a>', { href: d.user.view, target: '_blank', class: 'button' }).text('View Profile')
    ).appendTo(card);


    dev.append(
        $('<div>').css({fontWeight:600, marginBottom:'6px'}).text('Developer Shortcodes'),
        $('<p>').css({margin:'4px 0'}).append(
            $('<span>').css({display:'block',fontWeight:600,marginBottom:'2px'}).text('title'),
            codeBadge('[podcast id="title"]')
        ),
        $('<p>').css({margin:'4px 0'}).append(
            $('<span>').css({display:'block',fontWeight:600,marginBottom:'2px'}).text('biography'),
            codeBadge('[podcast id="biography"]')
        ),
        $('<p>').css({margin:'4px 0'}).append(
            $('<span>').css({display:'block',fontWeight:600,marginBottom:'2px'}).text('website'),
            codeBadge('[podcast id="website"]')
        ),
        $('<p>').css({margin:'4px 0'}).append(
            $('<span>').css({display:'block',fontWeight:600,marginBottom:'2px'}).text('facebook'),
            codeBadge('[podcast id="url_facebook"]')
        )
    );

    
    card.append(dev);

    
    // URLs list
    var box = $('<div class="smp-website-user-urls">')
        .css({ marginTop: '12px' })
        .append('<p><strong>Website User URLs:</strong></p>');

    $.each(d.urls, function(key, link){
        link = $.trim(link);
        if (!link) return;
        var labelText = key.replace(/_/g,' ').replace(/\b\w/g, m => m.toUpperCase());
        box.append('<p><strong>'+labelText+':</strong> <a href="'+link+'" target="_blank">'+link+'</a></p>');
    });

    card.append(box);
    label.after(card);
});
JS
    );
});






/**
 * Plugin Part: Podcast Host Shortcode (Options-Based)
 *
 * Shortcode: [podcast_host id="..."]
 *
 * Examples (supported ids):
 *   [podcast_host id="title"]         => "Michael Peres"
 *   [podcast_host id="url_facebook"]  => "https://facebook.com/username"
 *   [podcast_host id="biography"]     => (Bio text from the Verified Profile CPT)
 *
 * Purpose:
 *  - Read the Default Host from Theme Options (ACF field NAME: "default_host")
 *  - Validate it is a Verified Profile (via \smp_verified_profiles\get_verified_profile_settings()['slug'])
 *  - Return specific data based on the requested id:
 *      - title        : post title
 *      - url_facebook: value from ACF group "url" (key: 'facebook')
 *      - biography    : value from ACF text/WYSIWYG field 'biography' (fallbacks: 'bio')
 *
 * Assumptions:
 *  - This file already runs inside your namespace (DO NOT redeclare it here).
 *  - ACF Options field "default_host" returns a Post Object (return_format: object).
 *  - ACF group field on the Verified Profile named "url" returns an array of keys: facebook, instagram, etc.
 */

\add_shortcode( 'podcast_host', __NAMESPACE__ . '\\podcast_host_shortcode' );

/**
 * Shortcode callback for [podcast_host id="..."].
 *
 * @param array $atts Shortcode attributes. Supports: title, url_facebook, biography.
 * @return string
 */
function podcast_host_shortcode( $atts ): string {
    // Normalize attributes; keep default "title"
    $atts = shortcode_atts(
        [
            'id' => 'title',
        ],
        $atts,
        'podcast_host'
    );
    $field_req = strtolower( trim( (string) $atts['id'] ) );

    // Ensure ACF is available
    if ( ! function_exists( 'get_field' ) ) {
        return '';
    }

    /**
     * STEP 1: Fetch the Default Host from ACF Options.
     * Field NAME per your schema: "default_host" (Post Object, return_format: object)
     */
    $val = get_field( 'default_host', 'option' );

    // Normalize to a post ID (defensive)
    if ( is_object( $val ) && isset( $val->ID ) ) {
        $profile_id = (int) $val->ID;
    } elseif ( is_array( $val ) ) {
        $first      = reset( $val );
        $profile_id = ( is_object( $first ) && isset( $first->ID ) ) ? (int) $first->ID : (int) $first;
    } else {
        $profile_id = (int) $val;
    }

    if ( $profile_id <= 0 ) {
        return '';
    }

    /**
     * STEP 2: Validate the post type against the Verified Profiles CPT.
     */
    $slug = '';
    if ( function_exists( '\smp_verified_profiles\get_verified_profile_settings' ) ) {
        $settings = \smp_verified_profiles\get_verified_profile_settings();
        $slug     = isset( $settings['slug'] ) ? (string) $settings['slug'] : '';
    }

    $profile = get_post( $profile_id );
    if ( ! $profile ) {
        return '';
    }
    if ( $slug && $profile->post_type !== $slug ) {
        return '';
    }

    /**
     * STEP 3: Return per requested field.
     */
    switch ( $field_req ) {

        case 'title': {
            $title = get_the_title( $profile_id );
            return $title ? esc_html( $title ) : '';
        }

        case 'url_facebook': {
            // Pull ACF group "url" and return the 'facebook' key if present
            $urls = get_field( 'url', $profile_id );
            $fb   = is_array( $urls ) && ! empty( $urls['facebook'] ) ? (string) $urls['facebook'] : '';
            return $fb ? esc_url( $fb ) : '';
        }

        case 'biography': {
            // Try 'biography' first; fallback to 'bio'
            $bio = (string) get_field( 'biography', $profile_id );
            if ( $bio === '' ) {
                $bio = (string) get_field( 'bio', $profile_id );
            }
            // Return as-is (may contain HTML from WYSIWYG)
            return $bio !== '' ? $bio : '';
        }

        default: {
            return '';
        }
    }
}









/**
 * Shortcode: [podcast id="..."]
 *
 * Examples (supported ids):
 *   [podcast id="title"]          => "Jane Smith" (user's full/display name)
 *   [podcast id="biography"]      => (User biography text/HTML)
 *   [podcast id="website"]        => https://example.com (user website URL)
 *   [podcast id="url_facebook"]   => https://facebook.com/username   (from user “urls” array)
 *   [podcast id="url_instagram"]  => https://instagram.com/username  (from user “urls” array)
 *   [podcast id="url_linkedin"]   => https://linkedin.com/in/handle  (from user “urls” array)
 *
 * How user is selected:
 *   1) Read ACF Options group "website" and its 'user' sub-field (Post Object: User).
 *   2) Use that user's ACF/user meta and core fields.
 *
 * Notes:
 *   - Returns empty string when not resolvable.
 *   - Biography may contain HTML (WYSIWYG); returned as-is.
 *   - URL outputs are escaped with esc_url().
 *   - This file already runs inside your namespace (do NOT redeclare it here).
 */

 \add_shortcode( 'podcast', __NAMESPACE__ . '\\podcast_shortcode' );

 /**
  * [podcast id="..."] — fetch data from a User (not a CPT).
  *
  * @param array $atts Supports: title, biography, website, url_{platform}
  * @return string
  */
 function podcast_shortcode( $atts ): string {
     // Normalize attributes; default to title
     $atts = shortcode_atts(
         [
             'id' => 'title',
         ],
         $atts,
         'podcast'
     );
     $requested = strtolower( trim( (string) $atts['id'] ) );
 
     // ACF must exist
     if ( ! function_exists( 'get_field' ) ) {
         return '';
     }
 
     // Resolve the “Website Owner” user from Options: website -> user (ACF user field)
     $website = get_field( 'website', 'option' );
     if ( ! ( is_array( $website ) && ! empty( $website['user']['ID'] ) ) ) {
         return '';
     }
     $user_id = (int) $website['user']['ID'];
 
     // Preload commonly used user data
     $userdata  = get_userdata( $user_id );
     $user_name = $userdata ? $userdata->display_name : '';
 
     // ACF on user profile: use "user_{$ID}" context
     $user_key   = 'user_' . $user_id;
     $user_urls  = get_field( 'urls', $user_key );            // expected array like ['facebook' => '...']
     $user_bio   = (string) get_field( 'biography', $user_key );
     $user_site  = (string) get_field( 'website', $user_key ); // optional direct website field
 
     // Handle request
     switch ( $requested ) {
         case 'title':
             return $user_name ? esc_html( $user_name ) : '';
 
         case 'biography':
             if ( $user_bio === '' ) {
                 // Optional fallback to core user description if ACF field empty
                 $core_bio = $userdata ? (string) $userdata->description : '';
                 return $core_bio !== '' ? $core_bio : '';
             }
             // Return as-is (may contain HTML from WYSIWYG)
             return $user_bio;
 
         case 'website':
             // Prefer dedicated ACF website field; fallback to urls['website']; finally WP core user_url
             if ( $user_site !== '' ) {
                 return esc_url( $user_site );
             }
             if ( is_array( $user_urls ) && ! empty( $user_urls['website'] ) ) {
                 return esc_url( (string) $user_urls['website'] );
             }
             $core_url = $userdata ? (string) $userdata->user_url : '';
             return $core_url !== '' ? esc_url( $core_url ) : '';
 
         default:
             // Support any url_{platform} key, e.g., url_facebook, url_instagram, url_youtube, etc.
             if ( str_starts_with( $requested, 'url_' ) ) {
                 $platform = substr( $requested, 4 );                      // e.g., "facebook"
                 $platform = sanitize_key( $platform );                    // safety
                 if ( $platform !== '' && is_array( $user_urls ) && ! empty( $user_urls[ $platform ] ) ) {
                     return esc_url( (string) $user_urls[ $platform ] );
                 }
             }
             return '';
     }
 }
 