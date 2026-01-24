<?php namespace smp_core_podcast_functionality;


// Ensure function existence before declaring
if (!function_exists(__NAMESPACE__ . '\\display_single_profile_validate_schema_button')) {
    /** 
    * Displays a button to validate the schema of the current profile page.
    *
    * @return string HTML of the schema validation button.
    */
    function display_single_profile_validate_schema_button() {
    return '<a target=_blank href="https://validator.schema.org/#url=' . get_the_permalink() . '">Validate schema of ' . get_the_title() . '<i aria-hidden="true" class="fas fa-external-link-square-alt"></i></a>';
    }
    } else 
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\display_single_profile_validate_schema_button function is already declared", true);

    









         //SCHEMA UPDATE

if (!function_exists(__NAMESPACE__ . '\\update_schema_markup')) {
    /**
     * Updates the schema markup for profiles in batches, outputs the status of updates, and shows relevant post details.
     * 
     * Checks for 'update_schema' query parameter, batch size, and updates schema markup for profile posts.
     * Outputs information about total profiles, published profiles, drafts, and processed profiles.
     */
    function update_schema_markup() {
        // Ensure the update is triggered by an authorized user with 'manage_options' capability
        if (isset($_GET['update_schema']) && current_user_can('manage_options')) {
            // Determine batch size, defaulting to 20 if not specified
            $batch = isset($_GET['batch']) ? intval($_GET['batch']) : 20;

            // Base query to fetch profile IDs for total counts
            $base_query_args = [
                'post_type' => 'profile',
                'posts_per_page' => -1,  // Get all posts
                'fields' => 'ids'        // Fetch only the IDs to speed up the query
            ];

            // Query for total profiles
            $total_profiles_query = new \WP_Query($base_query_args);
            $total_profiles = $total_profiles_query->found_posts;

            // Query for published profiles
            $published_profiles_query = new \WP_Query(array_merge($base_query_args, ['post_status' => 'publish']));
            $total_published = $published_profiles_query->found_posts;

            // Query for draft profiles
            $draft_profiles_query = new \WP_Query(array_merge($base_query_args, ['post_status' => 'draft']));
            $total_drafts = $draft_profiles_query->found_posts;

            // Query for processing updates with limited batch
            $update_query_args = array_merge($base_query_args, [
                'posts_per_page' => $batch,
                'no_found_rows' => true,  // Disable pagination for faster processing
                'post_status' => 'publish'
            ]);
            $update_query = new \WP_Query($update_query_args);
            $total_processed = 0;

            // Process each post, generating schema markup
            if ($update_query->have_posts()) {
                foreach ($update_query->posts as $post_id) {
                    generate_schema_markup($post_id); // Assuming this function exists and updates schema markup
                    echo "Updated schema markup for post ID: {$post_id} - <a href='" . get_permalink($post_id) . "' target='_blank'>" . get_the_title($post_id) . "</a><br>";
                    $total_processed++;
                }
            } else {
                echo "No more posts to update.<br>";
            }

            // Output the counts for all profiles, published, drafts, and the number processed
            echo "Total profiles: $total_profiles<br>";
            echo "Total published profiles: $total_published<br>";
            echo "Total draft profiles: $total_drafts<br>";
            echo "Total processed this batch: $total_processed<br>";

            die(); // Stop further processing
        }
    }

    // Hook the update function to run during the 'admin_init' action
    add_action('admin_init', __NAMESPACE__ . '\\update_schema_markup');

} else write_log("⚠️ Warning: " . __NAMESPACE__ . "\\update_schema_markup function is already declared", true);




    ?>