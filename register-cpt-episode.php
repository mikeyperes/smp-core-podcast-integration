<?php namespace smp_core_podcast_functionality;

/**
 * Register the Podcast Episode CPT using ACF theme options.
 * Uses get_podcast_settings(): ['singular','plural','slug']
 * Safely bails if the post type already exists.
 */
function register_episode_custom_post_type() {

  
    // sanity: make sure helper exists
    if ( ! function_exists(__NAMESPACE__ . '\get_podcast_settings') ) {
     
        return;
    }

    // Fetch podcast settings from theme options
    $opts = get_podcast_settings();

    // Apply defaults + sanitize
    $singular = ! empty( $opts['singular'] )
        ? sanitize_text_field( $opts['singular'] )
        : 'Podcast Episode';

    $plural = ! empty( $opts['plural'] )
        ? sanitize_text_field( $opts['plural'] )
        : 'Podcast Episodes';

    $slug_raw = $opts['slug'] ?? '';
    $slug     = ! empty( $slug_raw )
        ? sanitize_title( $slug_raw )
        : 'post';


    // Guard rail: if CPT already exists, do nothing
    if ( post_type_exists( $slug ) ) {
        write_log('Bail: post_type_exists("' . $slug . '") === true', true);

        // show what owns it (built-in/plugin/theme)
        $pt_obj = get_post_type_object($slug);
      
        return;
    }

  
    $labels = [
        'name'                     => $plural,
        'singular_name'            => $singular,
        'menu_name'                => $plural,
        'all_items'                => 'All ' . $plural,
        'edit_item'                => 'Edit ' . $singular,
        'view_item'                => 'View ' . $singular,
        'view_items'               => 'View ' . $plural,
        'add_new_item'             => 'Add New ' . $singular,
        'add_new'                  => 'Add New ' . $singular,
        'new_item'                 => 'New ' . $singular,
        'parent_item_colon'        => 'Parent ' . $singular . ':',
        'search_items'             => 'Search ' . $plural,
        'not_found'                => 'No ' . strtolower( $plural ) . ' found',
        'not_found_in_trash'       => 'No ' . strtolower( $plural ) . ' found in Trash',
        'archives'                 => $singular . ' Archives',
        'attributes'               => $singular . ' Attributes',
        'insert_into_item'         => 'Insert into ' . strtolower( $singular ),
        'uploaded_to_this_item'    => 'Uploaded to this ' . strtolower( $singular ),
        'filter_items_list'        => 'Filter ' . strtolower( $plural ) . ' list',
        'filter_by_date'           => 'Filter ' . $plural . ' by date',
        'items_list_navigation'    => $plural . ' list navigation',
        'items_list'               => $plural . ' list',
        'item_published'           => $singular . ' published.',
        'item_published_privately' => $singular . ' published privately.',
        'item_reverted_to_draft'   => $singular . ' reverted to draft.',
        'item_scheduled'           => $singular . ' scheduled.',
        'item_updated'             => $singular . ' updated.',
        'item_link'                => $singular . ' Link',
        'item_link_description'    => 'A link to a ' . strtolower( $singular ) . '.',
    ];

    $args = [
        'labels'            => $labels,
        'public'            => true,
        'show_in_rest'      => true,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'has_archive'       => true,
        'rewrite'           => [ 'slug' => $slug ],
        'supports'          => [
            'title',
            'author',
            'editor',
            'excerpt',
            'revisions',
            'page-attributes',
            'thumbnail',
            'custom-fields',
        ],
        'taxonomies'        => [ 'category', 'post_tag' ],
        'delete_with_user'  => false,
        'menu_position'     => 20,
        'menu_icon'         => 'dashicons-microphone',
    ];

    

    register_post_type( $slug, $args );


}
