<?php namespace smp_core_podcast_functionality;

function display_settings_snippets() {

    // if this is an AJAX request, bail immediately—don’t print any CSS/HTML!
    if ( defined('DOING_AJAX') && DOING_AJAX ) {
        return;
    }

    acf_form_head();
    ?>
    <style>
        .panel-settings-snippets {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            margin-bottom: 20px;
            background-color: #f7f7f7;
            padding: 10px 15px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            font-size: 14px;
        }

        .panel-settings-snippets .panel-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .panel-settings-snippets .panel-content {
            padding: 10px 0;
        }

        .panel-settings-snippets ul {
            list-style: none;
            padding-left: 0;
            margin: 0;
        }

        .panel-settings-snippets li {
            padding: 1px 0;
            font-size: 12px;
            color: #888;
        }

        .panel-settings-snippets input[type="checkbox"] {
            margin-right: 10px;
        }

        .panel-settings-snippets label {
            font-size: 13px;
            color: #555;
        }

        .panel-settings-snippets small {
            display: block;
            margin-top: 3px;
            color: #777;
            font-size: 12px;
        }

        .snippet-item {
            margin-bottom: 12px;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #dcdcdc;
            background-color: #fff;
        }
    </style>

    <!-- Snippets Status Panel -->
    <div class="panel panel-settings-snippets">
        <h2 class="panel-title">Snippets</h2>
        <div class="panel-content">

            <h3>Active Snippets:</h3>
            <div style="margin-left: 15px; color: green;">
                <?php
                // Initialize an array to store active snippets
                $active_snippets = [];
                $settings_snippets = get_settings_snippets();

                // Iterate through the snippets and check which ones are active
                foreach ( $settings_snippets as $snippet ) {
                    $is_enabled = get_option( $snippet['id'], false );
                    if ( $is_enabled ) {
                        $active_snippets[] = $snippet['name'];
                    }
                }

                // Display active snippets or a message if none are found
                if ( ! empty( $active_snippets ) ) {
                    echo '<ul>';
                    foreach ( $active_snippets as $snippet_name ) {
                        echo '<li>&#x2705; ' . esc_html( $snippet_name ) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p>No active snippets found.</p>';
                }
                ?>
            </div>

            <!-- Snippet Actions and Status -->
            <div style="margin-bottom: 15px;">
                <h3>Available Snippets:</h3>
                <div style="margin-left: 15px;">
                    <?php
                    // Loop through all snippets and display them with a checkbox
                    foreach ( $settings_snippets as $snippet ) {
                        $is_enabled = get_option( $snippet['id'], false );
                        $checked    = $is_enabled ? 'checked' : '';
                        $info_html  = isset( $snippet['info'] ) ? $snippet['info'] : '';

                        echo '<div class="snippet-item">';
                        echo '  <input
                                    type="checkbox"
                                    id="' . esc_attr( $snippet['id'] ) . '"
                                    onclick="window.' . __NAMESPACE__ . '.toggleSnippet(\'' . esc_js( $snippet['id'] ) . '\')"
                                    ' . $checked . '
                                >';
                        echo '  <label for="' . esc_attr( $snippet['id'] ) . '">';
                        echo        esc_html( $snippet['name'] ) . ' – <em>' . esc_html( $snippet['description'] ) . '</em><br>';
                        echo '    <small><strong>Details:</strong><br>' . wp_kses_post( $info_html ) . '</small>';
                        echo '  </label>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>

        </div>
    </div>
    <?php
}


/*
    function display_settings_snippets() {
  

          // if this is an AJAX request, bail immediately—don’t print any CSS/HTML!
    if ( defined('DOING_AJAX') && DOING_AJAX ) {
        return;
    }


            acf_form_head();
      
        ?>
    

    <style>
        .panel-settings-snippets {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            margin-bottom: 20px;
            background-color: #f7f7f7;
            padding: 10px 15px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            font-size: 14px;
        }

        .panel-settings-snippets .panel-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .panel-settings-snippets .panel-content {
            padding: 10px 0;
        }

        .panel-settings-snippets ul {
            list-style: none;
            padding-left: 0;
            margin: 0;
        }

        .panel-settings-snippets li {
            padding: 1px 0;
            font-size: 12px;
            color: #888;
        }

        .panel-settings-snippets input[type="checkbox"] {
            margin-right: 10px;
        }

        .panel-settings-snippets label {
            font-size: 13px;
            color: #555;
        }

        .panel-settings-snippets small {
            display: block;
            margin-top: 3px;
            color: #777;
            font-size: 12px;
        }

        .snippet-item {
            margin-bottom: 12px;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #dcdcdc;
            background-color: #fff;
        }
    </style>
        <!-- Snippets Status Panel -->
        <div class="panel panel-settings-snippets">
            <h2 class="panel-title">Snippets</h2>
            <div class="panel-content">
                <h3>Active Snippets:</h3>
                <div style="margin-left: 15px; color: green;">
                    <?php
                    // Initialize an array to store active snippets
                    $active_snippets = [];
                    $settings_snippets = get_settings_snippets();
    
                    // Iterate through the snippets and check which ones are active
                    foreach ($settings_snippets as $snippet) {

                        
                        $is_enabled = get_option($snippet['id'], false);
                        if ($is_enabled) {
                            $active_snippets[] = $snippet['name']; // Add active snippet names to the array
                        }
                    }
    
                        // Display active snippets or a message if none are found
                if (!empty($active_snippets)) {
                    echo "<ul>";
                    foreach ($active_snippets as $snippet_name) {
                        echo "<li>&#x2705; {$snippet_name}</li>";
                    }
                    echo "</ul>";
                } else {
                    echo "<p>No active snippets found.</p>";
                }
                    ?>
                </div>
    
                <!-- Snippet Actions and Status -->
                <div style="margin-bottom: 15px;">
                    <h3>Available Snippets:</h3>
                    <div style="margin-left: 15px;">
                        <?php
// Loop through all snippets and display them with a checkbox
foreach ($settings_snippets as $snippet) {
    // Get the current state of the option from the database
    $is_enabled = get_option($snippet['id'], false);

    // Debug printout to screen
  //  echo "<pre>Debug: Option '{$snippet['id']}' current value: " . var_export($is_enabled, true) . "</pre>";

    // Determine if the checkbox should be checked
    $checked = $is_enabled ? 'checked' : '';

    // Display the checkbox and label with the info field included
    echo '<div style="color: #555; margin-bottom: 10px;">
    <input 
        type="checkbox" 
        id="' . $snippet['id'] . '" 
        onclick="window.' . __NAMESPACE__ . '.toggleSnippet(\'' . $snippet['id'] . '\')" 
        ' . $checked . '>
    <label for="' . $snippet['id'] . '">
        ' . $snippet['name'] . ' - <em>' . $snippet['description'] . '</em><br>
        <small><strong>Details:</strong><br>' . $snippet['info'] . '</small>
    </label>
</div>';


}

                        ?>
                    </div>
                </div>
            </div>
        </div>



  
    
    <?php }




*/







    
?>