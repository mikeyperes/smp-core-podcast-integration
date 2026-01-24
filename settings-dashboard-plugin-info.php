<?php namespace smp_core_podcast_functionality;
 use smp_core_podcast_functionality\Config;
 //use smp_core_podcast_functionality\WP_GitHub_Updater;


 function get_plugin_data() {
    // Determine the main plugin file
    $plugin_file = __FILE__; // This should point to the current file

    // Get the directory name
    $plugin_dir = dirname($plugin_file);

    // Define the main plugin file explicitly
    $main_plugin_file = $plugin_dir . '/'.Config::$plugin_starter_file; 

    // Ensure the file exists, is a regular file, and is readable
    if (!file_exists($main_plugin_file) || !is_file($main_plugin_file) || !is_readable($main_plugin_file)) {
        write_log("Main plugin file does not exist, is a directory, or is not readable: $main_plugin_file", true);
        return [
            'Name' => 'Not Available',
            'Version' => 'Not Available',
            'PluginURI' => 'Not Available',
            'Author' => 'Not Available',
            'AuthorURI' => 'Not Available',
        ];
    }

    // Fetch plugin data using WordPress' built-in function
   // $plugin_data = get_plugin_data($main_plugin_file);
$plugin_data = \get_plugin_data($main_plugin_file);

    // If any field is empty, set it to 'Not Available'
    foreach ($plugin_data as $key => $value) {
        if (empty($value)) {
            $plugin_data[$key] = 'Not Available';
        }
    }

    return $plugin_data;
}

function display_plugin_info() {
    $plugin_data = get_plugin_data();

    // ---- FIX: safely load/resolve WP_GitHub_Updater before instantiating ----
    $updater_class = null;

    // 1) If your updater is namespaced
    if (class_exists(__NAMESPACE__ . '\WP_GitHub_Updater')) {
        $updater_class = __NAMESPACE__ . '\WP_GitHub_Updater';
    }
    // 2) If your updater is global (most common)
    elseif (class_exists('\WP_GitHub_Updater')) {
        $updater_class = '\WP_GitHub_Updater';
    }
    // 3) Try to require it from common plugin locations (adjust if yours differs)
    else {
        $candidates = [
            plugin_dir_path(__FILE__) . 'class-wp-github-updater.php',
            plugin_dir_path(__FILE__) . 'includes/class-wp-github-updater.php',
            plugin_dir_path(dirname(__FILE__)) . 'class-wp-github-updater.php',
            plugin_dir_path(dirname(__FILE__)) . 'includes/class-wp-github-updater.php',
            plugin_dir_path(dirname(__FILE__)) . 'vendor/autoload.php',
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                require_once $file;
                break;
            }
        }

        if (class_exists(__NAMESPACE__ . '\WP_GitHub_Updater')) {
            $updater_class = __NAMESPACE__ . '\WP_GitHub_Updater';
        } elseif (class_exists('\WP_GitHub_Updater')) {
            $updater_class = '\WP_GitHub_Updater';
        }
    }

    // Default fallbacks so panel never fatals
    $new_version  = 'Not Available';
    $download_url = '#';

    if ($updater_class) {
        $updater      = new $updater_class(Config::get_github_config());
        $new_version  = $updater->get_new_version() ?: 'Not Available';
        $download_url = $updater->config['zip_url'] ?? '#';
    }
    // ----------------------------------------------------------------------

    preg_match('/href=["\']([^"\']+)["\']/', $plugin_data['Author'], $matches);
    $author_url  = $matches[1] ?? '#';
    $author_name = strip_tags($plugin_data['Author']);
    ?>
    <!-- Plugin Info Panel -->
    <div class="panel">
        <h2 class="panel-title">SMP Scale My Podcast</h2>
        <div class="panel-content">
            <div style="margin-bottom: 15px;">
                <strong>Plugin Name:</strong> <?php echo esc_html($plugin_data['Name']); ?>
            </div>
            <div style="margin-bottom: 15px;">
                <strong>Plugin Slug:</strong> <?php echo esc_html(dirname(plugin_basename(__FILE__))); ?>
            </div>

            <div style="margin-bottom: 15px;">
                <strong style="color: <?php echo ($plugin_data['Version'] !== $new_version) ? 'red' : 'inherit'; ?>;">
                    Current Version:
                </strong>
                <span style="color: <?php echo ($plugin_data['Version'] !== $new_version) ? 'red' : 'inherit'; ?>;">
                    <?php echo esc_html($plugin_data['Version']); ?>
                </span>
            </div>

            <div style="margin-bottom: 15px;">
                <strong>Latest Version:</strong> <?php echo esc_html($new_version); ?>
                <br />
                <small>If the latest version number does not reflect the version number on GitHub, please wait until the Git API reflects the correct version.</small>
                <?php
                $update_check_url = admin_url('update-core.php?force-check=1');
                $plugins_page_url = admin_url('plugins.php');
                echo '<br /><small><i><a href="' . esc_url($update_check_url) . '" target="_blank">Force WordPress to Perform an Update Check</a></i></small>';
                echo '<br /><small><i><a href="' . esc_url($plugins_page_url) . '" target="_blank">View Plugins Page</a></i></small>';
                ?>
            </div>

            <div style="margin-bottom: 15px;">
                <strong>Download URL:</strong>
                <a href="<?php echo esc_url($download_url); ?>" target="_blank">
                    <?php echo esc_html($download_url); ?>
                </a>
            </div>

            <div style="margin-bottom: 15px;">
                <strong>Plugin URI:</strong>
                <a href="<?php echo esc_url($plugin_data['PluginURI']); ?>" target="_blank">
                    <?php echo esc_html($plugin_data['PluginURI']); ?>
                </a>
            </div>

            <div style="margin-bottom: 15px;">
                <strong>Author:</strong>
                <a href="<?php echo esc_url($author_url); ?>" target="_blank">
                    <?php echo esc_html($author_name) . ' - ' . esc_html(parse_url($author_url, PHP_URL_HOST)); ?>
                </a>
            </div>
        </div>
    </div>
    <?php
}