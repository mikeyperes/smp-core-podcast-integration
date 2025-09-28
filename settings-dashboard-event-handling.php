<?php namespace smp_core_podcast_functionality;

// Hook to load custom JavaScript in wp-admin head
add_action('admin_head',  __NAMESPACE__ . '\\activate_listeners');
add_action('wp_ajax_'.__NAMESPACE__.'_execute_function',  __NAMESPACE__ . '\\handle_execute_function_ajax');
add_action('wp_ajax_nopriv_'.__NAMESPACE__.'_execute_function',  __NAMESPACE__ . '\\handle_execute_function_ajax');  // For non-logged in users (optional)
add_action('wp_ajax_'.__NAMESPACE__.'_modify_wp_config_constants',  __NAMESPACE__ . '\\modify_wp_config_constants_handler');
add_action('wp_ajax_'.__NAMESPACE__.'_toggle_snippet',  __NAMESPACE__ . '\\toggle_snippet');


function activate_listeners()
{
      // don’t output this JS during AJAX either
      if ( defined('DOING_AJAX') && DOING_AJAX ) {
 //       return;
    }?>
 <script>



// assets/js/hws-base-tools-listeners.js
;(function($){
  'use strict';

  var ns = '<?php echo __NAMESPACE__; ?>';
  window[ns] = window[ns] || {};

  window[ns].toggleSnippet = function(snippetId) {
    // sanity checks
    console.groupCollapsed("toggleSnippet debug");
    console.log("↪ snippetId:", snippetId);
    var $el = $('#'+snippetId);
    if ( ! $el.length ) {
      console.warn("⚠️ No element found with that ID!");
    }
    var isChecked = $el.prop('checked');
    console.log("↪ isChecked:", isChecked);

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'json',                // force JSON parse
      data: {
        action: ns + '_toggle_snippet',
        snippet_id: snippetId,
        enable:    isChecked
      },
      beforeSend: function(jqXHR, settings){
        console.log("→ AJAX payload:", settings.data);
      },
      success: function(response) {
        console.log("← success response:", response);
        if (response.success) {
          alert("✔️ " + response.data);
        } else {
          alert("❌ Error: " + response.data);
        }
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.group("← AJAX error");
        console.error("Status:", textStatus);
        console.error("Thrown:", errorThrown);
        console.error("Response text:", jqXHR.responseText);
        console.groupEnd();
        alert('AJAX error; see console for details.');
      },
      complete: function() {
        console.groupEnd();
      }
    });
  };

})(jQuery);















  
    jQuery(document).ready(function($) {


          // Handle "Toggle Auto Updates" button click
    // Handle "Toggle Auto Updates" button click
    $('#xxxxhws-base-tools .modify-snippet-via-button').on('click', function() {
        var snippetId = $(this).data('snippet-id');
        var action = $(this).data('action');
        
     
        // Now you can directly use snippetId without conditional checks
        alert(action + " | Snippet ID: " + snippetId);
  

        // Do nothing if snippetId is not set (invalid constant)
        if (snippetId === null) {
            return;
        }

        // Toggle the checkbox state based on the action (enable or disable)
        var checkbox = $('#' + snippetId);
        var isChecked = (action === 'enable');
        checkbox.prop('checked', isChecked);

        // Trigger the toggleSnippet function to update the setting
        window.smp_core_podcast_functionality.toggleSnippet(snippetId);
    
    });
});

 





</script>






<?php }

    
function handle_execute_function_ajax() {
    write_log("entered handle_execute_function_ajax", true);


    // Verify if the method parameter is passed and is not empty
    if (isset($_POST['method']) && !empty($_POST['method'])) {
        $method_name = sanitize_text_field($_POST['method']);
        write_log("Method name passed: " . $method_name, true);
        $variable = "";
        if(isset($_POST['variable']))
        $variable = $_POST['variable'];
        // Determine the correct namespace
        $namespace = 'smp_core_podcast_functionality';
        $fully_qualified_function_name = $namespace . '\\' . $method_name;

        // Get the state if passed
        $state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : null;

        // Check if the function exists with the namespace
        if (function_exists($fully_qualified_function_name)) {
            // Execute the function with both the setting and state

            if($method_name == "toggle_php_ini_value")
            $response = call_user_func($fully_qualified_function_name,$variable, $state);
            else
            $response = call_user_func($fully_qualified_function_name, $state);
        
            // Send a success response with the result of the function execution
            wp_send_json_success($response);
        } else {
            write_log("The function does not exist: " . $fully_qualified_function_name, true);
            wp_send_json_error('The function does not exist.');
        }
    } else {
        wp_send_json_error('No method name provided.');
    }

    wp_die();  // This is required to properly terminate the script when doing AJAX in WordPress
}




if (!function_exists(__NAMESPACE__.'\toggle_snippet')) {
    function toggle_snippet() {
        $settings_snippets = get_settings_snippets();

        // Retrieve the snippet ID and the enable/disable state from the AJAX request
        $snippet_id = sanitize_text_field($_POST['snippet_id']);
        $enable = filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN);

        write_log("Toggle snippet called with ID: {$snippet_id}, enable: " . ($enable ? 'true' : 'false'));

        // Find the corresponding snippet and function
        foreach ($settings_snippets as $snippet) {
            if ($snippet['id'] === $snippet_id) {
                // Get the current value from the database
                $current_value = get_option($snippet_id);
                write_log("Current value of '{$snippet_id}': " . var_export($current_value, true));

                // Ensure both current and new values are booleans for accurate comparison
                $current_value_bool = filter_var($current_value, FILTER_VALIDATE_BOOLEAN);

                // Only update if the value has actually changed
                if ($current_value_bool !== $enable) {
                    write_log("Attempting to update '{$snippet_id}' to " . ($enable ? 'true' : 'false'));

                    // Attempt the update
                    $updated = update_option($snippet_id, $enable);

                    // Log the result of the update attempt
                    if ($updated) {
                        write_log("Option '{$snippet_id}' updated successfully.");
                        wp_send_json_success("Option '{$snippet_id}' updated successfully.");
                    } else {
                        global $wpdb;
                        $db_error = $wpdb->last_error;
                        write_log("Failed to update option '{$snippet_id}'. Database error: {$db_error}");
                        wp_send_json_error("Failed to update option '{$snippet_id}'. Database error: {$db_error}");
                    }
                } else {
                    write_log("No update required for '{$snippet_id}'. Current value is the same as the new value.");
                    wp_send_json_error("No update required for '{$snippet_id}'. Current value is the same.");
                }

                exit; // Stop further processing once the correct snippet is found
            }
        }

        write_log("Invalid snippet ID: {$snippet_id}");
        wp_send_json_error("Invalid snippet ID: {$snippet_id}");

        wp_die(); // Ensure proper termination of the script
    }
} else write_log("Warning: hws_base_tools/toggle_snippet function is already declared", true);






/**
 * Register our AJAX handler.
 */
add_action( 'wp_ajax_execute_function', __NAMESPACE__ . '\\execute_function' );
/**
 * Handle the `execute_function` AJAX call with debug logging.
 */
function execute_function() {
    write_log( 'execute_function() called' );

    // Capability check
    if ( ! current_user_can( 'manage_options' ) ) {
        write_log( 'Permission denied for user ' . get_current_user_id() );
        wp_send_json_error( 'Insufficient permissions.' );
    }

    // Read & sanitize inputs
    $method   = isset( $_POST['method'] )   ? sanitize_text_field( wp_unslash($_POST['method']) )   : '';
    $setting  = isset( $_POST['setting'] )  ? sanitize_text_field( wp_unslash($_POST['setting']) )  : '';
    $state    = isset( $_POST['state'] )    ? sanitize_text_field( wp_unslash($_POST['state']) )    : '';
    $variable = isset( $_POST['variable'] ) ? sanitize_text_field( wp_unslash($_POST['variable']) ) : '';

    write_log( compact('method','setting','state','variable') );

    // Build the full function name
    $func = __NAMESPACE__ . '\\' . $method;
    if ( ! function_exists( $func ) ) {
        write_log( "Function not found: $func" ,true);
        wp_send_json_error( "Function “{$method}” not found." );
    }

    // Invoke
    try {
        $result = call_user_func( $func, $state, $variable );
        write_log( [ 'result' => $result ]  ,true);
        wp_send_json_success( $result );
    }
    catch ( \Throwable $e ) {
        write_log( 'Exception: ' . $e->getMessage() );
        wp_send_json_error( $e->getMessage() );
    }
}
?>