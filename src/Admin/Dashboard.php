<?php

namespace SMP\Podcast\Admin;

use Hexa\PluginCore\ContentTypes\ContentTypeRenderer;
use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\CoreRuntime\CoreVersion;
use Hexa\PluginCore\FieldStructures\AcfFieldGroupRenderer;
use Hexa\PluginCore\ShortcodeRegistry\ShortcodeCatalogRenderer;
use Hexa\PluginCore\SnippetRegistry\SnippetsTableRenderer;
use Hexa\PluginCore\WpAdminComponents\CoreUi;
use Hexa\PluginCore\WpAdminComponents\DynamicButton;
use Hexa\PluginCore\WpAdminTabs\HostTabsRenderer;
use SMP\Podcast\Acf\PodcastOptionsFieldGroup;
use SMP\Podcast\Bootstrap\Registries;
use SMP\Podcast\Config;
use SMP\Podcast\Settings\PlaybackSettings;
use SMP\Podcast\Settings\PodcastSettings;
use SMP\Podcast\Support\Dependencies;

final class Dashboard implements ModuleInterface {
    private static string $hook_suffix = '';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'redirect_legacy_page' ], 1 );
        add_action( 'admin_init', [ $this, 'prepare_acf_form' ], 5 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_smp_podcast_load_tab', [ $this, 'ajax_load_tab' ] );
    }

    public function register_menu(): void {
        self::$hook_suffix = (string) add_options_page(
            'Scale My Podcast',
            'Scale My Podcast',
            Config::CAPABILITY,
            Config::SETTINGS_PAGE,
            [ $this, 'render' ]
        );
    }

    public function redirect_legacy_page(): void {
        $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( Config::LEGACY_SETTINGS_PAGE !== $page ) {
            return;
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=' . Config::SETTINGS_PAGE ) );
        exit;
    }

    public function prepare_acf_form(): void {
        if ( ! $this->is_dashboard_request() || ! function_exists( 'acf_form_head' ) ) {
            return;
        }
        acf_form_head();
    }

    public function enqueue_assets( string $hook_suffix ): void {
        if ( ( self::$hook_suffix && self::$hook_suffix !== $hook_suffix ) || ! $this->is_dashboard_request() ) {
            return;
        }

        wp_enqueue_style(
            'smp-podcast-admin',
            plugin_dir_url( SMP_PODCAST_PLUGIN_FILE ) . 'assets/admin.css',
            [],
            Config::VERSION
        );
        wp_enqueue_script(
            'smp-podcast-admin',
            plugin_dir_url( SMP_PODCAST_PLUGIN_FILE ) . 'assets/admin.js',
            [],
            Config::VERSION,
            true
        );
        wp_localize_script(
            'smp-podcast-admin',
            'smpPodcastAdmin',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( Config::NONCE_ACTION ),
            ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( Config::CAPABILITY ) ) {
            wp_die( 'You do not have permission to manage podcast settings.' );
        }

        $tabs = $this->tabs();
        $active = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';
        if ( ! isset( $tabs[ $active ] ) ) {
            $active = 'overview';
        }

        CoreUi::render_assets();
        DynamicButton::render_assets();
        ?>
        <div class="wrap smp-podcast-dashboard">
            <header class="smp-podcast-header">
                <div><h1>Scale My Podcast</h1><p>Podcast content, profile relationships, feeds, shortcodes, and delivery integrations.</p></div>
                <div class="smp-podcast-version"><strong>v<?php echo esc_html( Config::VERSION ); ?></strong><span>Hexa WP Core <?php echo esc_html( CoreVersion::current() ); ?></span></div>
            </header>
            <?php
            ( new HostTabsRenderer() )->render(
                [
                    'tabs' => $tabs,
                    'active' => $active,
                    'page_url' => admin_url( 'options-general.php?page=' . Config::SETTINGS_PAGE ),
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'ajax_action' => 'smp_podcast_load_tab',
                    'nonce' => wp_create_nonce( Config::NONCE_ACTION ),
                    'nonce_field' => 'nonce',
                    'root_id' => 'smp-podcast-tabs',
                    'panel_id' => 'smp-podcast-tab-panel',
                    'label' => 'Podcast settings sections',
                    'layout' => 'sidebar',
                    'groups' => $this->groups(),
                    'sidebar_collapsible' => false,
                    'sidebar_identity' => [
                        'plugin_name' => Config::NAME,
                        'current_version' => Config::VERSION,
                        'github_url' => 'https://github.com/' . Config::GITHUB_REPO,
                        'core_name' => 'Hexa WP Core',
                        'core_version' => CoreVersion::current(),
                        'core_github_url' => 'https://github.com/mikeyperes/hexa-wordpress-plugin-core',
                    ],
                    'render_callback' => [ $this, 'render_tab' ],
                ]
            );
            ?>
        </div>
        <?php
    }

    public function ajax_load_tab(): void {
        if ( ! current_user_can( Config::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
        check_ajax_referer( Config::NONCE_ACTION, 'nonce' );

        $tabs = $this->tabs();
        $tab = isset( $_POST['tab'] ) && is_scalar( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : '';
        if ( ! isset( $tabs[ $tab ] ) ) {
            wp_send_json_error( [ 'message' => 'Unknown dashboard tab.' ], 400 );
        }

        ob_start();
        $this->render_tab( $tab );
        wp_send_json_success( [ 'tab' => $tab, 'label' => $this->tab_label( $tabs[ $tab ] ), 'html' => (string) ob_get_clean() ] );
    }

    /** @return array<string,string> */
    public function tabs(): array {
        return apply_filters(
            'smp_podcast_dashboard_tabs',
            [
                'overview' => 'Overview',
                'settings' => 'Podcast Settings',
                'playback' => 'Persistent Player',
                'custom-fields' => 'Custom Fields',
                'snippets' => 'Snippets',
                'shortcodes' => 'Shortcodes',
                'operations' => 'Operations',
                'integrations' => 'Integrations',
                'updates' => 'Updates',
            ]
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function groups(): array {
        return [
            'overview' => [ 'label' => 'Overview', 'tabs' => [ 'overview' ] ],
            'podcast' => [ 'label' => 'Podcast', 'tabs' => [ 'settings', 'playback', 'custom-fields', 'snippets', 'shortcodes' ] ],
            'operations' => [ 'label' => 'Operations', 'tabs' => [ 'operations', 'integrations' ] ],
            'system' => [ 'label' => 'System', 'tabs' => [ 'updates', 'hexa-core' ] ],
        ];
    }

    public function render_tab( string $tab ): void {
        if ( apply_filters( 'smp_podcast_render_dashboard_tab', false, $tab ) ) {
            return;
        }

        match ( $tab ) {
            'settings' => $this->render_settings(),
            'playback' => $this->render_playback(),
            'custom-fields' => $this->render_custom_fields(),
            'snippets' => $this->render_snippets(),
            'shortcodes' => $this->render_shortcodes(),
            'operations' => $this->render_operations(),
            'integrations' => $this->render_integrations(),
            'updates' => $this->render_updates(),
            default => $this->render_overview(),
        };
    }

    private function render_overview(): void {
        $content = PodcastSettings::content();
        $published = $this->content_count( 'publish' );
        $drafts = $this->content_count( 'draft' );
        $host_id = PodcastSettings::default_host_id();
        $test_url = \Hexa\PluginCore\IntegrationTests\TestEndpointController::url( [ 'host' => Config::SLUG ] );
        ?>
        <section class="smp-podcast-intro"><h2>Podcast system</h2><p>The current site uses <strong><?php echo esc_html( $content['plural'] ); ?></strong> as its podcast content source.</p></section>
        <div class="smp-podcast-metrics">
            <?php $this->metric( 'Content type', $content['post_type'], 'Fixed WordPress key' ); ?>
            <?php $this->metric( 'Published', (string) $published, $content['plural'] ); ?>
            <?php $this->metric( 'Drafts', (string) $drafts, $content['plural'] ); ?>
            <?php $this->metric( 'Default host', $host_id ? get_the_title( $host_id ) : 'Not set', $host_id ? 'Profile #' . $host_id : 'Optional' ); ?>
        </div>
        <div class="hpc-grid two">
            <article class="hpc-card"><h3>Release checks</h3><p>Run the shared read-only Hexa integration suite for Core, plugin metadata, fields, shortcodes, feed registration, and saved audio alignment.</p><a class="hpc-button hpc-external" href="<?php echo esc_url( $test_url ); ?>" target="_blank" rel="noopener noreferrer">Run Integration Tests</a></article>
            <article class="hpc-card"><h3>Public feed</h3><p><code><?php echo esc_html( home_url( '/feed/podcast/' ) ); ?></code></p><a class="hpc-button secondary hpc-external" href="<?php echo esc_url( home_url( '/feed/podcast/' ) ); ?>" target="_blank" rel="noopener noreferrer">Open Feed</a></article>
        </div>
        <?php $this->recent_content(); ?>
        <?php
    }

    private function render_settings(): void {
        $content = PodcastSettings::content();
        $host_id = PodcastSettings::default_host_id();
        $model_body = '<p>Use existing posts to keep the current podcast archive unchanged. A dedicated <code>episode</code> type is available for sites that intentionally separate podcast episodes.</p>';
        $model_body .= '<label class="hpc-field"><span>Podcast content model</span><select data-smp-content-model><option value="post"' . selected( 'post', $content['post_type'], false ) . '>Existing Posts</option><option value="episode"' . selected( 'episode', $content['post_type'], false ) . '>Dedicated Podcast Episodes</option></select></label>';
        $model_body .= '<div class="hpc-actions hpc-actions-bottom">' . DynamicButton::render( [ 'label' => 'Save Content Model', 'class' => 'smp-podcast-save-model' ] ) . '<span data-smp-model-status aria-live="polite"></span></div>';
        echo CoreUi::collapsible( [ 'title' => 'Content Model', 'body_html' => $model_body, 'meta_html' => CoreUi::pill( $content['post_type'], 'success' ), 'open' => true, 'persist_key' => 'smp-podcast-content-model' ] );

        $host_body = '<p>The host is optional. It is applied only when the default-host snippet is enabled and a new podcast item has no assigned host.</p>';
        if ( $host_id ) {
            $host_body .= '<div class="smp-podcast-host-card">' . get_the_post_thumbnail( $host_id, 'thumbnail' ) . '<div><strong>' . esc_html( get_the_title( $host_id ) ) . '</strong><span>Profile #' . (int) $host_id . '</span><div class="hpc-actions"><a class="hpc-button secondary hpc-external" href="' . esc_url( get_edit_post_link( $host_id, 'raw' ) ) . '" target="_blank" rel="noopener noreferrer">Edit</a><a class="hpc-button secondary hpc-external" href="' . esc_url( get_permalink( $host_id ) ) . '" target="_blank" rel="noopener noreferrer">View</a></div></div></div>';
        } else {
            $host_body .= '<p class="hpc-muted">No default host is selected.</p>';
        }
        echo CoreUi::collapsible( [ 'title' => 'Default Host', 'body_html' => $host_body, 'meta_html' => CoreUi::pill( $host_id ? 'Configured' : 'Optional', $host_id ? 'success' : 'warning' ), 'open' => true, 'persist_key' => 'smp-podcast-default-host' ] );

        if ( function_exists( 'acf_form' ) && function_exists( 'acf_get_field_group' ) && acf_get_field_group( PodcastOptionsFieldGroup::GROUP_KEY ) ) {
            echo '<section class="hpc-card smp-podcast-acf-form"><h3>Podcast Data</h3><p>These are the existing saved podcast options. Field names and storage keys remain unchanged.</p>';
            acf_form(
                [
                    'post_id' => PodcastSettings::OPTIONS_POST_ID,
                    'field_groups' => [ PodcastOptionsFieldGroup::GROUP_KEY ],
                    'form' => true,
                    'submit_value' => 'Save Podcast Data',
                    'updated_message' => 'Podcast data saved.',
                    'return' => admin_url( 'options-general.php?page=' . Config::SETTINGS_PAGE . '&tab=settings&updated=1' ),
                    'html_submit_button' => '<input type="submit" class="hpc-button" value="%s" />',
                ]
            );
            echo '</section>';
        }
    }

    private function render_custom_fields(): void {
        echo ( new ContentTypeRenderer() )->render( Registries::content_types(), [ 'title' => 'Podcast Content Type', 'description' => 'Hexa WP Core owns the registration state, field toggle, labels, and URL behavior for podcast content.', 'persist_prefix' => 'smp-podcast' ] );
        echo ( new AcfFieldGroupRenderer() )->render( Registries::option_fields(), [ 'title' => 'Podcast Option Fields', 'description' => 'The settings field group is separately managed through the same shared Hexa WP Core structure.', 'persist_prefix' => 'smp-podcast' ] );
    }

    private function render_playback(): void {
        $settings = PlaybackSettings::get();
        ?>
        <section class="smp-podcast-intro">
            <h2>Persistent Media Player and Playback Navigation</h2>
            <p>One fixed audio or video player can remain active while listeners browse. Navigation is enhanced only while the selected media is actively playing; every public URL remains a complete server-rendered WordPress document for search engines, sharing, and direct visits.</p>
        </section>
        <form class="smp-podcast-playback-form" data-smp-playback-form>
            <section class="hpc-card">
                <div class="smp-podcast-setting-heading"><div><h3>Runtime</h3><p>Enable the singleton player first, then choose when same-origin links use the playback-preserving navigation layer.</p></div><?php echo CoreUi::pill( $settings['enabled'] ? 'Enabled' : 'Disabled', $settings['enabled'] ? 'success' : 'warning' ); ?></div>
                <div class="smp-podcast-toggle-grid">
                    <?php $this->playback_toggle( 'enabled', 'Enable persistent podcast player', 'Renders one accessible media surface and fixed bottom control bar.', (bool) $settings['enabled'] ); ?>
                    <?php $this->playback_toggle( 'ajax_navigation', 'Enable playback-preserving navigation', 'Fetches the clicked public HTML page only while audio or video is actively playing.', (bool) $settings['ajax_navigation'] ); ?>
                    <?php $this->playback_toggle( 'video_enabled', 'Enable inline episode video', 'Loads a real episode YouTube video inside the persistent media surface after a Watch click.', (bool) $settings['video_enabled'] ); ?>
                    <?php $this->playback_toggle( 'show_mode_switch', 'Show Audio / Video switch', 'Lets visitors switch formats for the same episode without leaving the current page.', (bool) $settings['show_mode_switch'] ); ?>
                    <?php $this->playback_toggle( 'sync_media_position', 'Keep position when switching formats', 'Carries the current timestamp between audio and video when both versions are available.', (bool) $settings['sync_media_position'] ); ?>
                    <?php $this->playback_toggle( 'media_session', 'Use browser Media Session controls', 'Adds lock-screen and hardware play, pause, seek, and metadata support.', (bool) $settings['media_session'] ); ?>
                    <?php $this->playback_toggle( 'remember_preferences', 'Remember speed and volume', 'Stores only playback rate and volume in local browser storage.', (bool) $settings['remember_preferences'] ); ?>
                </div>
            </section>

            <section class="hpc-card">
                <h3>Navigation Safety</h3>
                <p>The browser fetches the exact canonical link with cookies and an HTML accept header. It swaps only a matching bounded root after scripts, styles, and Elementor readiness pass preflight. Unsupported pages, HTTP errors, non-HTML responses, missing roots, timeouts, downloads, external links, feeds, account paths, and modified clicks use normal navigation.</p>
                <div class="smp-podcast-field-grid">
                    <label class="hpc-field"><span>Preferred content root selector</span><input type="text" name="content_selector" value="<?php echo esc_attr( (string) $settings['content_selector'] ); ?>" spellcheck="false"><small>Add <code>data-smp-ajax-root</code> to the rebuilt content island. Header, footer, navigation, whole-document, and ambiguous roots are rejected.</small></label>
                    <label class="hpc-field"><span>Request timeout (milliseconds)</span><input type="number" name="timeout_ms" min="2000" max="30000" step="500" value="<?php echo (int) $settings['timeout_ms']; ?>"><small>A timeout performs an ordinary browser navigation.</small></label>
                    <label class="hpc-field"><span>Transition duration (milliseconds)</span><input type="number" name="transition_ms" min="0" max="1000" step="10" value="<?php echo (int) $settings['transition_ms']; ?>"><small>Reduced-motion preferences always disable this effect.</small></label>
                    <label class="hpc-field smp-podcast-field-wide"><span>Additional excluded paths</span><textarea name="excluded_paths" rows="8" spellcheck="false"><?php echo esc_textarea( (string) $settings['excluded_paths'] ); ?></textarea><small>One site-relative path or wildcard per line. Built-in admin, login, REST, feed, checkout, account, media, and download exclusions cannot be removed.</small></label>
                </div>
            </section>

            <section class="hpc-card">
                <h3>Player Controls</h3>
                <div class="smp-podcast-field-grid smp-podcast-skip-grid">
                    <label class="hpc-field"><span>Skip backward (seconds)</span><input type="number" name="skip_back" min="5" max="120" step="5" value="<?php echo (int) $settings['skip_back']; ?>"></label>
                    <label class="hpc-field"><span>Skip forward (seconds)</span><input type="number" name="skip_forward" min="5" max="120" step="5" value="<?php echo (int) $settings['skip_forward']; ?>"></label>
                </div>
                <div class="smp-podcast-toggle-grid">
                    <?php $this->playback_toggle( 'show_cover', 'Episode cover', 'Show the current episode artwork.', (bool) $settings['show_cover'] ); ?>
                    <?php $this->playback_toggle( 'show_skip', 'Skip controls', 'Show backward and forward buttons.', (bool) $settings['show_skip'] ); ?>
                    <?php $this->playback_toggle( 'show_rate', 'Playback speed', 'Show the playback-rate selector.', (bool) $settings['show_rate'] ); ?>
                    <?php $this->playback_toggle( 'show_volume', 'Volume controls', 'Show mute and volume controls.', (bool) $settings['show_volume'] ); ?>
                    <?php $this->playback_toggle( 'show_download', 'Download control', 'Use the direct media URL when one is available.', (bool) $settings['show_download'] ); ?>
                    <?php $this->playback_toggle( 'show_close', 'Close control', 'Allow listeners to stop and clear the active track.', (bool) $settings['show_close'] ); ?>
                </div>
            </section>

            <section class="hpc-card smp-podcast-seo-note">
                <h3>SEO contract</h3>
                <ul><li>No public AJAX endpoint or app shell is introduced.</li><li>Direct pages retain their normal HTTP status, canonical URL, metadata, JSON-LD, crawlable anchors, and complete Elementor HTML.</li><li>After safe preflight, client navigation synchronizes the document title/language, common canonical and alternate links, common SEO/social metadata, head JSON-LD, body classes, history, scroll state, and supported Elementor handlers. Other page-specific scripts or assets force a full navigation.</li></ul>
            </section>

            <div class="hpc-actions smp-podcast-playback-actions">
                <?php echo DynamicButton::render( [ 'label' => 'Save Player Settings', 'class' => 'smp-podcast-save-playback' ] ); ?>
                <span data-smp-playback-status aria-live="polite"></span>
            </div>
        </form>
        <?php
    }

    private function playback_toggle( string $name, string $label, string $description, bool $enabled ): void {
        ?>
        <label class="smp-podcast-toggle">
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"<?php checked( $enabled ); ?>>
            <span><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $description ); ?></small></span>
        </label>
        <?php
    }

    private function render_snippets(): void {
        echo ( new SnippetsTableRenderer() )->render(
            Registries::snippets(),
            [
                'title' => 'Podcast Snippets',
                'description' => 'Each optional behavior has one saved switch, one implementation owner, and an executable status test.',
                'toggle_action' => 'smp_podcast_toggle_snippet',
                'test_action' => 'smp_podcast_test_snippet',
                'nonce' => wp_create_nonce( Config::NONCE_ACTION ),
                'nonce_field' => 'nonce',
                'categories' => [
                    'content' => 'Content',
                    'custom-fields' => 'Custom Fields',
                    'feeds' => 'Feeds',
                    'integrations' => 'Integrations',
                    'display' => 'Display',
                ],
            ]
        );
    }

    private function render_shortcodes(): void {
        echo ( new ShortcodeCatalogRenderer() )->render_page(
            [
                'title' => 'Podcast Shortcodes',
                'intro' => 'Every legacy shortcode remains available, with one canonical player trigger for Elementor, the block editor, and templates.',
                'catalog' => $this->shortcode_catalog(),
                'show_author_search' => false,
                'show_post_search' => false,
                'post_type' => PodcastSettings::content_type(),
                'context' => [ 'post_id' => $this->latest_content_id(), 'user_id' => get_current_user_id() ],
            ]
        );
    }

    private function render_operations(): void {
        echo '<section class="smp-podcast-intro"><h2>Podcast Operations</h2><p>Each operation discovers the current podcast content first, then processes one item per authenticated AJAX request with a live before-and-after report.</p></section>';
        $this->operation_card( 'default-host', 'Apply Default Host', 'Assigns the selected default profile only to podcast items whose Hosts field is empty. Existing hosts are never overwritten.' );
        $this->operation_card( 'audio', 'Synchronize Audio and PowerPress', 'Aligns the ACF audio URL and PowerPress enclosure while preserving the existing serialized PowerPress settings line.' );
        $this->operation_card( 'migrate-urls', 'Migrate Legacy URL Fields', 'Copies the legacy YouTube ID field into the canonical grouped YouTube field only when the destination is empty.' );
    }

    private function render_integrations(): void {
        echo '<section class="smp-podcast-intro"><h2>Integrations</h2><p>Availability is detected at runtime. Optional integrations do not prevent the dashboard or unrelated podcast features from loading.</p></section><div class="hpc-stack">';
        foreach ( Dependencies::all() as $dependency ) {
            $active = ! empty( $dependency['active'] );
            $meta = CoreUi::pill( $active ? 'Active' : ( ! empty( $dependency['required'] ) ? 'Required' : 'Optional' ), $active ? 'success' : ( ! empty( $dependency['required'] ) ? 'danger' : 'warning' ) );
            echo CoreUi::collapsible( [ 'title' => (string) $dependency['label'], 'body_html' => '<p>' . esc_html( (string) $dependency['purpose'] ) . '</p>', 'meta_html' => $meta, 'open' => true, 'persist_key' => 'smp-podcast-integration-' . sanitize_key( (string) $dependency['label'] ) ] );
        }
        echo '</div>';
    }

    private function render_updates(): void {
        ( new \Hexa\PluginCore\PluginUpdates\UpdaterPanelRenderer( \SMP\Podcast\updater_config() ) )->render();
        ( new \Hexa\PluginCore\CorePackageUpdates\CorePackagePanelRenderer( \SMP\Podcast\core_package_config() ) )->render();
    }

    private function operation_card( string $operation, string $title, string $description ): void {
        $body = '<div class="smp-podcast-operation" data-smp-operation="' . esc_attr( $operation ) . '"><p>' . esc_html( $description ) . '</p><div class="hpc-actions">' . DynamicButton::render( [ 'label' => 'Run ' . $title, 'class' => 'smp-podcast-operation-run' ] ) . '<span data-smp-operation-status aria-live="polite"></span></div><div class="smp-podcast-operation-progress" hidden><progress max="1" value="0"></progress><span></span></div><div class="smp-podcast-operation-log" hidden aria-live="polite"></div></div>';
        echo CoreUi::collapsible( [ 'title' => $title, 'body_html' => $body, 'open' => false, 'persist_key' => 'smp-podcast-operation-' . $operation ] );
    }

    private function metric( string $label, string $value, string $note ): void {
        echo '<article><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
    }

    private function recent_content(): void {
        $posts = get_posts( PodcastSettings::scoped_query_args( [ 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => 5, 'orderby' => 'modified', 'order' => 'DESC' ] ) );
        $body = '<div class="smp-podcast-recent">';
        foreach ( $posts as $post ) {
            $body .= '<div><div><strong>' . esc_html( get_the_title( $post ) ) . '</strong><code>' . esc_html( get_permalink( $post ) ) . '</code></div><a class="hpc-button secondary hpc-external" href="' . esc_url( get_edit_post_link( $post->ID, 'raw' ) ) . '" target="_blank" rel="noopener noreferrer">Edit</a></div>';
        }
        $body .= $posts ? '</div>' : '<p>No podcast content found.</p></div>';
        echo CoreUi::collapsible( [ 'title' => 'Recently Modified', 'body_html' => $body, 'open' => true, 'persist_key' => 'smp-podcast-recent' ] );
    }

    /** @return array<int,array<string,mixed>> */
    private function shortcode_catalog(): array {
        return [
            [
                'key' => 'episode', 'title' => 'Episode Data', 'layer' => 'Post layer', 'live' => 'shortcode', 'register_file' => 'src/Frontend/ShortcodeCallbacks.php', 'access' => 'Podcast content',
                'items' => [
                    [ 'tag' => 'smp_listen_button', 'code' => '[smp_listen_button label="Listen"]', 'desc' => 'Render the canonical persistent-player trigger with resolved PowerPress or ACF audio metadata.', 'type' => 'Accessible button', 'params' => 'post_id, label, class' ],
                    [ 'tag' => 'smp_watch_button', 'code' => '[smp_watch_button label="Watch"]', 'desc' => 'Render a crawlable YouTube link that opens the validated episode video inline and carries the matching audio source.', 'type' => 'Crawlable media link', 'params' => 'post_id, label, class, element' ],
                    [ 'tag' => 'episode_fields', 'code' => '[episode_fields name="audio_url"]', 'desc' => 'Output any podcast episode field or grouped URL subfield.', 'type' => 'Text or URL', 'params' => 'name' ],
                    [ 'tag' => 'article_guests', 'code' => '[article_guests]', 'desc' => 'Display linked guest profiles for the current podcast item.', 'type' => 'HTML' ],
                    [ 'tag' => 'podcast_hosts', 'code' => '[podcast_hosts]', 'desc' => 'Display linked host profiles.', 'type' => 'HTML' ],
                    [ 'tag' => 'display_single_episode_hosts', 'code' => '[display_single_episode_hosts]', 'desc' => 'Render the current episode hosts in the legacy card layout.', 'type' => 'HTML' ],
                ],
            ],
            [
                'key' => 'podcast', 'title' => 'Podcast Settings', 'layer' => 'Publication layer', 'live' => 'shortcode', 'register_file' => 'src/Frontend/ShortcodeCallbacks.php', 'access' => 'Site-wide',
                'items' => [
                    [ 'tag' => 'podcast_url', 'code' => '[podcast_url social="spotify"]', 'desc' => 'Output a saved podcast platform URL.', 'type' => 'URL', 'params' => 'social' ],
                    [ 'tag' => 'podcast_host', 'code' => '[podcast_host id="title"]', 'desc' => 'Output the selected default host title, biography, or profile URL.', 'type' => 'Text or URL', 'params' => 'id' ],
                    [ 'tag' => 'guest_grid', 'code' => '[guest_grid]', 'desc' => 'Render the searchable podcast guest grid.', 'type' => 'HTML' ],
                ],
            ],
            [
                'key' => 'profiles', 'title' => 'Profile Displays', 'layer' => 'Author layer', 'live' => 'shortcode', 'register_file' => 'src/Frontend/ShortcodeCallbacks.php', 'access' => 'Single podcast content',
                'items' => [
                    [ 'tag' => 'display_guest_profile_info', 'code' => '[display_guest_profile_info]', 'desc' => 'Display the legacy guest profile details.', 'type' => 'HTML' ],
                    [ 'tag' => 'display_host_profile_info', 'code' => '[display_host_profile_info]', 'desc' => 'Display the legacy host profile details.', 'type' => 'HTML' ],
                ],
            ],
        ];
    }

    private function latest_content_id(): int {
        $ids = get_posts( PodcastSettings::scoped_query_args( [ 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC' ] ) );
        return $ids ? (int) $ids[0] : 0;
    }

    private function content_count( string $status ): int {
        $query = new \WP_Query(
            PodcastSettings::scoped_query_args(
                [
                    'post_status' => $status,
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                    'no_found_rows' => false,
                ]
            )
        );
        return (int) $query->found_posts;
    }

    private function tab_label( mixed $tab ): string {
        if ( is_object( $tab ) && method_exists( $tab, 'label' ) ) {
            return (string) $tab->label();
        }
        return is_scalar( $tab ) ? (string) $tab : 'Section';
    }

    private function is_dashboard_request(): bool {
        $page = isset( $_REQUEST['page'] ) && is_scalar( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['page'] ) ) : '';
        return in_array( $page, [ Config::SETTINGS_PAGE, Config::LEGACY_SETTINGS_PAGE ], true );
    }
}
