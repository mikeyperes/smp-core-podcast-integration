<?php namespace smp_core_podcast_functionality;

function enable_snippet_profile_post_wp_admin_functionality()
{
// Hook into the save post action
add_action('save_post', __NAMESPACE__.'\save_acf_post_id');
add_action('wp_head', __NAMESPACE__.'\inject_schema_on_single_profile');
add_action('save_post', __NAMESPACE__.'\generate_schema_markup');
add_action('admin_footer', __NAMESPACE__.'\enforce_featured_image_with_jquery');
add_action('admin_footer', __NAMESPACE__.'\custom_quick_edit_javascript');
add_action('init', __NAMESPACE__.'\remove_content_editor_from_profile_cpt');
add_action('init',  __NAMESPACE__.'\disable_content_editor_for_profile');
}




/**
 * Disable the content editor for 'profile' custom post type
 * Removes the WordPress content editor from the 'profile' post type.
 */
function disable_content_editor_for_profile() {
    remove_post_type_support('profile', 'editor');
}



//dummy function, delete 

function check_plugin_acf()
{return true;}

/**
 * Save ACF post ID into the 'post_id' field when the post is saved.
 * 
 * @param int $post_id The ID of the post being saved.
 */
if (!function_exists(__NAMESPACE__ . '\\save_acf_post_id')) {
    function save_acf_post_id($post_id) {
        if (!check_plugin_acf()) {
            return;
        }
        update_field('post_id', $post_id, $post_id);
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\save_acf_post_id function is already declared", true);
}

/**
 * Inject schema markup into the head section of a 'profile' post type single view.
 */
if (!function_exists(__NAMESPACE__ . '\\inject_schema_on_single_profile')) {
    function inject_schema_on_single_profile() {
        if (!check_plugin_acf()) {
            return;
        }
        if (is_singular('profile')) { 
            global $post;
            $schema_json = get_field('schema_markup', $post->ID);
            if ($schema_json) {
                echo "<script type='application/ld+json'>" . $schema_json . "</script>";
            }
        }
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\inject_schema_on_single_profile function is already declared", true);
}

/**
 * Generate and save schema markup for a 'profile' or 'organization' based on post categories.
 * 
 * @param int $post_id The ID of the post being saved.
 */
if (!function_exists(__NAMESPACE__ . '\\generate_schema_markup')) {
    function generate_schema_markup($post_id) {
        if (!check_plugin_acf()) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $post_categories = get_the_category($post_id);
        $category_slugs = array_map(function($category) {
            return $category->slug;
        }, $post_categories);

        $schema = [];

        if (in_array('person', $category_slugs)) {
            $education_entries = get_field('personal_education', $post_id);
            $alumniOf = [];
            if ($education_entries) {
                foreach ($education_entries as $entry) {
                    $alumniOf[] = [
                        "@type" => "EducationalOrganization",
                        "name" => $entry['school'],
                        "sameAs" => $entry['wikipedia_url']
                    ];
                }
            }

            $birthPlaceName = get_field('personal_location_born_name', $post_id);
            $homeLocationName = get_field('personal_current_residence_name', $post_id);
            $bio = get_field('short_description', $post_id);

            $birthPlace = $birthPlaceName ? ["@type" => "Place", "name" => $birthPlaceName, "sameAs" => get_field('personal_location_born_wikipedia_url', $post_id)] : null;
            $homeLocation = $homeLocationName ? ["@type" => "Place", "name" => $homeLocationName, "sameAs" => get_field('personal_current_residence_wikipedia_url', $post_id)] : null;

            $schema = [
                "@context" => "https://schema.org",
                "@type" => "Person",
                "@id" => get_the_permalink($post_id),
                "url" => get_the_permalink($post_id),
                "name" => get_the_title($post_id),
                "image" => get_the_post_thumbnail_url($post_id),
                "birthPlace" => $birthPlace,
                "homeLocation" => $homeLocation,
                "description" => $bio,
                "gender" => get_field('personal_gender', $post_id),      
                "alumniOf" => $alumniOf,
                "sameAs" => array_values(array_filter([
                    get_field('social_profiles_facebook', $post_id),
                    get_field('social_profiles_twitter', $post_id),
                    get_field('social_profiles_instagram', $post_id),
                    get_field('social_profiles_linkedin', $post_id),
                    get_field('social_profiles_tiktok', $post_id),
                    get_field('social_profiles_wikipedia', $post_id),
                    get_field('social_profiles_imdb', $post_id),
                    get_field('social_profiles_muckrack_url', $post_id),
                    get_field('social_profiles_soundcloud', $post_id),
                    get_field('social_profiles_amazon_author', $post_id),
                    get_field('social_profiles_audible', $post_id),
                    get_field('social_profiles_github', $post_id),
                    get_field('social_profiles_crunchbase', $post_id),
                    get_field('social_profiles_f6s', $post_id),
                    get_field('social_profiles_the_org', $post_id),
                    get_field('social_profiles_threads', $post_id),
                    get_field('social_profiles_linktree', $post_id),
                    get_field('social_profiles_pinterest', $post_id),
                    get_field('social_profiles_quora', $post_id),
                    get_field('social_profiles_reddit', $post_id),
                    get_field('social_profiles_youtube', $post_id),
                    get_field('social_profiles_angel_list', $post_id)
                ]))
            ];

        } elseif (in_array('organization', $category_slugs)) {
            $schema = [
                "@context" => "https://schema.org",
                "@type" => "Organization",
                "name" => get_field('organization_name', $post_id),
                "url" => get_field('organization_url', $post_id),
                "legalName" => get_field('legal_name', $post_id),
                "naics" => get_field('naics', $post_id),
                "email" => get_field('email', $post_id),
                "description" => json_encode(get_field('description', $post_id)),
                "alternateName" => get_field('alternate_name', $post_id),
                "logo" => get_field('logo', $post_id),
                "award" => get_field('award', $post_id),
                "brand" => get_field('brand', $post_id),
                "contactPoint" => array_filter([
                    "@type" => "ContactPoint",
                    "contactType" => get_field('contact_type', $post_id),
                    "email" => get_field('contact_email', $post_id),
                    "telephone" => get_field('contact_telephone', $post_id)
                ]),
                "founder" => array_filter([
                    "@type" => "Person",
                    "@id" => get_field('founder_id', $post_id) ?: null,
                    "url" => get_field('founder_url', $post_id) ?: null,
                    "name" => get_field('founder_name', $post_id) ?: null
                ]),
                "foundingDate" => get_field('founding_date', $post_id),
                "numberOfEmployees" => get_field('number_of_employees', $post_id),
                "seeks" => get_field('seeks', $post_id),
                "sameAs" => array_values(array_filter([get_field('same_as', $post_id)])),
                "address" => array_filter([
                    "@type" => "PostalAddress",
                    "streetAddress" => get_field('street_address', $post_id),
                    "addressLocality" => get_field('address_locality', $post_id),
                    "addressRegion" => get_field('address_region', $post_id),
                    "postalCode" => get_field('postal_code', $post_id),
                    "addressCountry" => get_field('address_country', $post_id)
                ])
            ];
        }

        // Remove any null or empty values from the schema
        $schema = array_filter($schema, function($value) {
            return ($value !== null && $value !== []);
        });

        // Convert the schema array to JSON and store it in the 'schema_markup' ACF field
        $schema_json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        update_field('schema_markup', $schema_json, $post_id);                       
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\generate_schema_markup function is already declared", true);
}

/**
 * Enforces setting a featured image before publishing a profile post.
 */
if (!function_exists(__NAMESPACE__ . '\\enforce_featured_image_with_jquery')) {
    function enforce_featured_image_with_jquery() {
        global $typenow;
        if ($typenow == 'profile') {  // Replace 'profile' with your actual custom post type
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#publish').click(function(e) {
                        if ($('#set-post-thumbnail img').length == 0) {
                            e.preventDefault();
                           alert('You must set a featured image (company logo or headshot) before publishing this profile.');
                            return false;
                        }
                    });
                });
            </script><?php
        }
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\enforce_featured_image_with_jquery function is already declared", true);
}

/**
 * Custom quick edit functionality for removing fields from the quick edit screen for the 'profile' post type.
 */
if (!function_exists(__NAMESPACE__ . '\\custom_quick_edit_javascript')) {
    function custom_quick_edit_javascript() {
        global $current_screen;

        // Check if on the edit screen of the 'profile' post type and if the user has the 'verified_profile_manager' role
        if ($current_screen->id == 'edit-profile' && is_profile_manager(true)) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Remove the author field from quick edit
                    $('#the-list').on('click', '.editinline', function() {
                        setTimeout(function() {
                            var editRow = $('#edit-' + $('.inline-edit-row').attr('id').substring(5));
                            if (editRow.find('.inline-edit-author').length > 0) {
                                editRow.find('.inline-edit-author').remove();
                            }
                        }, 50);
                    });

                    // Remove the category field from quick edit
                    $('#the-list').on('click', '.editinline', function() {
                        setTimeout(function() {
                            var editRow = $('#edit-' + $('.inline-edit-row').attr('id').substring(5));
                            if (editRow.find('.inline-edit-categories').length > 0) {
                                editRow.find('.inline-edit-categories').remove();
                            }

                            // Hide the slug edit section
                            $('.inline-edit-row').find('label:has(.title:contains("Slug"))').remove();
                            // Hide the tags edit section
                            $('.inline-edit-row').find('.inline-edit-tags').remove();
                        }, 50);
                    });

                    // Remove the slug edit section
                    $('#edit-slug-box').remove();
                });
            </script><?php
        }
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\custom_quick_edit_javascript function is already declared", true);
}

/**
 * Removes content editor from the 'profile' custom post type.
 */
if (!function_exists(__NAMESPACE__ . '\\remove_content_editor_from_profile_cpt')) {
    function remove_content_editor_from_profile_cpt() {
        // Remove 'editor' support from 'profile' CPT
        remove_post_type_support('profile', 'editor');
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\remove_content_editor_from_profile_cpt function is already declared", true);
}

/**
 * Displays associated profiles in the Publish meta box when editing a 'profile' post.
 *
 * @param WP_Post $post The current post object.
 */
if (!function_exists(__NAMESPACE__ . '\\wpadmin_profile_display_associated_profiles')) {
    function wpadmin_profile_display_associated_profiles($post) {
        if (!current_user_can('administrator')) return;
        global $wpdb;

        // Ensure this function runs only for the custom post type 'profile'
        if ('profile' !== $post->post_type) {
            return;
        }

        $profile_id = $post->ID;
        $meta_key_like = 'unclaimed_profiles_%_profile';

        // Custom SQL query
        $sql = "
            SELECT DISTINCT user_id 
            FROM {$wpdb->usermeta} 
            WHERE meta_key LIKE %s 
            AND meta_value = %d
        ";

        // Prepare and execute the query
        $prepared_sql = $wpdb->prepare($sql, $meta_key_like, $profile_id);
        $user_ids = $wpdb->get_col($prepared_sql);

        // Start building the HTML output
        $html = '<div id="major-publishing-actions" style="overflow:hidden;">';
        $html .= '<div id="publishing-action" style="text-align:left !important;float:left !important;">';
        $html .= '<div><b>Associated with:</b><br />';

        // Check if users found
        if (empty($user_ids)) {
            $html .= "No association";
        } else {
            foreach ($user_ids as $user_id) {
                $user = get_userdata($user_id);
                $html .= esc_html($user->display_name) . ' | <a target=_blank href="' . esc_url(get_edit_user_link($user_id)) . '">edit</a><br />';
            }
        }

        $html .= '</div>';
        $html .= '<div><br /><b>Claimed By:</b><br />';

        // Get the author data
        $author_id = $post->post_author;
        $author = get_userdata($author_id);

        // Check if author exists
        if ($author) {
            $author_name = esc_html($author->display_name);
            $edit_link = esc_url(get_edit_user_link($author_id));
            $html .= "{$author_name} | <a href='{$edit_link}' target='_blank'>edit</a>";
        } else {
            $html .= "No author";
        }

        $html .= '</div>';
        $html .= '</div></div>';

        // Output the HTML
        echo $html;
    }
} else {
    write_log("⚠️ Warning: " . __NAMESPACE__ . "\\wpadmin_profile_display_associated_profiles function is already declared", true);
}