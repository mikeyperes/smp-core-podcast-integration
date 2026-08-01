<?php

declare( strict_types=1 );

$root = dirname( __DIR__ );
$checks = 0;
$failures = [];

defined( 'SMP_PODCAST_PLUGIN_ROOT' ) || define( 'SMP_PODCAST_PLUGIN_ROOT', $root );
defined( 'SMP_PODCAST_PLUGIN_FILE' ) || define( 'SMP_PODCAST_PLUGIN_FILE', $root . '/smp-core-podcast-integration.php' );

function check( bool $passed, string $label, string $detail = '' ): void {
    global $checks, $failures;
    $checks++;
    if ( $passed ) {
        echo "PASS {$label}\n";
        return;
    }
    $failures[] = $label . ( '' !== $detail ? ': ' . $detail : '' );
    echo "FAIL {$label}" . ( '' !== $detail ? " - {$detail}" : '' ) . "\n";
}

$GLOBALS['test_options'] = [];
$GLOBALS['test_fields'] = [];
$GLOBALS['test_shortcodes'] = [];
$GLOBALS['test_post_types'] = [];
$GLOBALS['test_post_meta_keys'] = [];
$GLOBALS['test_post_meta_values'] = [];
$GLOBALS['test_powerpress'] = [];
$GLOBALS['test_attachment_urls'] = [];
$GLOBALS['test_thumbnail_sizes'] = [];
$GLOBALS['test_posts'] = [];

final class WP_Query {
    /** @param array<string,mixed> $query_vars */
    public function __construct( private array $query_vars = [] ) {}

    public function get( string $key ): mixed {
        return $this->query_vars[ $key ] ?? null;
    }

    public function set( string $key, mixed $value ): void {
        $this->query_vars[ $key ] = $value;
    }
}

function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?: '' ); }
function sanitize_title( string $value ): string { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?: '' ), '-' ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return esc_html( $value ); }
function esc_url( mixed $value ): string { return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: ''; }
function esc_url_raw( mixed $value ): string { return esc_url( $value ); }
function wp_kses_post( mixed $value ): string { return (string) $value; }
function wp_strip_all_tags( mixed $value ): string { return strip_tags( (string) $value ); }
function wp_http_validate_url( mixed $value ): string|false { return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : false; }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( string $path = '' ): string { return 'https://example.test/' . ltrim( $path, '/' ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['test_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = false ): bool { unset( $autoload ); $GLOBALS['test_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['test_options'][ $key ] ); return true; }
function shortcode_atts( array $defaults, array $atts, string $tag = '' ): array { unset( $tag ); return array_merge( $defaults, array_intersect_key( $atts, $defaults ) ); }
function add_shortcode( string $tag, callable $callback ): void { $GLOBALS['test_shortcodes'][ $tag ] = $callback; }
function shortcode_exists( string $tag ): bool { return isset( $GLOBALS['test_shortcodes'][ $tag ] ); }
function get_the_ID(): int { return 101; }
function get_field( string $field, mixed $context = null, bool $format = true ): mixed { unset( $format ); $key = (string) $context . ':' . $field; return $GLOBALS['test_fields'][ $key ] ?? null; }
function get_post( int $post_id ): ?object { return $GLOBALS['test_posts'][ $post_id ] ?? ( $post_id > 0 ? (object) [ 'ID' => $post_id, 'post_author' => 1, 'post_type' => 'post' ] : null ); }
function get_post_type( int $post_id ): string|false { return $GLOBALS['test_post_types'][ $post_id ] ?? false; }
function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool { return 'post' === $meta_type && ! empty( $GLOBALS['test_post_meta_keys'][ $object_id ][ $meta_key ] ); }
function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed { unset( $single ); return $GLOBALS['test_post_meta_values'][ $post_id ][ $key ] ?? ''; }
function get_the_title( mixed $post_id = 0 ): string { return 'Object ' . (int) ( is_object( $post_id ) ? $post_id->ID : $post_id ); }
function get_permalink( int $post_id ): string { return 'https://example.test/object-' . $post_id . '/'; }
function get_userdata( int $user_id ): ?object { return $user_id > 0 ? (object) [ 'ID' => $user_id, 'display_name' => 'User ' . $user_id, 'user_email' => '', 'user_url' => '' ] : null; }
function get_users( array $args = [] ): array { unset( $args ); return []; }
function get_author_posts_url( int $user_id ): string { return 'https://example.test/author/' . $user_id . '/'; }
function get_avatar( int $id, int $size = 96, string $default = '', string $alt = '' ): string { unset( $default ); return '<img data-id="' . $id . '" width="' . $size . '" alt="' . esc_attr( $alt ) . '">'; }
function get_the_post_thumbnail( int $id, mixed $size = 'thumbnail', array $attrs = [] ): string { unset( $size, $attrs ); return '<img data-post="' . $id . '">'; }
function get_the_post_thumbnail_url( int $id, mixed $size = 'thumbnail' ): string|false { $GLOBALS['test_thumbnail_sizes'][ $id ] = $size; return $id > 0 ? 'https://example.test/cover-' . $id . '.jpg' : false; }
function wp_get_attachment_url( int $id ): string|false { return $GLOBALS['test_attachment_urls'][ $id ] ?? false; }
function has_post_thumbnail( int $id ): bool { return $id > 0; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { unset( $hook, $args ); return $value; }
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function is_feed(): bool { return false; }
function is_embed(): bool { return false; }
function powerpress_get_enclosure_data( int $post_id, string $feed_slug = 'podcast' ): array|false { unset( $feed_slug ); return $GLOBALS['test_powerpress'][ $post_id ] ?? false; }
function setup_postdata( object $post ): void { unset( $post ); }
function wp_reset_postdata(): void {}
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { unset( $hook, $callback, $priority, $accepted_args ); }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { unset( $hook, $callback, $priority, $accepted_args ); }
function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }
function did_action( string $hook ): int { unset( $hook ); return 0; }
function flush_rewrite_rules( bool $hard = true ): void { unset( $hard ); }

require $root . '/lib/hexa-wordpress-plugin-core/bootstrap.php';
hexa_plugin_core_register_package( 'podcast-tests', $root . '/lib/hexa-wordpress-plugin-core', [ 'minimum_version' => '1.1.9' ] );
HexaPluginCorePackageRegistry::resolve();
require $root . '/src/Support/Autoloader.php';
SMP\Podcast\Support\Autoloader::register( $root . '/src' );

$main = file_get_contents( $root . '/smp-core-podcast-integration.php' );
preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n*]+)/mi', (string) $main, $header_match );
$header_version = trim( (string) ( $header_match[1] ?? '' ) );
$file_version = trim( (string) file_get_contents( $root . '/VERSION' ) );
check( '3.1.3' === $header_version, 'plugin header reports 3.1.3', $header_version );
check( $header_version === SMP\Podcast\Config::VERSION, 'header and Config versions agree' );
check( $header_version === $file_version, 'header and VERSION file agree' );

$declared_hash = trim( (string) file_get_contents( $root . '/lib/hexa-wordpress-plugin-core/PACKAGE_HASH' ) );
$actual_hash = HexaPluginCorePackageRegistry::source_hash( $root . '/lib/hexa-wordpress-plugin-core' );
check( '1.1.9' === trim( (string) file_get_contents( $root . '/lib/hexa-wordpress-plugin-core/VERSION' ) ), 'bundled Core version is 1.1.9' );
check( hash_equals( $declared_hash, $actual_hash ), 'bundled Core source matches PACKAGE_HASH' );

$episode = SMP\Podcast\Acf\EpisodeFieldGroup::definition();
$options = SMP\Podcast\Acf\PodcastOptionsFieldGroup::definition();
check( 'group_6844c5d5cf57f' === $episode['key'], 'episode ACF group key is stable' );
check( 'group_6848b7b0247cc' === $options['key'], 'options ACF group key is stable' );
check( '@post_type' === $episode['location'][0][0]['value'], 'episode ACF location remains Core-resolvable' );
check( 'theme-options-podcast' === $options['location'][0][0]['value'], 'options ACF legacy location remains compatible' );

$field_keys = [];
$collect = static function ( array $fields ) use ( &$collect, &$field_keys ): void {
    foreach ( $fields as $field ) {
        $field_keys[] = (string) ( $field['key'] ?? '' );
        if ( is_array( $field['sub_fields'] ?? null ) ) {
            $collect( $field['sub_fields'] );
        }
    }
};
$collect( $episode['fields'] );
check( count( $field_keys ) === count( array_unique( $field_keys ) ), 'episode ACF field keys are unique' );
foreach ( [ 'field_6844c5f82ace2', 'field_6844c7776e21e', 'field_6844c7e536052', 'field_6844c7e536057' ] as $required_key ) {
    check( in_array( $required_key, $field_keys, true ), 'episode field key retained: ' . $required_key );
}

$GLOBALS['test_options'] = [];
check( 'post' === SMP\Podcast\Settings\PodcastSettings::content_type(), 'existing posts are the default content model' );
$GLOBALS['test_options']['smp_podcast_content_model'] = 'episode';
check( 'episode' === SMP\Podcast\Settings\PodcastSettings::content_type(), 'dedicated episode model can be selected' );
$GLOBALS['test_options']['smp_podcast_content_model'] = 'post';

$playback_defaults = SMP\Podcast\Settings\PlaybackSettings::defaults();
check( false === $playback_defaults['enabled'], 'persistent player is opt-in by default' );
check( true === $playback_defaults['ajax_navigation'], 'playback navigation is ready when the player is enabled' );
check( ! array_key_exists( 'ajax_while_paused', $playback_defaults ), 'paused navigation cannot be enabled in settings' );
$sanitized_playback = SMP\Podcast\Settings\PlaybackSettings::sanitize(
    [
        'enabled' => 'on',
        'ajax_navigation' => '1',
        'ajax_while_paused' => 'yes',
        'content_selector' => 'body, main',
        'excluded_paths' => "/members/*\nhttps://bad.example/path\n<script>\n/downloads/",
        'timeout_ms' => 99999,
        'transition_ms' => -5,
        'skip_back' => 1,
        'skip_forward' => 999,
    ]
);
check( true === $sanitized_playback['enabled'] && true === $sanitized_playback['ajax_navigation'], 'playback boolean settings normalize' );
check( ! array_key_exists( 'ajax_while_paused', $sanitized_playback ), 'legacy paused-navigation input is discarded' );
check( '[data-smp-ajax-root]' === $sanitized_playback['content_selector'], 'unsafe whole-body selector falls back to the content island' );
foreach ( [ 'header', 'footer', 'nav', 'main', '#page', '.site', '#wrapper', '.wrapper', 'body > main' ] as $unsafe_selector ) {
    $unsafe_result = SMP\Podcast\Settings\PlaybackSettings::sanitize( [ 'content_selector' => $unsafe_selector ] );
    check( '[data-smp-ajax-root]' === $unsafe_result['content_selector'], 'unsafe content selector is rejected: ' . $unsafe_selector );
}
$bounded_result = SMP\Podcast\Settings\PlaybackSettings::sanitize( [ 'content_selector' => 'main.site-main' ] );
check( 'main.site-main' === $bounded_result['content_selector'], 'bounded main selector remains available' );
check( "/members/*\n/downloads/" === $sanitized_playback['excluded_paths'], 'excluded paths keep only site-relative entries' );
check( 30000 === $sanitized_playback['timeout_ms'] && 0 === $sanitized_playback['transition_ms'], 'navigation timing settings stay within safety bounds' );
check( 5 === $sanitized_playback['skip_back'] && 120 === $sanitized_playback['skip_forward'], 'skip controls stay within accessible bounds' );
$saved_playback = SMP\Podcast\Settings\PlaybackSettings::save( $sanitized_playback );
check( $saved_playback === SMP\Podcast\Settings\PlaybackSettings::get(), 'playback settings save through one canonical option' );
$public_playback = SMP\Podcast\Settings\PlaybackSettings::public_config();
check( [ '/members/*', '/downloads/' ] === $public_playback['excludedPaths'], 'public playback config exposes normalized path rules' );
check( ! array_key_exists( 'ajaxWhilePaused', $public_playback ), 'public playback config exposes no paused-navigation override' );
unset( $GLOBALS['test_options'][ SMP\Podcast\Settings\PlaybackSettings::OPTION_NAME ] );

$home_interactions = new SMP\Podcast\Frontend\HomeInteractions();
$GLOBALS['test_posts'][ SMP\Podcast\Frontend\HomeInteractions::LEGACY_CUSTOM_CODE_ID ] = (object) [
    'ID' => SMP\Podcast\Frontend\HomeInteractions::LEGACY_CUSTOM_CODE_ID,
    'post_author' => 1,
    'post_type' => 'elementor_snippet',
    'post_status' => 'publish',
    'post_title' => 'Podcast Home Interactions',
];
$GLOBALS['test_post_meta_values'][ SMP\Podcast\Frontend\HomeInteractions::LEGACY_CUSTOM_CODE_ID ]['_elementor_code'] = '<style>[data-elementor-id="23095"]{display:block}.mpp-topic-chip{display:flex}</style><script>new MutationObserver(function(){});</script>';
$snippet_query = new WP_Query( [ 'post_type' => 'elementor_snippet', 'post__not_in' => [ 99 ] ] );
$home_interactions->exclude_legacy_custom_code( $snippet_query );
check(
    [ 99, SMP\Podcast\Frontend\HomeInteractions::LEGACY_CUSTOM_CODE_ID ] === $snippet_query->get( 'post__not_in' ),
    'owned home interactions retire only the matching Elementor Custom Code document'
);
$ordinary_query = new WP_Query( [ 'post_type' => 'post' ] );
$home_interactions->exclude_legacy_custom_code( $ordinary_query );
check( null === $ordinary_query->get( 'post__not_in' ), 'ordinary content queries are untouched by the Custom Code retirement' );
$GLOBALS['test_posts'][ SMP\Podcast\Frontend\HomeInteractions::LEGACY_CUSTOM_CODE_ID ]->post_title = 'Unrelated Custom Code';
$unrelated_snippet_query = new WP_Query( [ 'post_type' => 'elementor_snippet' ] );
$home_interactions->exclude_legacy_custom_code( $unrelated_snippet_query );
check( null === $unrelated_snippet_query->get( 'post__not_in' ), 'a mismatched Custom Code identity is never suppressed by numeric ID alone' );
$GLOBALS['test_posts'][ SMP\Podcast\Frontend\HomeInteractions::LEGACY_CUSTOM_CODE_ID ]->post_title = 'Podcast Home Interactions';
$protected_home_tag = $home_interactions->protect_runtime_script( '<script src="home-interactions.js"></script>', SMP\Podcast\Frontend\HomeInteractions::SCRIPT_HANDLE );
check( str_contains( $protected_home_tag, 'data-no-optimize="1"' ) && str_contains( $protected_home_tag, 'data-cfasync="false"' ), 'home interactions load before legacy body-end Custom Code and bypass script reordering' );
check( '<script src="other.js"></script>' === $home_interactions->protect_runtime_script( '<script src="other.js"></script>', 'other-handle' ), 'home interaction script protection is handle-scoped' );

$rewrite = new SMP\Podcast\Support\RewriteLifecycle();
unset( $GLOBALS['test_options']['smp_podcast_rewrite_version'], $GLOBALS['test_options']['smp_podcast_flush_rewrite_rules'] );
$rewrite->register();
check( 1 === ( $GLOBALS['test_options']['smp_podcast_flush_rewrite_rules'] ?? 0 ), 'a new release schedules one rewrite refresh' );
$rewrite->maybe_flush();
check( SMP\Podcast\Config::VERSION === ( $GLOBALS['test_options']['smp_podcast_rewrite_version'] ?? '' ), 'rewrite refresh records the current release' );
check( ! isset( $GLOBALS['test_options']['smp_podcast_flush_rewrite_rules'] ), 'rewrite refresh clears its pending flag' );

$scoped = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post_status' => 'publish' ] );
check( 'post' === $scoped['post_type'], 'post-mode queries retain the WordPress post type' );
check( 'IN' === ( $scoped['meta_query'][0]['compare_key'] ?? '' ), 'post-mode queries use one metadata-key join' );
check( 'EXISTS' === ( $scoped['meta_query'][0]['compare'] ?? '' ), 'post-mode queries require a podcast metadata marker' );
check( 6 === count( $scoped['meta_query'][0]['key'] ?? [] ), 'all podcast metadata markers participate in post-mode queries' );

$existing_meta_query = [ [ 'key' => 'season', 'value' => 2 ] ];
$combined = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'meta_query' => $existing_meta_query ] );
check( 'AND' === ( $combined['meta_query']['relation'] ?? '' ), 'existing meta queries are combined with podcast scoping' );
check( $existing_meta_query === ( $combined['meta_query'][0] ?? null ), 'existing meta query clauses are preserved' );

$GLOBALS['test_post_types'][201] = 'post';
$GLOBALS['test_post_types'][202] = 'post';
$GLOBALS['test_post_types'][203] = 'page';
$GLOBALS['test_post_meta_keys'][202]['audio_url'] = true;
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 201 ), 'unmarked regular posts are excluded from podcast operations' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 202 ), 'metadata-marked posts are included in podcast operations' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 203 ), 'non-podcast post types are excluded from podcast operations' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 201, false ), 'explicit unscoped compatibility checks accept the configured post type' );

$GLOBALS['test_options']['smp_podcast_content_model'] = 'episode';
$GLOBALS['test_post_types'][204] = 'episode';
$episode_scoped = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post_status' => 'publish' ] );
check( 'episode' === $episode_scoped['post_type'], 'episode-mode queries use the dedicated content type' );
check( ! isset( $episode_scoped['meta_query'] ), 'episode-mode queries do not require redundant metadata markers' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 204 ), 'dedicated episode posts are podcast content without markers' );
$GLOBALS['test_options']['smp_podcast_content_model'] = 'post';

$shortcodes = new SMP\Podcast\Frontend\Shortcodes();
$shortcodes->register();
$expected_tags = [ 'smp_listen_button', 'podcast_url', 'episode_fields', 'article_guests', 'podcast_hosts', 'display_single_episode_hosts', 'guest_grid', 'display_guest_profile_info', 'display_host_profile_info', 'podcast_host' ];
check( $expected_tags === SMP\Podcast\Frontend\Shortcodes::tags(), 'canonical player trigger and all nine legacy shortcode tags register' );
check( [] === array_diff( $expected_tags, array_keys( $GLOBALS['test_shortcodes'] ) ), 'all shortcode callbacks register' );

$GLOBALS['test_fields']['301:audio'] = [ 'url' => 'https://example.test/direct-301.mp3' ];
$GLOBALS['test_powerpress'][301] = [ 'url' => 'https://media.example.test/powerpress-301.mp3', 'duration' => '1:02:03' ];
$resolved_audio = SMP\Podcast\Frontend\AudioSourceResolver::resolve( 301 );
check( 'https://media.example.test/powerpress-301.mp3' === $resolved_audio['playback_url'], 'PowerPress URL has playback precedence' );
check( 'https://example.test/direct-301.mp3' === $resolved_audio['download_url'], 'direct ACF media remains the download URL' );
check( 3723 === $resolved_audio['duration_seconds'] && '1:02:03' === $resolved_audio['duration'], 'PowerPress duration normalizes for the player' );
check( 'powerpress' === $resolved_audio['source'], 'resolved audio records its playback owner' );
check( 'thumbnail' === ( $GLOBALS['test_thumbnail_sizes'][301] ?? null ), 'persistent-player cover requests the thumbnail derivative' );
$listen_button = SMP\Podcast\Frontend\ShortcodeCallbacks::listen_button( [ 'post_id' => 301, 'label' => 'Listen now', 'class' => 'episode-listen custom<script>' ] );
check( str_contains( $listen_button, 'data-smp-player-trigger' ) && str_contains( $listen_button, 'powerpress-301.mp3' ), 'listen-button shortcode emits the canonical player contract' );
check( str_contains( $listen_button, 'aria-controls="smp-podcast-player"' ) && str_contains( $listen_button, 'aria-pressed="false"' ), 'listen-button shortcode is keyboard and state accessible' );
check( ! str_contains( $listen_button, '<script>' ), 'listen-button shortcode sanitizes custom classes and labels' );

$GLOBALS['test_fields']['302:audio'] = null;
$GLOBALS['test_fields']['302:audio_url'] = 'https://cdn.example.test/direct-302.m4a';
$direct_audio = SMP\Podcast\Frontend\AudioSourceResolver::resolve( 302 );
check( 'https://cdn.example.test/direct-302.m4a' === $direct_audio['playback_url'] && 'audio' === $direct_audio['source'], 'ACF audio URL is the first fallback without PowerPress' );
$GLOBALS['test_post_meta_values'][303]['enclosure'] = "https://cdn.example.test/enclosure-303.mp3\n12345\naudio/mpeg";
$enclosure_audio = SMP\Podcast\Frontend\AudioSourceResolver::resolve( 303 );
check( 'https://cdn.example.test/enclosure-303.mp3' === $enclosure_audio['playback_url'] && 'enclosure' === $enclosure_audio['source'], 'WordPress enclosure is the final playback fallback' );

$GLOBALS['test_fields']['101:audio_url'] = 'https://cdn.example.test/audio.mp3';
check( 'https://cdn.example.test/audio.mp3' === SMP\Podcast\Frontend\ShortcodeCallbacks::episode_fields( [ 'name' => 'audio_url', 'post_id' => 101 ] ), 'top-level audio_url shortcode is not misread as a group' );
$GLOBALS['test_fields']['101:urls'] = [ 'spotify' => 'https://open.spotify.com/example' ];
check( 'https://open.spotify.com/example' === SMP\Podcast\Frontend\ShortcodeCallbacks::episode_fields( [ 'name' => 'urls_spotify', 'post_id' => 101 ] ), 'grouped URL shortcode resolves' );
$GLOBALS['test_fields']['21811:guests'] = '1';
$GLOBALS['test_post_meta_values'][21811]['guests'] = '1';
$GLOBALS['test_post_meta_values'][21811]['guests_0_guest'] = '139';
$guest_output = SMP\Podcast\Frontend\ShortcodeCallbacks::guest_profiles( [ 'post_id' => 21811 ] );
check( str_contains( $guest_output, 'class="guest-profile' ) && str_contains( $guest_output, 'User 139' ), 'legacy guest repeater renders without an active ACF field definition' );

require $root . '/src/Compatibility/legacy-functions.php';
check( function_exists( 'smp_core_podcast_functionality\\episode_fields_shortcode' ), 'legacy namespace wrapper exists' );
check( 'https://cdn.example.test/audio.mp3' === smp_core_podcast_functionality\episode_fields_shortcode( [ 'name' => 'audio_url', 'post_id' => 101 ] ), 'legacy wrapper delegates to canonical callback' );

$snippets = SMP\Podcast\Support\SnippetDefinitions::all();
check( 6 === count( $snippets ), 'six managed snippet definitions are present' );
foreach ( $snippets as $snippet ) {
    foreach ( (array) ( $snippet['test_rules'] ?? [] ) as $rule ) {
        if ( in_array( $rule['type'] ?? '', [ 'callback', 'callable' ], true ) ) {
            check( is_callable( $rule['callback'] ?? null ), 'snippet callback is callable: ' . (string) $snippet['id'] );
        }
    }
}

$banned_files = [ 'GitHub_Updater.php', 'generic-functions.php', 'function-schema.php', 'function-post-save.php', 'snippet-rss.php', 'settings-dashboard.php', 'register-acf-structure-user.php' ];
foreach ( $banned_files as $file ) {
    check( ! file_exists( $root . '/' . $file ), 'superseded flat file removed: ' . $file );
}

$source = '';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
    if ( $file instanceof SplFileInfo && $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
        $contents = (string) file_get_contents( $file->getPathname() );
        $source .= "\n" . $contents;
        $relative = substr( $file->getPathname(), strlen( $root ) + 1 );
        $namespace_ok = 1 === preg_match( '/^namespace\s+SMP\\\\Podcast(?:\\\\[A-Za-z0-9_]+)*\s*;/m', $contents );
        if ( 'src/Compatibility/legacy-functions.php' === $relative ) {
            $namespace_ok = 1 === preg_match( '/^namespace\s+smp_core_podcast_functionality\s*;/m', $contents );
        }
        check( $namespace_ok, 'source file is namespaced: ' . $relative );
    }
}
check( ! str_contains( $source, 'query_posts(' ), 'runtime does not mutate the global query with query_posts' );
check( ! str_contains( $source, 'wp_ajax_nopriv_' ), 'runtime exposes no unauthenticated admin mutation endpoint' );
check( ! str_contains( $source, 'function generate_schema_markup' ), 'podcast plugin no longer owns unrelated profile schema generation' );
$player_js = (string) file_get_contents( $root . '/assets/frontend-player.js' );
$player_css = (string) file_get_contents( $root . '/assets/frontend-player.css' );
$home_interactions_js = (string) file_get_contents( $root . '/assets/home-interactions.js' );
$home_interactions_css = (string) file_get_contents( $root . '/assets/home-interactions.css' );
check( str_contains( $player_js, "response.status !== 200" ) && str_contains( $player_js, "text/html" ), 'AJAX navigation accepts only HTTP 200 HTML documents' );
check( str_contains( $player_js, 'state.playbackActivated' ) && str_contains( $player_js, 'return !audio.paused;' ) && ! str_contains( $player_js, 'ajaxWhilePaused' ), 'navigation interception requires actively playing audio and has no paused-track override' );
check( str_contains( $player_js, "new DOMParser().parseFromString" ) && str_contains( $player_js, "roots.current.replaceWith" ), 'navigation parses full public documents and replaces only the content island' );
check( str_contains( $player_js, "runReadyTrigger(window.jQuery(root))" ), 'Elementor handlers reinitialize against the replaced content root' );
check( str_contains( $player_js, "link[rel=\"canonical\"]" ) && str_contains( $player_js, 'application/ld+json' ), 'client navigation synchronizes canonical metadata and JSON-LD' );
check( str_contains( $player_js, "window.addEventListener('popstate'" ) && str_contains( $player_js, 'history.pushState' ), 'browser history and back-forward navigation are owned by the player runtime' );
check( str_contains( $player_js, "window.location.assign" ) && str_contains( $player_js, "window.location.reload" ), 'failed or ineligible navigation has a hard-browser fallback' );
check( ! str_contains( $player_js, 'bindNavigation();' ) && ! preg_match( '/configureMediaSession\(\);\s*recordHistoryState\(\);/', $player_js ), 'page initialization does not bind or mutate navigation history' );
check( strpos( $player_js, 'beginNavigationSession();' ) > strpos( $player_js, 'loadRequiredStyles(plan.styles.missing,' ), 'history ownership begins only after navigation preflight succeeds' );
check( str_contains( $player_js, 'unsupported-inline-script' ) && str_contains( $player_js, 'missing-script-asset' ) && str_contains( $player_js, 'sanitizeImportedContent(importedRoot)' ), 'fetched executable code is rejected or stripped rather than evaluated' );
check(
    str_contains( $player_js, 'ignorableSelfRemovingScript(source)' )
    && str_contains( $player_js, 'url.origin === window.location.origin' )
    && str_contains( $player_js, 'cloudflare-static\\/email-decode'),
    'only the exact same-origin self-removing Cloudflare email decoder may be omitted from fetched script requirements'
);
check(
    str_contains( $player_js, "new RegExp('(?:^|\\\\n)//# sourceURL=' + escapedId" )
    && str_contains( $player_js, "var candidate = cleaned.replace" ),
    'localized Elementor JSON accepts only its own exact trailing WordPress sourceURL annotation'
);
check(
    str_contains( $player_js, 'JetEngineSettings' )
    && str_contains( $player_js, '/^jet-engine-frontend-js-extra$/' ),
    'JetEngine page context is accepted only from its exact JSON localization handle'
);
check( str_contains( $player_js, 'cancelPendingNavigation' ) && str_contains( $player_js, 'parkNavigationSession' ) && str_contains( $player_js, 'pendingNavigationActive' ), 'pause and terminal playback states cancel pending work and park history ownership' );
check( str_contains( $player_js, 'inline-event-handler' ) && str_contains( $player_js, 'copyAllowedAttributes' ) && str_contains( $player_js, 'managedInlineStyleId' ), 'fetched event attributes are rejected and managed assets use explicit policies' );
check( str_contains( $player_js, 'safeContentRootSelector' ) && str_contains( $player_js, 'content-root-mismatch' ) && str_contains( $player_js, 'header,footer,nav' ), 'content roots are matched and reject persistent or broad regions' );
check(
    str_contains( $player_js, 'var incomingDescriptor = findContentRoot(parsed)' )
    && str_contains( $player_js, 'trustedElementorSurfaceSelector' )
    && str_contains( $player_js, 'currentMarker === null || incomingMarker === null || currentMarker !== incomingMarker' ),
    'current and incoming roots resolve independently only across trusted Elementor surfaces or compatible explicit markers'
);
check(
    str_contains( $player_js, "key !== 'smpi-breadcrumbs'" )
    && str_contains( $player_js, "source.tagName !== 'TEMPLATE'" )
    && str_contains( $player_js, "throw unsupportedNavigation('unsafe-companion-fragment')" )
    && str_contains( $player_js, 'syncCompanionFragments(plan.companions)' ),
    'AJAX companion synchronization accepts only allowlisted inert breadcrumb templates'
);
check( str_contains( $player_js, "nodesIncludingScope(scope, '#ap-audio')" ) && str_contains( $player_js, 'enforcePlayerSingleton' ), 'legacy audio is migrated into the singleton player contract' );
check( ! str_contains( $player_js, 'admin-ajax.php' ) && ! str_contains( $player_js, 'wp-json' . '/smp' ), 'frontend navigation uses exact public URLs without a public AJAX API' );
check( str_contains( $player_css, 'position: fixed' ) && str_contains( $player_css, 'prefers-reduced-motion' ), 'fixed player CSS includes reduced-motion support' );
check(
    str_contains( $home_interactions_js, "var apiKey = '__mppHomeInteractions23128'" )
    && str_contains( $home_interactions_js, "document.addEventListener('smp:content-ready', refresh)" )
    && str_contains( $home_interactions_js, 'disconnectObserver()' ),
    'owned homepage initializer refreshes and disconnects across AJAX content lifecycles'
);
check(
    1 === substr_count( $home_interactions_js, "document.addEventListener('click', handleClick)" )
    && 1 === substr_count( $home_interactions_js, "document.addEventListener('input', handleInput)" )
    && ! str_contains( $home_interactions_js, 'dataset.mppBound' ),
    'homepage interactions use one delegated listener set instead of per-element rebinding'
);
check(
    str_contains( $home_interactions_css, '[data-elementor-id="23095"]' )
    && str_contains( $home_interactions_css, '[data-elementor-id="23094"]' ),
    'homepage interaction CSS remains scoped to the owned Elementor header and homepage IDs'
);
$bootstrap_source = (string) file_get_contents( $root . '/src/Bootstrap/Plugin.php' );
check( str_contains( $bootstrap_source, 'new HomeInteractions()' ) && str_contains( $bootstrap_source, 'new PersistentPlayer()' ) && str_contains( $bootstrap_source, 'new PlaybackSettingsController()' ), 'homepage initializer, player runtime, and authenticated settings controller are bootstrapped' );
$playback_controller_source = (string) file_get_contents( $root . '/src/Admin/PlaybackSettingsController.php' );
$admin_js = (string) file_get_contents( $root . '/assets/admin.js' );
check( str_contains( $playback_controller_source, 'smp_podcast_save_playback_settings' ) && str_contains( $playback_controller_source, 'AjaxActionRegistry' ), 'player settings use the authenticated Hexa admin-AJAX registry' );
check( str_contains( $admin_js, "action: 'smp_podcast_save_playback_settings'" ) && str_contains( $admin_js, "document.addEventListener('submit'" ), 'player settings save entirely through the admin AJAX client' );
$dashboard_source = (string) file_get_contents( $root . '/src/Admin/Dashboard.php' );
check(
    str_contains( $dashboard_source, "'return' => admin_url( 'options-general.php?page=' . Config::SETTINGS_PAGE . '&tab=settings&updated=1' )" ),
    'AJAX-rendered ACF form returns to the canonical settings tab'
);

echo "\n{$checks} checks, " . count( $failures ) . " failed.\n";
if ( $failures ) {
    foreach ( $failures as $failure ) {
        fwrite( STDERR, "- {$failure}\n" );
    }
    exit( 1 );
}
