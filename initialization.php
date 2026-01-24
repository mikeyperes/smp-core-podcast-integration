<?php
/*
Plugin Name: Scale My Podcast - Core Functionality
Description: none
Author: Michael Peres
Plugin URI: https://github.com/mikeyperes/smp-core-podcast-integration
Version: 2.1
Text Domain: smp-core-podcast-integration
Domain Path: /languages
Author URI: https://scalemypodcast.com
GitHub Plugin URI: https://github.com/mikeyperes/smp-core-podcast-integration
GitHub Branch: main 
XXXRequires Plugins: hws-base-tools, advanced-custom-fields-pro

*/          
namespace smp_core_podcast_functionality;

 
// Ensure this file is being included by a parent fileI
defined('ABSPATH') or die('No script kiddies please!');

// Generic functions import
include_once("generic-functions.php");
include_once("GitHub_Updater.php");
hws_import_tool('generic-functions.php');
hws_alias_namespace_functions('hws_base_tools', 'smp_core_podcast_functionality');
    

/*
// only in wp-admin, prevent WP from trying to flush zlib's buffer at shutdown
add_action( 'admin_init', function() {
    remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
}, 0 );

*/


// Define constants
class Config {
    public static $plugin_name = "Scale My Podcast - Core Functionality";
    public static $plugin_starter_file = "initialization.php";

    public static $settings_page_name = "Scale My Podcast - Settings";
    public static $settings_page_capability = "manage_options";
    public static $settings_page_slug = "smp_core_podcast_functionality";
    public static $settings_page_display_title = "Scale My Podcast - Settings";
    

    // Add this method to return the GitHub config dynamically
    public static function get_github_config() {
        return array(
            'slug' => plugin_basename(__FILE__), // Plugin slug
            'proper_folder_name' => 'smp-core-podcast-integration', // Proper folder name
            'api_url' => 'https://api.github.com/repos/mikeyperes/smp-core-podcast-integration', // GitHub API URL
            'raw_url' => 'https://raw.github.com/mikeyperes/smp-core-podcast-integration/main', // Raw GitHub URL
            'github_url' => 'https://github.com/mikeyperes/smp-core-podcast-integration', // GitHub repository URL
            'zip_url' => 'https://github.com/mikeyperes/smp-core-podcast-integration/archive/main.zip', // Zip URL for the latest version
            'sslverify' => true, // SSL verification for the download
            'requires' => '5.0', // Minimum required WordPress version
            'tested' => '1.1', // Tested up to WordPress version
            'readme' => 'README.md', // Readme file for version checking
            'access_token' => '', // Access token if required
        );
    }
}


// Always loaded on every admin page:
if (true||is_admin() ) {
    // Only remove the shutdown hook on our settings page:
    if ( isset( $_GET['page'] ) && $_GET['page'] === Config::$settings_page_slug ) {
        remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
    }

    // Temporarily disabled - WP_GitHub_Updater class not found
    /*
    hws_import_tool('GitHub_Updater.php', 'WP_GitHub_Updater');
    // Automatically imports the class into your current namespace
    hws_alias_namespace_functions('hws_base_tools', 'smp_core_podcast_functionality');


    /**
 * Initialize GitHub Updater only after plugins have loaded and i18n is ready.
 */
    /*
add_action( 'admin_init', function() {
    $updater = new WP_GitHub_Updater( Config::get_github_config() );

    // if you still want your "force‐update‐check" debug hook:
    if ( isset( $_GET['force-update-check'] ) ) {
        wp_clean_update_cache();
        set_site_transient( 'update_plugins', null );
        wp_update_plugins();
        error_log( 'WP_GitHub_Updater: Forced plugin update check triggered.' );
    }
} );
    */




 // require ACF Pro OR ACF Pro Temp
 $required_plugins = [
    ["hws-base-tools/initialization.php"],  // Updated to match actual folder name
    // ["smp-verified-profiles/initialization.php"], // Optional
    [
        'advanced-custom-fields-pro/acf.php',
 'advanced-custom-fields-pro-temp/acf.php'

    ],
    ["powerpress/powerpress.php"]
   /// [  ]

];


// Usage in your plugin bootstrap:
list( $ok, $error ) = check_required_plugins( $required_plugins );
if ( ! $ok ) {
    // show notice on the dashboard
    add_action( 'admin_notices', function() use ( $error ) {
        echo '<div class="notice notice-error"><p><strong>'
           . esc_html( Config::$plugin_name )
           . '</strong> ' . esc_html( $error ) . '</p></div>';
    } );

    return;
}





}











// Hook to acf/init to ensure ACF is initialized before running any ACF-related code
add_action('acf/init', function() {



    // Register ACF Fields
    
    //include_once("register-acf-structure-user.php");

    include_once("register-acf-structure-post.php");
    include_once("register-acf-structure-theme-options.php");
            include_once("register-cpt-episode.php");


    // ✅ IMPORTANT: register options + field group BEFORE any get_field() reads
    if ( function_exists(__NAMESPACE__ . '\register_theme_options') ) {
        register_theme_options();
    }

    // ✅ Now reads will work (fields/options exist)
    register_episode_custom_post_type();


 
    include_once("shortcodes.php");



    //register_user_acf_fields(); 



 

    
 

}, 20);



add_action('init', function() { 

   
    include_once("function-schema.php"); 

    include_once("settings-dashboard.php");
    
    include_once("settings-dashboard-snippets.php");
    include_once("settings-dashboard-event-handling.php");
    
    
    include_once("settings-migrate-old-episode-acfs.php");
    include_once("settings-dashboard-update-audio-files.php");
    include_once("settings-dashboard-update-default-hosts.php");
        include_once("settings-dashboard-plugin-info.php");
    
     
    include_once("snippet-footer-link-icon.php");
    include_once("snippet-rss.php");
    include_once("snippet-powerpress-post-functionality.php");
    include_once("snippet-post-functionality.php");
    
    
    include_once("function-acf-theme-options-save.php");
    
    
    include_once("snippet-theme-options-functionality.php");
    
activate_snippets("admin");
activate_snippets("non_admin");





}, 20);




function get_settings_snippets()
{
    $settings_snippets = [
        
  

        [
            'id' => 'enable_powerpress_post_functionality',
            'name' => 'enable_powerpress_post_functionality',
            'description' => '',
            'info' => 'test',
            'function' => 'enable_powerpress_post_functionality'
        ],
        [
            'id' => 'enable_rss',
            'name' => 'enable_rss',
            'description' => '',
            'info' => 'test',
            'function' => 'enable_rss'
        ],    
        [
            'id' => 'register_theme_options',
            'name' => 'register_theme_options',
            'description' => '',
            'info' => display_acf_structure('group_6848b7b0247cc'),
            'function' => 'register_theme_options'
        ],
        [
            'id' => 'enable_post_functionality',
            'name' => 'enable_post_functionality',
            'description' => '',
            'info' => '',
            'function' => 'enable_post_functionality'
        ],
               [
            'id' => 'regsiter_acf_post_podcast',
            'name' => 'regsiter_acf_post_podcast',
            'description' => '',
            'info' => '',
            'function' => 'regsiter_acf_post_podcast'
        ]
  
  
  
  


        
        
    ];

    // Ensure closure results are handled
    foreach ($settings_snippets as &$snippet) {
        if (is_callable($snippet['info'])) {
            $snippet['info'] = $snippet['info'](); // Execute closure and replace it with the returned value
        }
    }

    return $settings_snippets;
}






 


/**
 * At admin init, register a shutdown handler that logs
 * the current output-buffer level and handlers via write_log().
 */
/*
add_action( 'admin_init', function() {
    register_shutdown_function( function() {
        $level    = ob_get_level();
        $handlers = ob_list_handlers();

        write_log( "🔍 shutdown: ob_get_level() = {$level}", true );
        write_log( "🔍 shutdown: handlers = " . implode( ', ', $handlers ), true );
    } );
} );
*/
?>
