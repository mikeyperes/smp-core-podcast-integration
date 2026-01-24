<?php
namespace smp_core_podcast_functionality;

function enable_powerpress_post_functionality() {
    global $pagenow;

    // Only run on the post edit screen.
    if ( 'post.php' !== $pagenow ) {
        return;
    }

    // 1) Sync PowerPress → ACF
    add_action( 'save_post', __NAMESPACE__ . '\\update_episode_data_acf_fields' );

    //this function is in settings-dashboard-update-audio-files.php : sync_acf_audio_to_powerpress()
    // 2) Sync ACF audio file → PowerPress & audio_url
    add_action( 'acf/save_post', __NAMESPACE__ . '\\sync_acf_audio_to_powerpress', 20 );

    // 3) Hook our JS into the ACF admin footer.
    add_action( 'acf/input/admin_footer', __NAMESPACE__ . '\\print_readonly_acf_js' );
}
add_action( 'admin_init', __NAMESPACE__ . '\\enable_powerpress_post_functionality' );

/**
 * 1) Read PowerPress enclosure and write all fields into ACF
 */
function update_episode_data_acf_fields( $post_ID ) {
    // Bail on autosave or non-episode types.
    if (
        ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
        get_post_type( $post_ID ) !== 'episode'
    ) {
        return $post_ID;
    }

    $episodeData = powerpress_get_enclosure_data( $post_ID );
    if ( empty( $episodeData ) ) {
        return;
    }

    // 1a) Audio URL & file
    if ( ! empty( $episodeData['url'] ) ) {
        $url = $episodeData['url'];
        update_field( 'audio_url', $url, $post_ID );

        $file_array = [
            'url'       => $url,
            'title'     => basename( $url ),
            'mime_type' => wp_check_filetype( $url )['type'],
        ];
        if ( $attach_id = attachment_url_to_postid( $url ) ) {
            $file_array['ID'] = $attach_id;
        }
        update_field( 'audio', $file_array, $post_ID );
    }

    // 1b) Duration (strip leading “0:”)
    if ( ! empty( $episodeData['duration'] ) ) {
        $parts = explode( ':', $episodeData['duration'] );
        $d = ( count( $parts ) === 3 && $parts[0] === '0' )
             ? "{$parts[1]}:{$parts[2]}"
             : $episodeData['duration'];
        update_field( 'duration', $d, $post_ID );
    }

    // 1c) Summary
    if ( ! empty( $episodeData['summary'] ) ) {
        update_field( 'summary', $episodeData['summary'], $post_ID );
    }

    // 1d) Episode number
    if ( ! empty( $episodeData['episode_no'] ) ) {
        update_field( 'number', $episodeData['episode_no'], $post_ID );
    }

    // 1e) Season
    if ( ! empty( $episodeData['season'] ) ) {
        update_field( 'season', $episodeData['season'], $post_ID );
    }

    // 1f) Flag active
    update_field( 'active', true, $post_ID );
}

/**
 * 3) Lock down certain ACF inputs
 */
function print_readonly_acf_js() {
    ?>
    <script type="text/javascript">
    jQuery(function($){
        var style = {color:'red',fontStyle:'italic'};

        $('div.acf-field[data-name="summary"] textarea')
            .prop('readonly', true)
            .css(style);

        $('div.acf-field[data-name="audio_url"] input')
            .prop('readonly', true)
            .css(style);

        $('div.acf-field[data-name="number"] input')
            .prop('readonly', true)
            .css(style);

            $('div.acf-field[data-name="duration"] input')
            .prop('readonly', true)
            .css(style);

    });
    </script>
    <?php
}
