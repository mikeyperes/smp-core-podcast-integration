<?php namespace smp_core_podcast_functionality;

/**
 * Add our settings page under “Settings” in WP admin.
 */
function add_wp_admin_settings_page() {
    add_options_page(
        Config::$settings_page_name,            // Page title
        Config::$settings_page_name,            // Menu title
        Config::$settings_page_capability,      // Capability
        Config::$settings_page_slug,            // Menu slug
        __NAMESPACE__ . '\\display_wp_admin_settings_page' // Callback
    );
}
add_action('admin_menu', __NAMESPACE__ . '\\add_wp_admin_settings_page');


/**
 * Render the admin settings screen.
 */
function display_wp_admin_settings_page() {
    ?>
    <style>
        /* Updated Minimalist Panel Styles with Depth */
        .panel {
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background-color: #f9f9f9;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .panel-title {
            padding: 15px;
            border-bottom: 1px solid #ddd;
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            border-radius: 4px 4px 0 0;
        }
        .panel-content {
            padding: 15px;
            border-radius: 0 0 4px 4px;
        }
        .button {
            padding: 10px 16px;
            font-size: 14px;
            border-radius: 4px;
            text-decoration: none;
            color: #fff;
            background-color: #0073aa;
            border: none;
            cursor: pointer;
            display: inline-block;
            margin-right: 10px;
            transition: background-color 0.3s ease;
        }
        .button:hover {
            background-color: #005c89;
        }
        .wp-config-expandable {
            margin-top: 0;
            padding-top: 15px;
        }
        .wp-config-toggle {
            cursor: pointer;
            color: #3b82f6;
            font-size: 16px;
            margin-bottom: 10px;
            display: inline-block;
            background-color: #e0f2fe;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }
        .wp-config-toggle:hover {
            background-color: #bfdbfe;
        }
        .wp-config-content {
            display: none;
            margin-top: 15px;
            padding: 15px;
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }
        .wp-config-content ul {
            list-style: none;
            padding-left: 0;
        }
        .wp-config-content ul li {
            margin-bottom: 8px;
            padding: 8px;
            background-color: #f3f4f6;
            border-radius: 4px;
        }
        pre {
            background-color: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 13px;
            color: #1f2937;
        }
    </style>

    <div class="wrap" id="<?php echo esc_attr( __NAMESPACE__ ); ?>">
        <h1><?php echo esc_html( Config::$settings_page_display_title ); ?></h1>

        <?php display_settings_snippets(); ?>
        <?php display_migrate_old_episode_acfs(); ?>
        <?php display_audio_batch_processor(); ?>
        <?php display_host_batch_processor(); ?>
        <?php display_plugin_info()  ?>
       
        <?php /* Uncomment any panels you need:
        display_settings_system_checks();
        display_settings_check_plugins();
        display_settings_theme_checks();
        display_settings_wp_config();
        hws_ct_display_php_info();
        if ( get_option('enable_custom_rss_functionality', false) ) display_settings_rss_dashboard();
        if ( get_option('enable_comments_management', false) ) display_settings_comments_dashboard();
        hws_ct_display_plugin_info();
        */ ?>
    </div>
    <?php





}
