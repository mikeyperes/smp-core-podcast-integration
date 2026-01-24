<?php 
namespace smp_core_podcast_functionality;

// Function to inject JavaScript into the footer for adding icons to external links using ::after
function add_icon_to_external_links() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Function to add icons to external links in the footer columns
            function addFooterLinkIcons() {
                // Specify the exact footer columns to target, limited to the footer area
                var footerColumns = ['.elementor-location-footer .column-1 a[target="_blank"]', '.elementor-location-footer .column-2 a[target="_blank"]', '.elementor-location-footer .column-3 a[target="_blank"]'];

                // Iterate through each selector to apply the icon
                footerColumns.forEach(function(selector) {
                    $(selector).each(function() {
                        if (!$(this).hasClass('icon-added')) {
                            $(this).addClass('icon-added'); // Mark the link to prevent multiple icons
                            // Directly add styles for the ::after pseudo-element
                            $('<style>').prop('type', 'text/css').html('a.icon-added::after { content: url("https://podcast.michaelperes.com/wp-content/uploads/2022/04/Vector-10.png") !important;opacity:1 !important;height:auto !important;background-color:transparent !important;;position: relative;top: 3px;margin-left: 10px;display: inline;}').appendTo('head');
                        }
                    });
                });
            }

            // Execute the function on document ready
            addFooterLinkIcons();
        });
    </script>
    <?php
}

// Attach this function to the WordPress wp_footer action
add_action('wp_footer', 'smp_core_podcast_functionality\add_icon_to_external_links');
?>