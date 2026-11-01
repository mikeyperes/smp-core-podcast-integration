<?php namespace smp_core_podcast_functionality;

/**
 * ============================================================================
 * Batch Processor 1: Update All Audio Files (source ACF field: "audio")
 * ============================================================================
 */

add_action( 'acf/options_page/after', __NAMESPACE__ . '\\display_audio_batch_processor' );
add_action( 'wp_ajax_run_audio_batch',      __NAMESPACE__ . '\\ajax_run_audio_batch' );

function display_audio_batch_processor() {
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
    acf_form_head();
    ?>
    <style>
        .panel-settings-snippets { border:1px solid #e0e0e0;border-radius:5px;
            margin-bottom:20px;background:#f7f7f7;padding:10px 15px;
            box-shadow:0 1px 3px rgba(0,0,0,0.1);font-size:14px;
        }
        .panel-settings-snippets .panel-title { font-size:16px;
            font-weight:bold;margin-bottom:10px;color:#333;
        }
        .panel-settings-snippets .panel-content { padding:10px 0; }
        .panel-settings-snippets input[type="button"] {
            margin-bottom:10px;
        }
        .panel-settings-snippets .report-item {
            background:#fff;border:1px solid #ccc;border-radius:3px;
            padding:8px 10px;margin-bottom:8px;font-size:13px;color:#333;
        }
        .panel-settings-snippets .report-item p { margin:4px 0; }
        .panel-settings-snippets .report-item code {
            display:block;background:#f5f5f5;padding:2px 4px;
            font-size:12px;word-break:break-all;
        }
    </style>

    <div class="panel panel-settings-snippets">
        <h2 class="panel-title">Batch Processor: Update All Audio Files</h2>
        <div class="panel-content">
            <input type="button" id="run-audio-batch" value="Run Audio Batch">
            <div id="audio-batch-output"></div>
        </div>
    </div>

    <script>
    jQuery(function($){
        $('#run-audio-batch').click(function(e){
            e.preventDefault();
            $('#audio-batch-output').empty();
            $.post( ajaxurl, {
                action: 'run_audio_batch',
                _ajax_nonce: '<?php echo wp_create_nonce("run_audio_batch_nonce"); ?>'
            }, function(response){
                $('#audio-batch-output').html(response);
            });
        });
    });
    </script>
    <?php
}




function ajax_run_audio_batch() {
    check_ajax_referer( 'run_audio_batch_nonce' );

    $posts = get_posts([
        'post_type'   => 'episode',
        'numberposts' => -1,
    ]);

    foreach ( $posts as $post ) {
        $file      = get_field( 'audio',     $post->ID );
        $file_url  = is_array( $file ) ? ( $file['url'] ?? '' ) : '';
        $did       = false;

        if ( $file_url ) {
            sync_acf_audio_to_powerpress( $post->ID );
            $did = true;
        }

        $admin_link  = admin_url( "post.php?post={$post->ID}&action=edit" );
        $title_html  = sprintf(
            '<a href="%1$s" target="_blank">%2$s</a>',
            esc_url( $admin_link ),
            esc_html( get_the_title( $post->ID ) )
        );

        if ( $did ) {
            $audio_url  = get_field( 'audio_url', $post->ID );
            $enclosure  = get_post_meta( $post->ID, 'enclosure', true );
            
            printf(
                '<div class="report-item">
                    <p>%1$s</p>
                    <p>Audio: updated to <a href="%2$s" target="_blank">%2$s</a></p>
                    <p>Audio URL: updated to <a href="%3$s" target="_blank">%3$s</a></p>
                    <p>Enclosure meta: updated</p>
                </div>',
                $title_html,
                esc_url( $file_url ),
                esc_url( $audio_url )
            );
        } else {
            printf(
                '<div class="report-item">
                    <p>%1$s</p>
                    <p>Did nothing</p>
                </div>',
                $title_html
            );
        }
    }

    wp_die();
}







function sync_acf_audio_to_powerpress( $post_ID ) {
    // Bail on autosave or non‑episode types
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || get_post_type( $post_ID ) !== 'episode' ) {
        return;
    }

    // Grab your ACF “audio” field (could be array, ID, or URL)
    $file = get_field( 'audio', $post_ID );
    if ( ! $file ) {
        return;
    }

    // Normalize to URL + attachment ID
    if ( is_array( $file ) && ! empty( $file['url'] ) ) {
        $url     = $file['url'];
        $file_id = $file['ID'] ?? 0;
    } elseif ( is_numeric( $file ) ) {
        $file_id = (int) $file;
        $url     = wp_get_attachment_url( $file_id );
    } elseif ( is_string( $file ) ) {
        $url     = $file;
        $file_id = attachment_url_to_postid( $url ) ?: 0;
    } else {
        return;
    }

    if ( ! $url ) {
        return;
    }

    // Determine file size & mime type
    if ( $file_id ) {
        $path = get_attached_file( $file_id );
        $size = file_exists( $path ) ? filesize( $path ) : 0;
        $mime = get_post_mime_type( $file_id );
    } else {
        $size = 0;
        $mime = wp_check_filetype( $url )['type'] ?? 'audio/mpeg';
    }

    // Update the ACF “audio_url” text field
    update_field( 'audio_url', $url, $post_ID );

    // Build and save the Blubrry enclosure meta
    $feed_slug      = 'podcast';
    $meta_key       = ( 'podcast' === $feed_slug ) ? 'enclosure' : "_{$feed_slug}:enclosure";
    $enclosure_data = "{$url}\n{$size}\n{$mime}";

    update_post_meta( $post_ID, $meta_key, $enclosure_data );
}
