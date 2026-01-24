<?php namespace smp_core_podcast_functionality;

// Enqueue this function on the 'acf/save_post' action, specifically when saving the theme options page.
add_action('acf/save_post', 'smp_core_podcast_functionality\update_acf_fields_on_save', 20);
function update_acf_fields_on_save($post_id) {

    // Check if it's the correct options page
    if($post_id !== 'options') {
        return;
    }

    // Get the user ID from the 'default_host' field
    $user_id = get_field('default_host', 'option');

    // Check if user_id is successfully retrieved
    if (!$user_id) {
        return;
    }

    // User data
    $user_data = get_userdata($user_id);
  // Update fields with user data
    if ($user_data) {

    // Get the URL of the user's avatar
$profile_photo_url = \get_avatar_url($user_id);


// Update the ACF field 'profile_photo' with the avatar URL
update_field('host_photo_url', $profile_photo_url, 'option');
 

$user_profile_url = \home_url('/author/' . $user_data->user_nicename);


// Update the ACF field 'host_url' with the user's website URL
update_field('host_url', $user_profile_url, 'option');
    // Update other fields using their 'name' property
    update_field('host_name', $user_data->display_name, 'option');
    update_field('host_facebook', get_user_meta($user_id, 'host_facebook', true), 'option');  // Replace with actual meta keys if different
    update_field('host_x', get_user_meta($user_id, 'host_x', true), 'option');
    update_field('host_instagram', get_user_meta($user_id, 'host_instagram', true), 'option');
    update_field('host_tiktok', get_user_meta($user_id, 'host_tiktok', true), 'option');
    update_field('host_imdb', get_user_meta($user_id, 'host_imdb', true), 'option');
    update_field('host_website', $user_data->user_url, 'option');
    update_field('host_crunchbase', get_user_meta($user_id, 'host_crunchbase', true), 'option');
    update_field('host_bio', $user_data->description, 'option');

   
    }
}
?>
