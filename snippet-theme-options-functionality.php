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

        // Links list (no indent)
        $labels = [
            'wikipedia' => 'Wikipedia',
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'linkedin'  => 'LinkedIn',
            'website'   => 'Website',
        ];

        $has_any = false;
        foreach ( $labels as $key => $label ) {
            if ( ! empty( $urls[ $key ] ) ) {
                $has_any = true;
                break;
            }
        }

        if ( $has_any ) {
            echo '<div style="margin-top:12px;"><strong>Links:</strong>';
            foreach ( $labels as $key => $label ) {
                if ( ! empty( $urls[ $key ] ) ) {
                    echo '<p style="margin:4px 0;">'
                       . '<strong>' . esc_html( $label ) . ':</strong> '
                       . '<a href="' . esc_url( $urls[ $key ] ) . '" target="_blank">'
                       . esc_html( $urls[ $key ] ) . '</a>'
                       . '</p>';
                }
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
