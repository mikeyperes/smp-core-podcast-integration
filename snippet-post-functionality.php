<?php namespace smp_core_podcast_functionality;

//use function smp_verified_profiles\get_verified_profile_settings;

/**
 * Hook into ACF init to register our hosts defaulting logic.
 */
add_action('acf/init', __NAMESPACE__ . '\\enable_post_functionality');

/**
 * Register the filter for the “hosts” field.
 */
function enable_post_functionality() {
    add_filter('acf/prepare_field/name=hosts', __NAMESPACE__ . '\\force_default_host_on_new_post');
}

/**
 * Force the “hosts” field to default to the option-set host
 * when adding a new Verified Profile post.
 *
 * @param array $field ACF field settings.
 * @return array Modified field settings.
 */
function force_default_host_on_new_post( $field ) {
    if ( ! is_admin() || ! function_exists('get_current_screen') ) {
        return $field;
    }

    $screen = get_current_screen();
    write_log("[hosts] screen: base={$screen->base}, action={$screen->action}, post_type={$screen->post_type}", true);

    // Only proceed on the “Add New” screen for our CPT
    if ( $screen->base !== 'post' || $screen->action !== 'add' ) {
        write_log('[hosts] skipped: not Add New screen', true);
        return $field;
    }

    // Fetch the expected CPT slug from the ACF options
  //  $settings = get_verified_profile_settings();
    $expected = $settings['slug'];
    $expected = "episode";
    write_log("[hosts] expected CPT slug={$expected}", true);

    // Compare against the actual screen post type
    $post_type = $screen->post_type;
    write_log("[hosts] actual CPT post_type={$post_type}", true);

    if ( $post_type !== $expected ) {
        write_log('[hosts] skipped: CPT mismatch', true);
        return $field;
    }

    // Pull the default_host value from the options page
    $default_host = get_field('default_host', 'option');
    write_log('[hosts] default_host raw: ' . print_r($default_host, true), true);

    // Normalize to an integer ID
    if ( $default_host instanceof \WP_Post ) {
        $default_host_id = $default_host->ID;
    } elseif ( is_array($default_host) ) {
        $default_host_id = intval( $default_host['value'] ?? 0 );
    } else {
        $default_host_id = intval( $default_host );
    }
    write_log("[hosts] normalized default_host_id={$default_host_id}", true);

    // If valid, set it as the field’s default value
    if ( $default_host_id > 0 ) {
        $field['value'] = [ $default_host_id ];
        write_log('[hosts] default host set on field', true);
    }

    return $field;
}



/**
 * Apply the default host to a single post by ID.
 *
 * @param int $post_id
 * @return bool True if updated, false otherwise.
 */
function apply_default_host_to_post( int $post_id ): bool {
    $settings = get_verified_profile_settings();
    $expected = $settings['slug'];

    if ( get_post_type($post_id) !== $expected ) {
        return false;
    }

    // Skip if hosts already set
    $current = get_field('hosts', $post_id, false);
    if ( ! empty($current) ) {
        return false;
    }

    // Get the default host ID
    $default_host = get_field('default_host', 'option');
    if ( $default_host instanceof \WP_Post ) {
        $default_host_id = $default_host->ID;
    } elseif ( is_array($default_host) ) {
        $default_host_id = intval($default_host['value'] ?? 0);
    } else {
        $default_host_id = intval($default_host);
    }

    if ( $default_host_id < 1 ) {
        return false;
    }

    // Update the hosts field
    update_field('hosts', [ $default_host_id ], $post_id);
    write_log("Applied default host ({$default_host_id}) to post {$post_id}", true);
    return true;
}