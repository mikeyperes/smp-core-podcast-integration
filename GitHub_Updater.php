<?php namespace smp_verified_profiles;

// Prevent loading this file directly and/or if the class is already defined
if ( ! defined( 'ABSPATH' ) || class_exists( 'WPGitHubUpdater' ) || class_exists( 'WP_GitHub_Updater' ) )
	return;

class WP_GitHub_Updater {

	/** 
	 * GitHub Updater version
	 */
	const VERSION = 1.6;

	/**
	 * @var $config the config for the updater
	 * @access public
	 */
	var $config;

	/**
	 * @var $missing_config any config that is missing from the initialization of this instance
	 * @access public
	 */
	var $missing_config;

	/**
	 * @var $github_data temporiraly store the data fetched from GitHub, allows us to only load the data once per class instance
	 * @access private
	 */
	private $github_data;


	/**
	 * Class Constructor
	 *
	 * @since 1.0
	 * @param array $config the configuration required for the updater to work
	 * @see has_minimum_config()
	 * @return void
	 */
	public function __construct( $config = array() ) {

		$defaults = array(
			'slug' => plugin_basename( __FILE__ ),
			'proper_folder_name' => dirname( plugin_basename( __FILE__ ) ),
			'sslverify' => true,
			'access_token' => '',
		);

		$this->config = wp_parse_args( $config, $defaults );

		// if the minimum config isn't set, issue a warning and bail
		if ( ! $this->has_minimum_config() ) {
			$message = 'The GitHub Updater was initialized without the minimum required configuration, please check the config in your plugin. The following params are missing: ';
			$message .= implode( ',', $this->missing_config );
			_doing_it_wrong( __CLASS__, $message , self::VERSION );
			return;
		}

		$this->set_defaults();

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );

		// Hook into the plugin details screen
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		// set timeout
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
 
		// set sslverify for zip download
		add_filter( 'http_request_args', array( $this, 'http_request_sslverify' ), 10, 2 );
	}

	public function has_minimum_config() {

		$this->missing_config = array();

		$required_config_params = array(
			'api_url',
			'raw_url',
			'github_url',
			'zip_url',
			'requires',
			'tested',
			'readme',
		);

		foreach ( $required_config_params as $required_param ) {
			if ( empty( $this->config[$required_param] ) )
				$this->missing_config[] = $required_param;
		}

		return ( empty( $this->missing_config ) );
	}


	/**
	 * Check wether or not the transients need to be overruled and API needs to be called for every single page load
	 *
	 * @return bool overrule or not
	 */
	public function overrule_transients() {
		return ( defined( 'WP_GITHUB_FORCE_UPDATE' ) && WP_GITHUB_FORCE_UPDATE );
	}


	/**
	 * Set defaults
	 *
	 * @since 1.2
	 * @return void
	 */
	public function set_defaults() {
		if ( !empty( $this->config['access_token'] ) ) {

			// See Downloading a zipball (private repo) https://help.github.com/articles/downloading-files-from-the-command-line
			extract( parse_url( $this->config['zip_url'] ) ); // $scheme, $host, $path

			$zip_url = $scheme . '://api.github.com/repos' . $path;
			$zip_url = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $zip_url );

			$this->config['zip_url'] = $zip_url;
		}


		if ( ! isset( $this->config['new_version'] ) )
			$this->config['new_version'] = $this->get_new_version();

		if ( ! isset( $this->config['last_updated'] ) )
			$this->config['last_updated'] = $this->get_date();

		if ( ! isset( $this->config['description'] ) )
			$this->config['description'] = $this->get_description();

		$plugin_data = $this->get_plugin_data();
		if ( ! isset( $this->config['plugin_name'] ) )
			$this->config['plugin_name'] = $plugin_data['Name'];

		if ( ! isset( $this->config['version'] ) )
			$this->config['version'] = $plugin_data['Version'];

		if ( ! isset( $this->config['author'] ) )
			$this->config['author'] = $plugin_data['Author'];

		if ( ! isset( $this->config['homepage'] ) )
			$this->config['homepage'] = $plugin_data['PluginURI'];

		if ( ! isset( $this->config['readme'] ) )
			$this->config['readme'] = 'README.md';

	}


	/**
	 * Callback fn for the http_request_timeout filter
	 *
	 * @since 1.0
	 * @return int timeout value
	 */
	public function http_request_timeout() {
		return 2;
	}

	/**
	 * Callback fn for the http_request_args filter
	 *
	 * @param unknown $args
	 * @param unknown $url
	 *
	 * @return mixed
	 */
	public function http_request_sslverify( $args, $url ) {
		if ( $this->config[ 'zip_url' ] == $url )
			$args[ 'sslverify' ] = $this->config[ 'sslverify' ];

		return $args;
	}


	/**
	 * Get New Version from GitHub
	 *
	 * @since 1.0
	 * @return int $version the version number
	 */
    public function get_new_version() {
        $query = trailingslashit($this->config['raw_url']) . Config::$plugin_starter_file;
        $response = wp_remote_get($query);
    
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            write_log("WP_GitHub_Updater: Error fetching version from GitHub.<br />URL: ".$query , "true");
            return false;
        }
    
        // Extract version from the plugin header
        if (preg_match('/^Version:\s*(.*)$/mi', wp_remote_retrieve_body($response), $matches)) {
            $version = trim($matches[1]);
            set_site_transient(md5($this->config['slug']).'_new_version', $version, 60*60*6);
            return $version;
        } else {
            write_log("WP_GitHub_Updater: No version found in the file.", "true");
            return false;
        }
    }



	/**
	 * Interact with GitHub
	 *
	 * @param string $query
	 *
	 * @since 1.6
	 * @return mixed
	 */
	public function remote_get( $query ) {
		if ( ! empty( $this->config['access_token'] ) )
			$query = add_query_arg( array( 'access_token' => $this->config['access_token'] ), $query );

		$raw_response = wp_remote_get( $query, array(
			'sslverify' => $this->config['sslverify']
		) );

		return $raw_response;
	}


	/**
	 * Get GitHub Data from the specified repository
	 *
	 * @since 1.0
	 * @return array $github_data the data
	 */
	public function get_github_data() {
		if ( isset( $this->github_data ) && ! empty( $this->github_data ) ) {
			$github_data = $this->github_data;
		} else {
			$github_data = get_site_transient( md5($this->config['slug']).'_github_data' );

			if ( $this->overrule_transients() || ( ! isset( $github_data ) || ! $github_data || '' == $github_data ) ) {
				$github_data = $this->remote_get( $this->config['api_url'] );

				if ( is_wp_error( $github_data ) )
					return false;

				$github_data = json_decode( $github_data['body'] );

				// refresh every 6 hours
				set_site_transient( md5($this->config['slug']).'_github_data', $github_data, 60*60*6 );
			}

			// Store the data in this class instance for future calls
			$this->github_data = $github_data;
		}

		return $github_data;
	}


	/**
	 * Get update date
	 *
	 * @since 1.0
	 * @return string $date the date
	 */
	public function get_date() {
		$_date = $this->get_github_data();
		return ( !empty( $_date->updated_at ) ) ? date( 'Y-m-d', strtotime( $_date->updated_at ) ) : false;
	}


	/**
	 * Get plugin description
	 *
	 * @since 1.0
	 * @return string $description the description
	 */
	public function get_description() {
		$_description = $this->get_github_data();
		return ( !empty( $_description->description ) ) ? $_description->description : false;
	}


	/**
	 * Get Plugin data
	 *
	 * @since 1.0
	 * @return object $data the data
	 */
	public function get_plugin_data() {
		include_once ABSPATH.'/wp-admin/includes/plugin.php';
		$data = get_plugin_data( WP_PLUGIN_DIR.'/'.$this->config['slug'] );
		return $data;
	}


	/**
	 * Hook into the plugin update check and connect to GitHub
	 *
	 * @since 1.0
	 * @param object  $transient the plugin data transient
	 * @return object $transient updated plugin data transient
	 */
    public function api_check($transient) {
        // Log that the api_check function is called
       write_log('WP_GitHub_Updater: api_check called.',true);
    
        // Check if the transient contains the 'checked' information
        if (empty($transient->checked)) {
            write_log('WP_GitHub_Updater: No checked information in transient.',true);
            return $transient;
        }
    
        // Check the version and decide if it's new
        $update = version_compare($this->config['new_version'], $this->config['version']);
        write_log("WP_GitHub_Updater: Comparing versions. New: {$this->config['new_version']}, Current: {$this->config['version']}", true);
    
        if ($update === 1) {
            $response = new \stdClass;
            $response->new_version = $this->config['new_version'];
            $response->slug = $this->config['proper_folder_name'];
            $response->url = add_query_arg(array('access_token' => $this->config['access_token']), $this->config['github_url']);
            $response->package = $this->config['zip_url'];
    
            // Log that an update is available
            write_log("WP_GitHub_Updater: Update available. New version: {$this->config['new_version']}", true);
    
            // If response is false, don't alter the transient
            if ($response !== false) {
                $transient->response[$this->config['slug']] = $response;
            }
        } else {
            // Log that no update is found
            write_log("WP_GitHub_Updater: No update found.", true);
        }
    
        return $transient;
    }


	/**
	 * Get Plugin info
	 *
	 * @since 1.0
	 * @param bool    $false  always false
	 * @param string  $action the API function being performed
	 * @param object  $args   plugin arguments
	 * @return object $response the plugin info
	 */
	public function get_plugin_info( $false, $action, $response ) {

		// Check if this call API is for the right plugin
		if ( !isset( $response->slug ) || $response->slug != $this->config['slug'] )
			return false;

		$response->slug = $this->config['slug'];
		$response->plugin_name  = $this->config['plugin_name'];
		$response->version = $this->config['new_version'];
		$response->author = $this->config['author'];
		$response->homepage = $this->config['homepage'];
		$response->requires = $this->config['requires'];
		$response->tested = $this->config['tested'];
		$response->downloaded   = 0;
		$response->last_updated = $this->config['last_updated'];
		$response->sections = array( 'description' => $this->config['description'] );
		$response->download_link = $this->config['zip_url'];

		return $response;
	}


	/**
	 * Upgrader/Updater
	 * Move & activate the plugin, echo the update message
	 *
	 * @since 1.0
	 * @param boolean $true       always true
	 * @param mixed   $hook_extra not used
	 * @param array   $result     the result of the move
	 * @return array $result the result of the move
	 */
	public function upgrader_post_install( $true, $hook_extra, $result ) {

		global $wp_filesystem;

		// Move & Activate
		$proper_destination = WP_PLUGIN_DIR.'/'.$this->config['proper_folder_name'];
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;
		$activate = activate_plugin( WP_PLUGIN_DIR.'/'.$this->config['slug'] );

		// Output the update message
		$fail  = __( 'The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'github_plugin_updater' );
		$success = __( 'Plugin reactivated successfully.', 'github_plugin_updater' );
		echo is_wp_error( $activate ) ? $fail : $success;
		return $result;

	}
}