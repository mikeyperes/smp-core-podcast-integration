<?php namespace smp_core_podcast_functionality;

/**
 * Batch Processor 2: Update All Default Hosts (progressive reporting, no 3rd‑party calls)
 */
add_action( 'acf/options_page/after', __NAMESPACE__ . '\\display_host_batch_processor' );
add_action( 'wp_ajax_run_host_batch_item', __NAMESPACE__ . '\\ajax_run_host_batch_item' );

function display_host_batch_processor() {
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
    acf_form_head();

    $posts    = get_posts( [ 'post_type'=>'episode', 'numberposts'=>-1 ] );
    $ids_json = wp_json_encode( wp_list_pluck( $posts, 'ID' ) );
    $nonce    = wp_create_nonce( 'run_host_batch_nonce' );
    ?>
    <style>
      .report-area { 
        border:1px solid #ccc; padding:10px; 
        background:#fff; max-height:300px;
        overflow:auto;
      }
      .report-item { margin:4px 0; }
    </style>
    <div class="panel panel-settings-snippets">
      <h2 class="panel-title">Batch Processor: Update All Default Hosts</h2>
      <div class="panel-content">
        <input type="button" id="run-host-batch" value="Run Host Batch">
        <div id="host-batch-output" class="report-area"></div>
      </div>
    </div>
    <script>
    jQuery(function($){
      var posts = <?php echo $ids_json; ?>;
      $('#run-host-batch').on('click', function(e){
        e.preventDefault();
        $('#host-batch-output').empty().append('<div class="report-item">Starting…</div>');
        (function next(){
          if (!posts.length) {
            $('#host-batch-output')
              .append('<div class="report-item">✅ Done.</div>');
            return;
          }
          var id = posts.shift();
          $.post( ajaxurl, {
            action: 'run_host_batch_item',
            _ajax_nonce: '<?php echo $nonce; ?>',
            post_id: id
          }, function(html){
            $('#host-batch-output')
              .append('<div class="report-item">'+ html +'</div>');
            next();
          });
        })();
      });
    });
    </script>
    <?php
}

/**
 * AJAX handler for one post at a time.
 * Only writes the default_host when the ACF 'hosts' field is completely empty.
 */
function ajax_run_host_batch_item() {
    check_ajax_referer( 'run_host_batch_nonce' );

    $post_id    = intval( $_POST['post_id'] );
    $post_link  = admin_url( "post.php?post={$post_id}&action=edit" );
    $post_title = get_the_title( $post_id );
    $post_html  = sprintf(
        '<a href="%1$s" target="_blank">%2$s</a>',
        esc_url( $post_link ),
        esc_html( $post_title )
    );

    // Normalize default_host option
    $raw = get_field( 'default_host', 'option' );
    if      ( $raw instanceof \WP_Post ) {
        $host_id    = $raw->ID;
        $host_label = $raw->post_title;
    } elseif ( is_array( $raw ) ) {
        $host_id    = intval( $raw['value'] ?? 0 );
        $host_label = sanitize_text_field( $raw['label'] ?? '' );
    } else {
        $host_id    = intval( $raw );
        $host_label = $host_id ? get_the_title( $host_id ) : '';
    }

    // Pull current hosts (raw IDs/objects)
    $current = get_field( 'hosts', $post_id, false );

    if ( empty( $current ) && $host_id > 0 ) {
        // No host yet: set it
        update_field( 'hosts', [ $host_id ], $post_id );
        $emoji = '✅';
        $status = sprintf(
            'set to <a href="%1$s" target="_blank">%2$s</a>',
            esc_url( admin_url( "post.php?post={$host_id}&action=edit" ) ),
            esc_html( $host_label )
        );
    }
    elseif ( empty( $current ) ) {
        // No default_host configured at all
        $emoji  = '❌';
        $status = 'no default host configured';
    }
    else {
        // Already has a host: link to that profile
        $first = $current[0];
        if ( is_object( $first ) ) {
            $existing_id    = $first->ID;
            $existing_label = $first->post_title;
        } else {
            $existing_id    = intval( $first );
            $existing_label = get_the_title( $existing_id );
        }
        $emoji = '⏭️';
        $status = sprintf(
            'already <a href="%1$s" target="_blank">%2$s</a>',
            esc_url( admin_url( "post.php?post={$existing_id}&action=edit" ) ),
            esc_html( $existing_label )
        );
    }

    echo "{$emoji} {$post_html}: {$status}";
    wp_die();
}
