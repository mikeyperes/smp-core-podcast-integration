<?php namespace smp_core_podcast_functionality;
/*
create a shortcode called display_guest_profile_info on single page. 

On the single.php page there is a custom field called 

guests - repeater 
guest - user returns user id 

get the guest (user)
socials - group 
Facebook
Edit Duplicate Move Delete
facebook
Text 
2
LinkedIn
Edit Duplicate Move Delete
linkedin
Text
3
X
Edit Duplicate Move Delete
x
Text
4
YouTube
Edit Duplicate Move Delete
youtube
Text
5
Instagram
Edit Duplicate Move Delete
instagram
Text
6
SoundCloud
Edit Duplicate Move Delete
soundcloud
Text
7
TikTok
Edit Duplicate Move Delete
tiktok


write a short code that gets the guests from that post and then displays their basic information including all there socials. 

for each ACF . check if it exists, if it does not , hide the enitre seciton (respective label and value)
*/

add_shortcode('display_guest_profile_info', 'smp_core_podcast_functionality\display_guest_profile_info');
add_shortcode('display_host_profile_info', 'smp_core_podcast_functionality\display_host_profile_info');



function display_guest_profile_info() {
    // Ensure we are on a single episode page
    if (!is_singular('episode')) {
        return '';
    }

    // Get the 'guests' repeater field
    $guests = get_field('guests');
    
    if (empty($guests)) {
        return '<p>No guest information available.</p>';
    }

    // Initialize output
    $output = '<div class="guest-profile-wrapper">';

    foreach ($guests as $guest) {
        $user_id = $guest['guest']; // Get the user ID from the repeater field
        
        if (!$user_id) {
            continue; // Skip if no user ID is found
        }

        // Fetch user ACF data
        $facebook = get_field('facebook', 'user_' . $user_id);
        $linkedin = get_field('linkedin', 'user_' . $user_id);
        $x = get_field('x', 'user_' . $user_id);
        $youtube = get_field('youtube', 'user_' . $user_id);
        $instagram = get_field('instagram', 'user_' . $user_id);
        $soundcloud = get_field('soundcloud', 'user_' . $user_id);
        $tiktok = get_field('tiktok', 'user_' . $user_id);

        // Fetch user basic details
        $user_info = get_userdata($user_id);
        if (!$user_info) {
            continue; // Skip if no user is found
        }


        // Start guest profile
        $output .= '<div class="guest-profile">';
        $output .= '<h3>' . esc_html($user_info->display_name) . '</h3>';
        


            // Add profile photo
            $output .= '<div class="guest-photo">';
            $output .= get_avatar($user_id, 96); // 96 is the size of the avatar
            $output .= '</div>';


        if (!empty($user_info->user_email)) {
            $output .= '<p>Email: ' . esc_html($user_info->user_email) . '</p>';
        }
        if (!empty($user_info->user_url)) {
            $output .= '<p>Website: <a href="' . esc_url($user_info->user_url) . '" target="_blank">' . esc_html($user_info->user_url) . '</a></p>';
        }

        // Add social links if they exist
        $output .= '<div class="guest-socials">';
        if (!empty($facebook)) {
            $output .= '<p>Facebook: <a href="' . esc_url($facebook) . '" target="_blank">' . esc_html($facebook) . '</a></p>';
        }
        if (!empty($linkedin)) {
            $output .= '<p>LinkedIn: <a href="' . esc_url($linkedin) . '" target="_blank">' . esc_html($linkedin) . '</a></p>';
        }
        if (!empty($x)) {
            $output .= '<p>X: <a href="' . esc_url($x) . '" target="_blank">' . esc_html($x) . '</a></p>';
        }
        if (!empty($youtube)) {
            $output .= '<p>YouTube: <a href="' . esc_url($youtube) . '" target="_blank">' . esc_html($youtube) . '</a></p>';
        }
        if (!empty($instagram)) {
            $output .= '<p>Instagram: <a href="' . esc_url($instagram) . '" target="_blank">' . esc_html($instagram) . '</a></p>';
        }
        if (!empty($soundcloud)) {
            $output .= '<p>SoundCloud: <a href="' . esc_url($soundcloud) . '" target="_blank">' . esc_html($soundcloud) . '</a></p>';
        }
        if (!empty($tiktok)) {
            $output .= '<p>TikTok: <a href="' . esc_url($tiktok) . '" target="_blank">' . esc_html($tiktok) . '</a></p>';
        }
        $output .= '</div>'; // Close guest-socials

        $output .= '</div>'; // Close guest-profile
    }

    $output .= '</div>'; // Close guest-profile-wrapper

    return $output;
}

function display_host_profile_info() {
    // Ensure we are on a single episode page
    if (!is_singular('episode')) {
        return '';
    }

    // Get the 'guests' repeater field
    $guests = get_field('guests');
    
    if (empty($guests)) {
        return '<p>No guest information available.</p>';
    }

    // Initialize output
    $output = '<div class="guest-profile-wrapper">';

    foreach ($guests as $guest) {
        $user_id = $guest['guest']; // Get the user ID from the repeater field
        
        if (!$user_id) {
            continue; // Skip if no user ID is found
        }

        // Fetch user ACF data
        $facebook = get_field('facebook', 'user_' . $user_id);
        $linkedin = get_field('linkedin', 'user_' . $user_id);
        $x = get_field('x', 'user_' . $user_id);
        $youtube = get_field('youtube', 'user_' . $user_id);
        $instagram = get_field('instagram', 'user_' . $user_id);
        $soundcloud = get_field('soundcloud', 'user_' . $user_id);
        $tiktok = get_field('tiktok', 'user_' . $user_id);

        // Fetch user basic details
        $user_info = get_userdata($user_id);
        if (!$user_info) {
            continue; // Skip if no user is found
        }


        // Start guest profile
        $output .= '<div class="guest-profile">';
        $output .= '<h3>' . esc_html($user_info->display_name) . '</h3>';
        


            // Add profile photo
            $output .= '<div class="guest-photo">';
            $output .= get_avatar($user_id, 96); // 96 is the size of the avatar
            $output .= '</div>';


        if (!empty($user_info->user_email)) {
            $output .= '<p>Email: ' . esc_html($user_info->user_email) . '</p>';
        }
        if (!empty($user_info->user_url)) {
            $output .= '<p>Website: <a href="' . esc_url($user_info->user_url) . '" target="_blank">' . esc_html($user_info->user_url) . '</a></p>';
        }

        // Add social links if they exist
        $output .= '<div class="guest-socials">';
        if (!empty($facebook)) {
            $output .= '<p>Facebook: <a href="' . esc_url($facebook) . '" target="_blank">' . esc_html($facebook) . '</a></p>';
        }
        if (!empty($linkedin)) {
            $output .= '<p>LinkedIn: <a href="' . esc_url($linkedin) . '" target="_blank">' . esc_html($linkedin) . '</a></p>';
        }
        if (!empty($x)) {
            $output .= '<p>X: <a href="' . esc_url($x) . '" target="_blank">' . esc_html($x) . '</a></p>';
        }
        if (!empty($youtube)) {
            $output .= '<p>YouTube: <a href="' . esc_url($youtube) . '" target="_blank">' . esc_html($youtube) . '</a></p>';
        }
        if (!empty($instagram)) {
            $output .= '<p>Instagram: <a href="' . esc_url($instagram) . '" target="_blank">' . esc_html($instagram) . '</a></p>';
        }
        if (!empty($soundcloud)) {
            $output .= '<p>SoundCloud: <a href="' . esc_url($soundcloud) . '" target="_blank">' . esc_html($soundcloud) . '</a></p>';
        }
        if (!empty($tiktok)) {
            $output .= '<p>TikTok: <a href="' . esc_url($tiktok) . '" target="_blank">' . esc_html($tiktok) . '</a></p>';
        }
        $output .= '</div>'; // Close guest-socials

        $output .= '</div>'; // Close guest-profile
    }

    $output .= '</div>'; // Close guest-profile-wrapper

    return $output;
}
