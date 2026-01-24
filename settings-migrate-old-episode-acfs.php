<?php namespace smp_core_podcast_functionality;

// 1) Render your ACF‑options UI (HTML + inline CSS)
add_action('acf/options_page/after', __NAMESPACE__ . '\\display_migrate_old_episode_acfs');
function display_migrate_old_episode_acfs() {
    if ( defined('DOING_AJAX') && DOING_AJAX ) return;
    acf_form_head();
    ?>
    <style>
    .panel-settings-snippets { border:1px solid #e0e0e0; border-radius:5px; margin-bottom:20px; background:#f7f7f7; padding:10px 15px; box-shadow:0 1px 3px rgba(0,0,0,0.1); font-size:14px; }
    .panel-settings-snippets .panel-title { font-size:16px; font-weight:bold; margin-bottom:10px; color:#333; }
    .panel-settings-snippets .panel-content { padding:10px 0; }
    #migrate-acf-report { width:100%; margin-top:10px; font-family:monospace; white-space:pre-wrap; height:200px; }
    </style>

    <div class="panel panel-settings-snippets">
      <h2 class="panel-title">ACF Migration</h2>
      <div class="panel-content">
        <button id="migrate-acf-btn" class="button button-primary">Migrate episode acfs to new systems</button>
        <textarea id="migrate-acf-report" readonly placeholder="Migration report…"></textarea>
      </div>
    </div>
    <?php
}

// 2) Print inline jQuery‑based script in the admin footer
add_action('admin_footer', __NAMESPACE__ . '\\print_migration_script');
function print_migration_script() {
    ?>
    <script>
    jQuery(function($){
      var $btn    = $('#migrate-acf-btn');
      var $report = $('#migrate-acf-report');
      if (!$btn.length || !$report.length) return;

      var ajaxUrl = (typeof ajaxurl !== 'undefined')
        ? ajaxurl
        : '<?php echo admin_url("admin-ajax.php"); ?>';

      $btn.on('click', function(e){
        e.preventDefault();
        $btn.prop('disabled', true);
        $report.val('Starting migration...\n');

        $.post(ajaxUrl, { action: 'migrate_episode_acfs' }, function(res){
          if (res.success) {
            $report.val(res.data);
          } else {
            $report.val('Error: ' + res.data);
          }
          $btn.prop('disabled', false);
        }, 'json');
      });
    });
    </script>
    <?php
}

// 3) AJAX handler — collect logs and return as JSON
add_action('wp_ajax_migrate_episode_acfs', __NAMESPACE__ . '\\migrate_episode_acfs');
function migrate_episode_acfs() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Insufficient permissions');
    }

    $log = [];
    $log[] = "🔍 Starting migration…";

    // 1) Query all episodes
    $args = [
      'post_type'      => 'episode',
      'posts_per_page' => -1,
      'fields'         => 'ids',
    ];
    $log[] = "Query args: " . var_export($args, true);

    $posts = get_posts($args);
    $count = count($posts);
    $log[] = "Found {$count} posts.";
    if (!$count) {
        $log[] = "‼️ No posts found – check 'post_type'.";
        return wp_send_json_success( implode("\n", $log) );
    }

    // 2) Field map
    $map = [
        'urls_spotify'      => 'spotify',
        'urls_pandora'      => 'pandora',
        'urls_soundcloud'   => 'soundcloud',
        'urls_google'       => 'google',
        'urls_deezer'       => 'deezer',
        'urls_amazon'       => 'amazon',
        'urls_apple'        => 'apple',
        'urls_iheart'       => 'iheart',
        'urls_audible'      => 'audible',
        'urls_stitcher'     => 'stitcher',
        'urls_blubrry'      => 'blubrry',
        'urls_gaana'        => 'gaana',
        'urls_podchaser'    => 'podchaser',
        'urls_jiosaavn'     => 'jiosaavn',
        'urls_tunein'       => 'tunein',
        'urls_imdb'         => 'imdb',
        'urls_anghami'      => 'anghami',
        'urls_youtube_id'   => 'youtube',
        'urls_instagram'    => 'instagram',
        'urls_listennotes'  => 'listennotes',
        'urls_rss'          => 'rss',
    ];

    // 3) Loop through each post and each mapping
    foreach ($posts as $post_id) {
        $log[] = "→ Post #{$post_id}:";
        foreach ($map as $old_key => $sub) {
            $old = get_field($old_key, $post_id);
            $log[] = "    get_field('{$old_key}'): " . var_export($old, true);
            if (empty($old)) {
                $log[] = "      ↳ empty, skipping";
                continue;
            }
            $new = get_field("urls_{$sub}", $post_id);
            $log[] = "    get_field('urls_{$sub}'): " . var_export($new, true);
            if (!empty($new)) {
                $log[] = "      ↳ already set, skipping";
                continue;
            }
            $success = update_field("urls_{$sub}", $old, $post_id);
            $log[] = "    update_field('urls_{$sub}') → " . ($success ? 'ok' : 'fail');
        }
    }

    $log[] = "✅ Migration complete.";
    wp_send_json_success( implode("\n", $log) );
}
