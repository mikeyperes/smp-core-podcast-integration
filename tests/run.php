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
$GLOBALS['test_post_meta_rows'] = [];
$GLOBALS['test_powerpress'] = [];
$GLOBALS['test_attachment_urls'] = [];
$GLOBALS['test_thumbnail_sizes'] = [];
$GLOBALS['test_posts'] = [];
$GLOBALS['test_registered_post_meta'] = [];
$GLOBALS['test_current_user_can'] = true;
$GLOBALS['test_powerpress_calls'] = [];
$GLOBALS['test_get_field_calls'] = [];
$GLOBALS['test_is_admin'] = false;
$GLOBALS['test_current_screen'] = null;
$GLOBALS['test_valid_nonce'] = 'valid-content-kind-nonce';
$GLOBALS['test_actions_registered'] = [];
$GLOBALS['test_filters_registered'] = [];
$GLOBALS['test_actions_fired'] = [];
$GLOBALS['test_meta_boxes'] = [];
$GLOBALS['test_revisions'] = [];
$GLOBALS['test_autosaves'] = [];
$GLOBALS['test_add_post_meta_failures'] = [];
$GLOBALS['test_add_post_meta_duplicate_once'] = [];
$GLOBALS['test_delete_post_meta_failures'] = [];

/**
 * Minimal wpdb double for the protected-meta integrity query.
 *
 * It derives duplicates from row-level fixtures rather than accepting a
 * precomputed ID list, so identical and conflicting duplicate values exercise
 * the same COUNT(*) > 1 boundary used in production.
 */
final class TestWpdb {
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';
    public bool $fail_prepare = false;
    public bool $fail_get_col = false;
    public int $prepare_calls = 0;
    public int $get_col_calls = 0;

    /** @var array<int,string> */
    public array $post_types = [];

    /** @var array<int,array{post_id:int,meta_key:string,meta_value:mixed}> */
    public array $meta_rows = [];

    private string $prepared_meta_key = '';
    private string $prepared_post_type = '';

    public function prepare( string $query, mixed ...$args ): string|false {
        $this->prepare_calls++;
        if ( $this->fail_prepare ) {
            return false;
        }

        $this->prepared_meta_key = (string) ( $args[0] ?? '' );
        $this->prepared_post_type = (string) ( $args[1] ?? '' );
        return $query;
    }

    /** @return array<int,int>|false */
    public function get_col( string $query ): array|false {
        unset( $query );
        $this->get_col_calls++;
        if ( $this->fail_get_col ) {
            $this->last_error = 'Simulated duplicate-integrity query failure.';
            return false;
        }

        $this->last_error = '';
        $counts = [];
        foreach ( $this->meta_rows as $row ) {
            $post_id = (int) ( $row['post_id'] ?? 0 );
            if ( $post_id < 1
                || $this->prepared_meta_key !== (string) ( $row['meta_key'] ?? '' )
                || $this->prepared_post_type !== ( $this->post_types[ $post_id ] ?? '' ) ) {
                continue;
            }
            $counts[ $post_id ] = ( $counts[ $post_id ] ?? 0 ) + 1;
        }

        $duplicates = [];
        foreach ( $counts as $post_id => $count ) {
            if ( $count > 1 ) {
                $duplicates[] = (int) $post_id;
            }
        }
        sort( $duplicates, SORT_NUMERIC );
        return $duplicates;
    }
}

$GLOBALS['wpdb'] = new TestWpdb();

class WP_Post {
    public int $ID;
    public int $post_author;
    public string $post_type;
    public string $post_status;
    public string $post_title;

    /** @param array<string,mixed> $data */
    public function __construct( array $data = [] ) {
        $this->ID = (int) ( $data['ID'] ?? 0 );
        $this->post_author = (int) ( $data['post_author'] ?? 1 );
        $this->post_type = (string) ( $data['post_type'] ?? 'post' );
        $this->post_status = (string) ( $data['post_status'] ?? 'draft' );
        $this->post_title = (string) ( $data['post_title'] ?? '' );
    }
}

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
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return esc_html( $value ); }
function esc_url( mixed $value ): string { return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: ''; }
function esc_url_raw( mixed $value ): string { return esc_url( $value ); }
function wp_kses_post( mixed $value ): string { return (string) $value; }
function wp_strip_all_tags( mixed $value ): string { return strip_tags( (string) $value ); }
function wp_http_validate_url( mixed $value ): string|false { return filter_var( (string) $value, FILTER_VALIDATE_URL ) ? (string) $value : false; }
function wp_parse_url( string $value ): array|false { return parse_url( $value ); }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( string $path = '' ): string { return 'https://example.test/' . ltrim( $path, '/' ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['test_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = false ): bool { unset( $autoload ); $GLOBALS['test_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['test_options'][ $key ] ); return true; }
function shortcode_atts( array $defaults, array $atts, string $tag = '' ): array { unset( $tag ); return array_merge( $defaults, array_intersect_key( $atts, $defaults ) ); }
function add_shortcode( string $tag, callable $callback ): void { $GLOBALS['test_shortcodes'][ $tag ] = $callback; }
function shortcode_exists( string $tag ): bool { return isset( $GLOBALS['test_shortcodes'][ $tag ] ); }
function get_the_ID(): int { return 101; }
function get_field( string $field, mixed $context = null, bool $format = true ): mixed { unset( $format ); $GLOBALS['test_get_field_calls'][] = [ $field, $context ]; $key = (string) $context . ':' . $field; return $GLOBALS['test_fields'][ $key ] ?? null; }
function get_post( int $post_id ): ?object { return $GLOBALS['test_posts'][ $post_id ] ?? ( $post_id > 0 ? new WP_Post( [ 'ID' => $post_id, 'post_author' => 1, 'post_type' => 'post' ] ) : null ); }
function get_post_type( int $post_id ): string|false { return $GLOBALS['test_post_types'][ $post_id ] ?? false; }
function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
    return 'post' === $meta_type
        && ( ! empty( $GLOBALS['test_post_meta_keys'][ $object_id ][ $meta_key ] )
            || ! empty( $GLOBALS['test_post_meta_rows'][ $object_id ][ $meta_key ] )
            || array_key_exists( $meta_key, $GLOBALS['test_post_meta_values'][ $object_id ] ?? [] ) );
}
function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
    if ( '' === $key ) {
        return $GLOBALS['test_post_meta_values'][ $post_id ] ?? [];
    }
    if ( array_key_exists( $key, $GLOBALS['test_post_meta_rows'][ $post_id ] ?? [] ) ) {
        $rows = array_values( $GLOBALS['test_post_meta_rows'][ $post_id ][ $key ] );
        return $single ? ( $rows[0] ?? '' ) : $rows;
    }
    if ( array_key_exists( $key, $GLOBALS['test_post_meta_values'][ $post_id ] ?? [] ) ) {
        $value = $GLOBALS['test_post_meta_values'][ $post_id ][ $key ];
        return $single ? $value : [ $value ];
    }
    return $single ? '' : [];
}
function add_post_meta( int $post_id, string $key, mixed $value, bool $unique = false ): int|false {
    $fixture_key = $post_id . ':' . $key;
    if ( ! empty( $GLOBALS['test_add_post_meta_failures'][ $fixture_key ] ) ) {
        $GLOBALS['test_add_post_meta_failures'][ $fixture_key ]--;
        return false;
    }
    $rows = get_post_meta( $post_id, $key, false );
    if ( $unique && [] !== $rows ) {
        return false;
    }
    $rows[] = $value;
    if ( ! empty( $GLOBALS['test_add_post_meta_duplicate_once'][ $fixture_key ] ) ) {
        $GLOBALS['test_add_post_meta_duplicate_once'][ $fixture_key ]--;
        $rows[] = $value;
    }
    $GLOBALS['test_post_meta_rows'][ $post_id ][ $key ] = $rows;
    $GLOBALS['test_post_meta_values'][ $post_id ][ $key ] = $rows[0];
    return count( $rows );
}
function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
    $fixture_key = $post_id . ':' . $key;
    if ( ! empty( $GLOBALS['test_delete_post_meta_failures'][ $fixture_key ] ) ) {
        $GLOBALS['test_delete_post_meta_failures'][ $fixture_key ]--;
        return false;
    }
    $existed = metadata_exists( 'post', $post_id, $key );
    if ( 3 === func_num_args() ) {
        $rows = array_values( array_filter( get_post_meta( $post_id, $key, false ), static fn( mixed $row ): bool => $row !== $value ) );
        if ( $rows ) {
            $GLOBALS['test_post_meta_rows'][ $post_id ][ $key ] = $rows;
            $GLOBALS['test_post_meta_values'][ $post_id ][ $key ] = $rows[0];
        } else {
            unset( $GLOBALS['test_post_meta_rows'][ $post_id ][ $key ], $GLOBALS['test_post_meta_values'][ $post_id ][ $key ] );
        }
        return $existed;
    }
    unset( $GLOBALS['test_post_meta_rows'][ $post_id ][ $key ], $GLOBALS['test_post_meta_values'][ $post_id ][ $key ] );
    return $existed;
}
function update_post_meta( int $post_id, string $key, mixed $value ): int|bool {
    $GLOBALS['test_post_meta_rows'][ $post_id ][ $key ] = [ $value ];
    $GLOBALS['test_post_meta_values'][ $post_id ][ $key ] = $value;
    return 1;
}
function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
    $enum = $args['show_in_rest']['schema']['enum'] ?? null;
    if ( array_key_exists( 'default', $args ) && is_array( $enum ) && ! in_array( $args['default'], $enum, true ) ) {
        return false;
    }
    $GLOBALS['test_registered_post_meta'][ $post_type ][ $meta_key ] = $args;
    return true;
}
function get_registered_meta_keys( string $object_type, string $object_subtype = '' ): array { return 'post' === $object_type ? ( $GLOBALS['test_registered_post_meta'][ $object_subtype ] ?? [] ) : []; }
function current_user_can( string $capability, mixed ...$args ): bool { unset( $capability, $args ); return (bool) $GLOBALS['test_current_user_can']; }
function wp_verify_nonce( string $nonce, string $action ): bool { unset( $action ); return hash_equals( (string) $GLOBALS['test_valid_nonce'], $nonce ); }
function wp_nonce_field( string $action, string $name ): void { unset( $action ); echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $GLOBALS['test_valid_nonce'] ) . '">'; }
function wp_is_post_revision( int $post_id ): bool { return ! empty( $GLOBALS['test_revisions'][ $post_id ] ); }
function wp_is_post_autosave( int $post_id ): bool { return ! empty( $GLOBALS['test_autosaves'][ $post_id ] ); }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string { $result = $selected == $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function add_meta_box( string $id, string $title, callable $callback, string $screen, string $context = 'advanced', string $priority = 'default' ): void { $GLOBALS['test_meta_boxes'][ $screen ][ $id ] = compact( 'title', 'callback', 'context', 'priority' ); }
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
function is_admin(): bool { return (bool) $GLOBALS['test_is_admin']; }
function get_current_screen(): ?object { return $GLOBALS['test_current_screen']; }
function wp_doing_ajax(): bool { return false; }
function is_feed(): bool { return false; }
function is_embed(): bool { return false; }
function powerpress_get_enclosure_data( int $post_id, string $feed_slug = 'podcast' ): array|false { unset( $feed_slug ); $GLOBALS['test_powerpress_calls'][] = $post_id; return $GLOBALS['test_powerpress'][ $post_id ] ?? false; }
function setup_postdata( object $post ): void { unset( $post ); }
function wp_reset_postdata(): void {}
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['test_actions_registered'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' ); }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['test_filters_registered'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' ); }
function do_action( string $hook, mixed ...$args ): void {
    $GLOBALS['test_actions_fired'][] = [ $hook, $args ];
    $callbacks = $GLOBALS['test_actions_registered'][ $hook ] ?? [];
    usort( $callbacks, static fn( array $left, array $right ): int => $left['priority'] <=> $right['priority'] );
    foreach ( $callbacks as $registration ) {
        call_user_func_array( $registration['callback'], array_slice( $args, 0, (int) $registration['accepted_args'] ) );
    }
}
function did_action( string $hook ): int { unset( $hook ); return 0; }
function flush_rewrite_rules( bool $hard = true ): void { unset( $hard ); }
function update_field( string $field, mixed $value, int $post_id ): bool { $GLOBALS['test_fields'][ $post_id . ':' . $field ] = $value; return true; }

require $root . '/lib/hexa-wordpress-plugin-core/bootstrap.php';
hexa_plugin_core_register_package( 'podcast-tests', $root . '/lib/hexa-wordpress-plugin-core', [ 'minimum_version' => '1.1.9' ] );
HexaPluginCorePackageRegistry::resolve();
require $root . '/src/Support/Autoloader.php';
SMP\Podcast\Support\Autoloader::register( $root . '/src' );

$main = file_get_contents( $root . '/smp-core-podcast-integration.php' );
preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n*]+)/mi', (string) $main, $header_match );
$header_version = trim( (string) ( $header_match[1] ?? '' ) );
$file_version = trim( (string) file_get_contents( $root . '/VERSION' ) );
check( '3.2.2' === $header_version, 'plugin header reports 3.2.2', $header_version );
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
check( true === $playback_defaults['video_enabled'], 'inline episode video is enabled by default once the player is enabled' );
check( true === $playback_defaults['show_mode_switch'] && true === $playback_defaults['sync_media_position'], 'audio/video switching defaults to visible position-preserving controls' );
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

$content_kind = new SMP\Podcast\Content\ContentKind();
$content_kind->register();
$content_kind->register_meta();
$registered_kind = $GLOBALS['test_registered_post_meta']['post'][SMP\Podcast\Content\ContentKind::META_KEY] ?? [];
check( 'string' === ( $registered_kind['type'] ?? '' ) && true === ( $registered_kind['single'] ?? false ), 'content-kind post meta is registered as one protected string' );
check( ! array_key_exists( 'default', $registered_kind ), 'content-kind registration has no implicit default' );
check( false === ( $registered_kind['show_in_rest'] ?? null ), 'content-kind classification is not writable outside its editor transition lifecycle' );
check( 'episode' === SMP\Podcast\Content\ContentKind::sanitize( ' Episode ' ), 'content-kind sanitizer accepts episode' );
check( 'article' === SMP\Podcast\Content\ContentKind::sanitize( 'ARTICLE' ), 'content-kind sanitizer accepts article' );
check( '' === SMP\Podcast\Content\ContentKind::sanitize( 'podcast' ), 'content-kind sanitizer rejects values outside the contract' );
$GLOBALS['test_current_user_can'] = true;
check( SMP\Podcast\Content\ContentKind::authorize( false, '_mpp_content_kind', 201 ), 'post editors may update the protected content-kind contract' );
$GLOBALS['test_current_user_can'] = false;
check( ! SMP\Podcast\Content\ContentKind::authorize( true, '_mpp_content_kind', 201 ), 'users who cannot edit a post cannot update its content kind' );
$GLOBALS['test_current_user_can'] = true;
check(
    isset( $GLOBALS['test_filters_registered']['acf/location/match_rule'] )
    && ! isset( $GLOBALS['test_filters_registered']['acf/prepare_field_group'] ),
    'episode field-group visibility uses ACF Pro supported location matching'
);
$content_kind->add_meta_box();
check(
    isset( $GLOBALS['test_meta_boxes']['post']['smp-podcast-content-kind'] ),
    'mixed posts expose a normal editorial content-kind authoring control'
);

$scoped = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post_status' => 'publish' ] );
check( 'post' === $scoped['post_type'], 'post-mode queries retain the WordPress post type' );
$post_scope = $scoped['meta_query'][0] ?? [];
check( 'OR' === ( $post_scope['relation'] ?? '' ), 'post-mode content scope separates explicit episodes from legacy fallback' );
check(
    '_mpp_content_kind' === ( $post_scope[0]['key'] ?? '' ) && 'episode' === ( $post_scope[0]['value'] ?? '' ),
    'explicit episode metadata is authoritative in scoped queries'
);
check(
    'AND' === ( $post_scope[1]['relation'] ?? '' )
    && 'NOT EXISTS' === ( $post_scope[1][0]['compare'] ?? '' )
    && 'IN' === ( $post_scope[1][1]['compare_key'] ?? '' ),
    'legacy podcast markers are consulted only when content kind is absent'
);
check( 5 === count( $post_scope[1][1]['key'] ?? [] ), 'only podcast-owned metadata markers participate in legacy fallback queries' );
check( ! in_array( 'profiles', $post_scope[1][1]['key'] ?? [], true ), 'editorial profile relationships never classify an article as an episode' );

$existing_meta_query = [ [ 'key' => 'season', 'value' => 2 ] ];
$combined = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'meta_query' => $existing_meta_query ] );
check( 'AND' === ( $combined['meta_query']['relation'] ?? '' ), 'existing meta queries are combined with podcast scoping' );
check( $existing_meta_query === ( $combined['meta_query'][0] ?? null ), 'existing meta query clauses are preserved' );

/** @var TestWpdb $test_wpdb */
$test_wpdb = $GLOBALS['wpdb'];
$test_wpdb->post_types = [
    601 => 'post',
    602 => 'post',
    603 => 'page',
];
$test_wpdb->meta_rows = [
    [ 'post_id' => 601, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'episode' ],
    [ 'post_id' => 601, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'episode' ],
    [ 'post_id' => 602, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'episode' ],
    [ 'post_id' => 602, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'article' ],
    [ 'post_id' => 603, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'episode' ],
    [ 'post_id' => 603, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'episode' ],
];
$scan_count = $test_wpdb->get_col_calls;
do_action( 'added_post_meta', 1, 601, SMP\Podcast\Content\ContentKind::META_KEY, 'episode' );
$duplicate_exclusions = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post__not_in' => [ 77 ] ] );
check(
    [ 77, 601, 602 ] === ( $duplicate_exclusions['post__not_in'] ?? [] ),
    'scoped queries preserve existing exclusions and reject identical and conflicting duplicate content-kind rows'
);
check( $scan_count + 1 === $test_wpdb->get_col_calls, 'protected-meta add hook invalidates the duplicate-ID cache' );

$duplicate_inclusion = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post__in' => [ 77, 601, 602, 88 ] ] );
check( [ 77, 88 ] === ( $duplicate_inclusion['post__in'] ?? [] ), 'existing inclusions are intersected with the duplicate-integrity boundary' );
$empty_duplicate_inclusion = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post__in' => [ 601, 602 ] ] );
check( [ 0 ] === ( $empty_duplicate_inclusion['post__in'] ?? [] ), 'an empty safe inclusion becomes post__in [0] instead of WordPress empty-array match-all semantics' );

$test_wpdb->meta_rows = [];
$scan_count = $test_wpdb->get_col_calls;
do_action( 'updated_post_meta', 2, 601, SMP\Podcast\Content\ContentKind::META_KEY, 'episode' );
$clean_scope = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post__in' => [ 77, 88 ], 'post__not_in' => [ 99 ] ] );
check(
    [ 77, 88 ] === ( $clean_scope['post__in'] ?? [] ) && [ 99 ] === ( $clean_scope['post__not_in'] ?? [] ),
    'a successful clean integrity scan leaves caller inclusion and exclusion constraints unchanged'
);
check( $scan_count + 1 === $test_wpdb->get_col_calls, 'protected-meta update hook invalidates the duplicate-ID cache' );
$cached_scan_count = $test_wpdb->get_col_calls;
SMP\Podcast\Settings\PodcastSettings::scoped_query_args();
check( $cached_scan_count === $test_wpdb->get_col_calls, 'duplicate-ID integrity results are cached within one request' );

$test_wpdb->post_types[604] = 'post';
$test_wpdb->meta_rows = [
    [ 'post_id' => 604, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'episode' ],
    [ 'post_id' => 604, 'meta_key' => SMP\Podcast\Content\ContentKind::META_KEY, 'meta_value' => 'article' ],
];
$scan_count = $test_wpdb->get_col_calls;
do_action( 'deleted_post_meta', 3, 604, SMP\Podcast\Content\ContentKind::META_KEY, 'article' );
$deleted_hook_scope = SMP\Podcast\Settings\PodcastSettings::scoped_query_args();
check(
    $scan_count + 1 === $test_wpdb->get_col_calls && [ 604 ] === ( $deleted_hook_scope['post__not_in'] ?? [] ),
    'protected-meta delete hook invalidates cached clean results before the next scoped query'
);

$test_wpdb->fail_prepare = true;
do_action( 'updated_post_meta', 4, 604, SMP\Podcast\Content\ContentKind::META_KEY, 'episode' );
$prepare_failure_scope = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post__not_in' => [ 77 ] ] );
check(
    [ 0 ] === ( $prepare_failure_scope['post__in'] ?? [] ) && ! isset( $prepare_failure_scope['post__not_in'] ),
    'duplicate-integrity prepare failure fails the scoped query closed with post__in [0]'
);
$test_wpdb->fail_prepare = false;

$test_wpdb->fail_get_col = true;
do_action( 'updated_post_meta', 5, 604, SMP\Podcast\Content\ContentKind::META_KEY, 'episode' );
$query_failure_scope = SMP\Podcast\Settings\PodcastSettings::scoped_query_args();
check( [ 0 ] === ( $query_failure_scope['post__in'] ?? [] ), 'duplicate-integrity database failure fails the scoped query closed with post__in [0]' );
$test_wpdb->fail_get_col = false;
$test_wpdb->last_error = '';
$test_wpdb->meta_rows = [];
SMP\Podcast\Content\ContentKind::invalidate_duplicate_ids();

$GLOBALS['test_post_types'][201] = 'post';
$GLOBALS['test_post_types'][202] = 'post';
$GLOBALS['test_post_types'][203] = 'page';
$GLOBALS['test_post_types'][205] = 'post';
$GLOBALS['test_post_types'][206] = 'post';
$GLOBALS['test_post_types'][207] = 'post';
$GLOBALS['test_post_types'][210] = 'post';
$GLOBALS['test_post_types'][211] = 'post';
$GLOBALS['test_post_types'][212] = 'post';
$GLOBALS['test_post_meta_keys'][202]['audio_url'] = true;
$GLOBALS['test_post_meta_values'][205]['_mpp_content_kind'] = 'episode';
$GLOBALS['test_post_meta_values'][206]['_mpp_content_kind'] = 'article';
$GLOBALS['test_post_meta_values'][206]['audio_url'] = 'https://example.test/must-not-count.mp3';
$GLOBALS['test_post_meta_values'][207]['_mpp_content_kind'] = 'invalid-existing-value';
$GLOBALS['test_post_meta_values'][207]['audio_url'] = 'https://example.test/must-fail-closed.mp3';
$GLOBALS['test_post_meta_values'][210]['profiles'] = [ [ 'profile' => 44 ] ];
$GLOBALS['test_post_meta_rows'][211]['_mpp_content_kind'] = [ 'episode', 'episode' ];
$GLOBALS['test_post_meta_rows'][212]['_mpp_content_kind'] = [ 'episode', 'article' ];
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 201 ), 'unmarked regular posts are excluded from podcast operations' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 202 ), 'metadata-marked posts are included in podcast operations' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 205 ), 'explicit episodes are included without incidental podcast fields' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 206 ), 'explicit articles veto legacy podcast markers' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 206, false ), 'article veto also applies to compatibility checks that do not require markers' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 207 ), 'invalid explicit content-kind values fail closed instead of falling back' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 210 ), 'profiles metadata alone remains ordinary article data' );
check(
    SMP\Podcast\Content\ContentKind::has_explicit_value( 211 )
    && '' === SMP\Podcast\Content\ContentKind::get( 211 )
    && ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 211 ),
    'duplicate identical protected-meta rows fail closed'
);
check(
    SMP\Podcast\Content\ContentKind::has_explicit_value( 212 )
    && '' === SMP\Podcast\Content\ContentKind::get( 212 )
    && ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 212 ),
    'duplicate conflicting protected-meta rows fail closed'
);
$GLOBALS['test_options'][SMP\Podcast\Settings\PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION] = false;
$cutover_scope = SMP\Podcast\Settings\PodcastSettings::content_scope_clause();
check(
    '_mpp_content_kind' === ( $cutover_scope['key'] ?? '' )
    && 'episode' === ( $cutover_scope['value'] ?? '' )
    && ! isset( $cutover_scope['relation'] ),
    'post-backfill query scope accepts explicit episodes only'
);
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 202 ), 'post-backfill cutover rejects unclassified posts with legacy podcast markers' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 205 ), 'post-backfill cutover retains explicit episodes' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 201, false ), 'post-backfill cutover rejects unclassified posts even in marker-optional compatibility checks' );
unset( $GLOBALS['test_options'][SMP\Podcast\Settings\PodcastSettings::LEGACY_MARKER_FALLBACK_OPTION] );
check( SMP\Podcast\Settings\PodcastSettings::legacy_marker_fallback_enabled(), 'missing cutover option preserves legacy episodes before migration' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 203 ), 'non-podcast post types are excluded from podcast operations' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 201, false ), 'explicit unscoped compatibility checks accept the configured post type' );

$episode_rule = [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ];
$episode_group = [ 'key' => SMP\Podcast\Acf\EpisodeFieldGroup::GROUP_KEY, 'title' => 'Podcast Episode Fields' ];
check(
    false === $content_kind->match_episode_field_group_location( true, $episode_rule, [ 'post_id' => 206, 'post_type' => 'post' ], $episode_group ),
    'podcast ACF field group is suppressed for explicit articles'
);
check(
    $content_kind->match_episode_field_group_location( true, $episode_rule, [ 'post_id' => 205, 'post_type' => 'post' ], $episode_group ),
    'podcast ACF field group remains available for explicit episodes'
);
check(
    $content_kind->match_episode_field_group_location( true, $episode_rule, [ 'post_id' => 202, 'post_type' => 'post' ], $episode_group ),
    'podcast ACF field group remains available for genuinely marked legacy episodes'
);
check(
    ! $content_kind->match_episode_field_group_location( true, $episode_rule, [ 'post_id' => 201, 'post_type' => 'post' ], $episode_group ),
    'unclassified Add New posts receive no podcast ACF field group'
);
$other_group = [ 'key' => 'group_unrelated' ];
check(
    $content_kind->match_episode_field_group_location( true, $episode_rule, [ 'post_id' => 206, 'post_type' => 'post' ], $other_group ),
    'content-kind ACF filter never alters unrelated field groups'
);
check(
    ! $content_kind->match_episode_field_group_location( true, $episode_rule, [ 'post_id' => 207, 'post_type' => 'post' ], $episode_group ),
    'invalid explicit content kind also fails closed at the podcast ACF boundary'
);
check(
    ! $content_kind->match_episode_field_group_location( false, $episode_rule, [ 'post_id' => 205, 'post_type' => 'post' ], $episode_group ),
    'content-kind ACF filter cannot turn a failed location rule into a match'
);
check(
    false === $content_kind->prevent_article_episode_field_update( null, 'changed', 206, [ 'key' => 'field_6844c7776e21e' ] ),
    'ACF episode-field writes are short-circuited for explicit articles'
);
check(
    null === $content_kind->prevent_article_episode_field_update( null, 'changed', 205, [ 'key' => 'field_6844c7776e21e' ] ),
    'ACF episode-field writes continue for explicit episodes'
);
check(
    false === $content_kind->prevent_article_episode_field_update( null, 'changed', 201, [ 'key' => 'field_6844c7776e21e' ] ),
    'unclassified regular posts cannot acquire their first podcast marker through ACF'
);
check(
    null === $content_kind->prevent_article_episode_field_update( null, 'changed', 202, [ 'key' => 'field_6844c7776e21e' ] ),
    'existing marked legacy episodes retain their ACF editing path'
);
check(
    null === $content_kind->prevent_article_episode_field_update( null, 'changed', 206, [ 'key' => 'field_unrelated' ] ),
    'ACF write veto never affects fields outside the owned episode group'
);
check(
    true === $content_kind->prevent_article_episode_field_update( true, 'changed', 206, [ 'key' => 'field_6844c7776e21e' ] ),
    'ACF write veto preserves an earlier pre-update short circuit'
);
$GLOBALS['test_is_admin'] = true;
$GLOBALS['test_current_screen'] = (object) [ 'base' => 'post', 'action' => 'add', 'post_type' => 'post' ];
$GLOBALS['test_options']['option_podcast_default_host'] = 999;
$GLOBALS['test_options']['enable_post_functionality'] = true;
$default_host = new SMP\Podcast\Content\DefaultHost();
$default_host->register();
check(
    isset( $GLOBALS['test_actions_registered']['smp_podcast_content_kind_saved'] ),
    'default-host assignment listens to the first canonical episode classification'
);
$GLOBALS['post'] = (object) [ 'ID' => 206 ];
$host_field = [ 'key' => 'field_hosts', 'value' => [] ];
check( $host_field === $default_host->prepare_field( $host_field ), 'default-host ACF preparation does nothing for explicit articles' );
$GLOBALS['post'] = (object) [ 'ID' => 201 ];
check( $host_field === $default_host->prepare_field( $host_field ), 'unclassified Add New posts receive no accidental default host' );

$editor_post = new WP_Post( [ 'ID' => 213, 'post_type' => 'post', 'post_status' => 'draft' ] );
$GLOBALS['test_post_types'][213] = 'post';
$_POST = [
    'smp_podcast_content_kind_nonce' => $GLOBALS['test_valid_nonce'],
    '_mpp_content_kind' => 'episode',
];
$content_kind->save_meta_box( 213, $editor_post, false );
check( [ 'episode' ] === SMP\Podcast\Content\ContentKind::raw_values( 213 ), 'editor save writes one canonical episode metadata row' );
check( [ 999 ] === ( $GLOBALS['test_fields']['213:hosts'] ?? null ), 'first episode classification assigns the configured default host without a second save' );
$saved_action = end( $GLOBALS['test_actions_fired'] );
check(
    'smp_podcast_content_kind_saved' === ( $saved_action[0] ?? '' )
    && [ 213, 'episode', '' ] === ( $saved_action[1] ?? [] ),
    'content-kind transition emits the exact post, new kind, and prior state'
);
$action_count = count( $GLOBALS['test_actions_fired'] );
$content_kind->save_meta_box( 213, $editor_post, true );
check(
    [ 'episode' ] === SMP\Podcast\Content\ContentKind::raw_values( 213 )
    && $action_count === count( $GLOBALS['test_actions_fired'] ),
    'saving an unchanged canonical kind does not churn metadata or repeat transition effects'
);

$GLOBALS['test_post_types'][214] = 'post';
$GLOBALS['test_post_meta_rows'][214]['_mpp_content_kind'] = [ 'episode', 'article' ];
$duplicate_post = new WP_Post( [ 'ID' => 214, 'post_type' => 'post' ] );
$_POST['_mpp_content_kind'] = 'article';
$content_kind->save_meta_box( 214, $duplicate_post, true );
check( [ 'article' ] === SMP\Podcast\Content\ContentKind::raw_values( 214 ), 'editor save normalizes duplicate protected-meta rows to one explicit choice' );

$meta_fixture_key = static fn( int $post_id ): string => $post_id . ':' . SMP\Podcast\Content\ContentKind::META_KEY;
$GLOBALS['test_post_types'][220] = 'post';
$GLOBALS['test_post_meta_rows'][220][SMP\Podcast\Content\ContentKind::META_KEY] = [ 'episode', 'article' ];
$GLOBALS['test_delete_post_meta_failures'][ $meta_fixture_key( 220 ) ] = 1;
$delete_failure_post = new WP_Post( [ 'ID' => 220, 'post_type' => 'post' ] );
$action_count = count( $GLOBALS['test_actions_fired'] );
$_POST['_mpp_content_kind'] = 'article';
$content_kind->save_meta_box( 220, $delete_failure_post, true );
check(
    [ 'episode', 'article' ] === SMP\Podcast\Content\ContentKind::raw_values( 220 )
    && $action_count === count( $GLOBALS['test_actions_fired'] ),
    'failed duplicate-row deletion leaves the exact prior state and emits no transition'
);

$GLOBALS['test_post_types'][221] = 'post';
$GLOBALS['test_post_meta_rows'][221][SMP\Podcast\Content\ContentKind::META_KEY] = [ 'episode', 'article' ];
$GLOBALS['test_add_post_meta_failures'][ $meta_fixture_key( 221 ) ] = 1;
$add_failure_post = new WP_Post( [ 'ID' => 221, 'post_type' => 'post' ] );
$action_count = count( $GLOBALS['test_actions_fired'] );
$_POST['_mpp_content_kind'] = 'article';
$content_kind->save_meta_box( 221, $add_failure_post, true );
check(
    [ 'episode', 'article' ] === SMP\Podcast\Content\ContentKind::raw_values( 221 )
    && $action_count === count( $GLOBALS['test_actions_fired'] ),
    'failed canonical add restores every prior metadata row and emits no transition'
);

$GLOBALS['test_post_types'][222] = 'post';
$GLOBALS['test_post_meta_rows'][222][SMP\Podcast\Content\ContentKind::META_KEY] = [ 'article' ];
$GLOBALS['test_add_post_meta_duplicate_once'][ $meta_fixture_key( 222 ) ] = 1;
$post_write_mismatch = new WP_Post( [ 'ID' => 222, 'post_type' => 'post' ] );
$action_count = count( $GLOBALS['test_actions_fired'] );
$_POST['_mpp_content_kind'] = 'episode';
$content_kind->save_meta_box( 222, $post_write_mismatch, true );
check(
    [ 'article' ] === SMP\Podcast\Content\ContentKind::raw_values( 222 )
    && $action_count === count( $GLOBALS['test_actions_fired'] ),
    'post-write contract mismatch restores the exact prior row and emits no transition'
);

$GLOBALS['test_post_types'][215] = 'post';
$GLOBALS['test_post_meta_rows'][215]['_mpp_content_kind'] = [ 'episode' ];
$protected_post = new WP_Post( [ 'ID' => 215, 'post_type' => 'post' ] );
$GLOBALS['test_current_user_can'] = false;
$_POST['_mpp_content_kind'] = 'article';
$content_kind->save_meta_box( 215, $protected_post, true );
check( [ 'episode' ] === SMP\Podcast\Content\ContentKind::raw_values( 215 ), 'unauthorized editor requests cannot alter content kind' );
$GLOBALS['test_current_user_can'] = true;

$GLOBALS['test_post_types'][216] = 'post';
$invalid_post = new WP_Post( [ 'ID' => 216, 'post_type' => 'post' ] );
$_POST['_mpp_content_kind'] = 'podcast';
$content_kind->save_meta_box( 216, $invalid_post, false );
check( [] === SMP\Podcast\Content\ContentKind::raw_values( 216 ), 'invalid editor choices never create protected metadata' );
$_POST = [];

$GLOBALS['test_options']['smp_podcast_content_model'] = 'episode';
$GLOBALS['test_post_types'][217] = 'episode';
$default_host->assign_after_content_kind( 217, 'episode', '' );
check(
    ! array_key_exists( '217:hosts', $GLOBALS['test_fields'] ),
    'dedicated episode mode keeps its existing ACF Add New default-host lifecycle'
);
$GLOBALS['test_options']['smp_podcast_content_model'] = 'post';

$GLOBALS['test_current_screen'] = (object) [ 'base' => 'post', 'action' => '', 'post_type' => 'post' ];
$GLOBALS['post'] = (object) [ 'ID' => 206 ];
ob_start();
( new SMP\Podcast\Integrations\PowerPressSync() )->render_readonly_fields();
$article_powerpress_admin = (string) ob_get_clean();
check( '' === $article_powerpress_admin, 'PowerPress admin field behavior does not render on explicit articles' );
$GLOBALS['test_is_admin'] = false;
$GLOBALS['test_current_screen'] = null;
unset( $GLOBALS['post'], $GLOBALS['test_options']['option_podcast_default_host'], $GLOBALS['test_options']['enable_post_functionality'] );

$GLOBALS['test_options']['smp_podcast_content_model'] = 'episode';
$GLOBALS['test_post_types'][204] = 'episode';
$GLOBALS['test_post_types'][208] = 'episode';
$GLOBALS['test_post_types'][209] = 'episode';
$GLOBALS['test_post_meta_values'][208]['_mpp_content_kind'] = 'article';
$GLOBALS['test_post_meta_values'][209]['_mpp_content_kind'] = 'episode';
$episode_scoped = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post_status' => 'publish' ] );
check( 'episode' === $episode_scoped['post_type'], 'episode-mode queries use the dedicated content type' );
check( 'OR' === ( $episode_scoped['meta_query'][0]['relation'] ?? '' ), 'episode-mode queries retain unclassified CPT entries while enforcing article veto' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 204 ), 'dedicated episode posts are podcast content without markers' );
check( ! SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 208 ), 'explicit article veto applies even on a dedicated episode post type' );
check( SMP\Podcast\Settings\PodcastSettings::is_podcast_content( 209 ), 'explicit episode authority applies on a dedicated episode post type' );
$GLOBALS['test_options']['smp_podcast_content_model'] = 'post';

$shortcodes = new SMP\Podcast\Frontend\Shortcodes();
$shortcodes->register();
$expected_tags = [ 'smp_listen_button', 'smp_watch_button', 'podcast_url', 'episode_fields', 'article_guests', 'podcast_hosts', 'display_single_episode_hosts', 'guest_grid', 'display_guest_profile_info', 'display_host_profile_info', 'podcast_host' ];
check( $expected_tags === SMP\Podcast\Frontend\Shortcodes::tags(), 'canonical media triggers and all nine legacy shortcode tags register' );
check( [] === array_diff( $expected_tags, array_keys( $GLOBALS['test_shortcodes'] ) ), 'all shortcode callbacks register' );

foreach ( [ 301, 302, 303, 304, 101, 21811 ] as $episode_post_id ) {
    $GLOBALS['test_post_types'][ $episode_post_id ] = 'post';
}
$GLOBALS['test_post_meta_values'][301]['_mpp_content_kind'] = 'episode';
$GLOBALS['test_post_meta_values'][302]['_mpp_content_kind'] = 'episode';
$GLOBALS['test_post_meta_values'][101]['_mpp_content_kind'] = 'episode';
$GLOBALS['test_fields']['301:audio'] = [ 'url' => 'https://example.test/direct-301.mp3' ];
$GLOBALS['test_powerpress'][301] = [ 'url' => 'https://media.example.test/powerpress-301.mp3', 'duration' => '1:02:03' ];
$resolved_audio = SMP\Podcast\Frontend\AudioSourceResolver::resolve( 301 );
check( 'https://media.example.test/powerpress-301.mp3' === $resolved_audio['playback_url'], 'PowerPress URL has playback precedence' );
check( 'https://example.test/direct-301.mp3' === $resolved_audio['download_url'], 'direct ACF media remains the download URL' );
check( 3723 === $resolved_audio['duration_seconds'] && '1:02:03' === $resolved_audio['duration'], 'PowerPress duration normalizes for the player' );
check( 'powerpress' === $resolved_audio['source'], 'resolved audio records its playback owner' );
check( 'medium_large' === ( $GLOBALS['test_thumbnail_sizes'][301] ?? null ), 'persistent-player cover requests a landscape-capable derivative' );
$listen_button = SMP\Podcast\Frontend\ShortcodeCallbacks::listen_button( [ 'post_id' => 301, 'label' => 'Listen now', 'class' => 'episode-listen custom<script>' ] );
check( str_contains( $listen_button, 'data-smp-player-trigger' ) && str_contains( $listen_button, 'powerpress-301.mp3' ), 'listen-button shortcode emits the canonical player contract' );
check( str_contains( $listen_button, 'aria-controls="smp-podcast-player"' ) && str_contains( $listen_button, 'aria-pressed="false"' ), 'listen-button shortcode is keyboard and state accessible' );
check( ! str_contains( $listen_button, '<script>' ), 'listen-button shortcode sanitizes custom classes and labels' );

$GLOBALS['test_fields']['301:urls_youtube'] = 'yzMcrZCYh5Y';
$resolved_video = SMP\Podcast\Frontend\VideoSourceResolver::resolve( 301 );
check( 'yzMcrZCYh5Y' === ( $resolved_video['id'] ?? '' ), 'YouTube episode IDs resolve into the persistent media contract' );
check( '89gjiaYTa0Y' === SMP\Podcast\Frontend\VideoSourceResolver::video_id( '89gjiaYTa0Y&t=3s' ), 'legacy YouTube IDs with a timestamp suffix normalize safely' );
check( 'yzMcrZCYh5Y' === SMP\Podcast\Frontend\VideoSourceResolver::video_id( 'https://www.youtube.com/watch?v=yzMcrZCYh5Y&t=14s' ), 'canonical YouTube watch URLs normalize safely' );
check( '' === SMP\Podcast\Frontend\VideoSourceResolver::video_id( 'https://video.attacker.example/yzMcrZCYh5Y' ), 'non-YouTube video hosts are rejected' );
$listen_with_video = SMP\Podcast\Frontend\ShortcodeCallbacks::listen_button( [ 'post_id' => 301 ] );
check( str_contains( $listen_with_video, 'data-smp-video-id="yzMcrZCYh5Y"' ), 'listen trigger carries the matching dynamic video identity for format switching' );
$watch_button = SMP\Podcast\Frontend\ShortcodeCallbacks::watch_button( [ 'post_id' => 301, 'label' => 'Watch now' ] );
check( str_contains( $watch_button, 'data-smp-watch-trigger' ) && str_contains( $watch_button, 'data-smp-audio-src=' ), 'watch trigger carries both dynamic media sources for inline switching' );
check(
    str_starts_with( $watch_button, '<a ' )
    && str_contains( $watch_button, 'href="https://www.youtube.com/watch?v=yzMcrZCYh5Y"' )
    && ! str_contains( $watch_button, 'aria-pressed=' ),
    'watch shortcode defaults to a crawlable YouTube anchor without button-only state semantics'
);
$watch_button_element = SMP\Podcast\Frontend\ShortcodeCallbacks::watch_button( [ 'post_id' => 301, 'element' => 'button' ] );
check(
    str_starts_with( $watch_button_element, '<button type="button" aria-pressed="false"' )
    && ! str_contains( $watch_button_element, ' href=' ),
    'watch shortcode retains an explicit accessible button compatibility mode'
);

$GLOBALS['test_options'][ SMP\Podcast\Settings\PlaybackSettings::OPTION_NAME ] = array_merge(
    SMP\Podcast\Settings\PlaybackSettings::defaults(),
    [ 'enabled' => true ]
);
ob_start();
( new SMP\Podcast\Frontend\PersistentPlayer() )->render();
$idle_player_markup = (string) ob_get_clean();
check(
    str_contains( $idle_player_markup, 'data-smp-stage' )
    && ! str_contains( $idle_player_markup, 'data-smp-cover' )
    && ! preg_match( '/<img\b[^>]*src=(?:""|\'\')/i', $idle_player_markup ),
    'idle player markup has no empty or broken artwork image'
);
unset( $GLOBALS['test_options'][ SMP\Podcast\Settings\PlaybackSettings::OPTION_NAME ] );

$GLOBALS['test_fields']['302:audio'] = null;
$GLOBALS['test_fields']['302:audio_url'] = 'https://cdn.example.test/direct-302.m4a';
$direct_audio = SMP\Podcast\Frontend\AudioSourceResolver::resolve( 302 );
check( 'https://cdn.example.test/direct-302.m4a' === $direct_audio['playback_url'] && 'audio' === $direct_audio['source'], 'ACF audio URL is the first fallback without PowerPress' );
$GLOBALS['test_post_meta_values'][303]['enclosure'] = "https://cdn.example.test/enclosure-303.mp3\n12345\naudio/mpeg";
$enclosure_audio = SMP\Podcast\Frontend\AudioSourceResolver::resolve( 303 );
check( 'https://cdn.example.test/enclosure-303.mp3' === $enclosure_audio['playback_url'] && 'enclosure' === $enclosure_audio['source'], 'WordPress enclosure is the final playback fallback' );
$GLOBALS['test_post_meta_values'][304]['_mpp_content_kind'] = 'article';
$GLOBALS['test_fields']['304:audio'] = [ 'url' => 'https://example.test/article-audio.mp3' ];
$GLOBALS['test_powerpress'][304] = [ 'url' => 'https://media.example.test/article-powerpress.mp3' ];
$powerpress_calls_before_article = count( $GLOBALS['test_powerpress_calls'] );
$acf_calls_before_article = count( $GLOBALS['test_get_field_calls'] );
check( [] === SMP\Podcast\Frontend\AudioSourceResolver::resolve( 304 ), 'explicit articles cannot initialize the podcast player from ACF or enclosure data' );
check( $powerpress_calls_before_article === count( $GLOBALS['test_powerpress_calls'] ), 'explicit articles never invoke the PowerPress resolver' );
check( $acf_calls_before_article === count( $GLOBALS['test_get_field_calls'] ), 'explicit articles never invoke the podcast ACF audio resolver' );
check( '' === SMP\Podcast\Frontend\ShortcodeCallbacks::episode_fields( [ 'name' => 'audio', 'post_id' => 304 ] ), 'podcast ACF shortcodes do not read explicit articles' );
$acf_calls_before_default_host = count( $GLOBALS['test_get_field_calls'] );
$article_host_result = SMP\Podcast\Content\DefaultHost::apply( 304 );
check( false === $article_host_result['changed'] && str_starts_with( $article_host_result['message'], 'Skipped:' ), 'default-host mutations veto explicit articles' );
check( $acf_calls_before_default_host === count( $GLOBALS['test_get_field_calls'] ), 'default-host operations do not read podcast ACF fields on explicit articles' );
$article_sync_result = SMP\Podcast\Integrations\PowerPressSync::sync_audio( 304 );
check( false === $article_sync_result['changed'] && str_starts_with( $article_sync_result['message'], 'Skipped:' ), 'PowerPress mutations veto explicit articles' );

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
check(
    str_contains( $player_js, 'state.playbackActivated' )
    && str_contains( $player_js, 'state.videoPlaybackActivated && state.videoPlaying' )
    && str_contains( $player_js, 'return !audio.paused;' )
    && ! str_contains( $player_js, 'ajaxWhilePaused' ),
    'navigation interception requires the selected audio or video medium to be actively playing'
);
check(
    str_contains( $player_js, 'youtubeVideoId(videoValue)' )
    && str_contains( $player_js, "switchMode('video', true)" )
    && str_contains( $player_js, "switchMode('audio', true)" )
    && str_contains( $player_js, 'https://www.youtube-nocookie.com/embed/' ),
    'runtime supports validated inline video and bidirectional media switching'
);
check( str_contains( $player_js, "new DOMParser().parseFromString" ) && str_contains( $player_js, "roots.current.replaceWith" ), 'navigation parses full public documents and replaces only the content island' );
check(
    str_contains( $player_js, "nodesIncludingScope(root, '.elementor-element[data-element_type]')" )
    && str_contains( $player_js, 'runReadyTrigger(element)' ),
    'Elementor handlers reinitialize each imported element instead of no-oping on the document root'
);
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
    str_contains( $player_js, "id === 'elementor-recaptcha_v3-api-js'" )
    && str_contains( $player_js, "url.protocol === 'https:'" )
    && str_contains( $player_js, "url.host === 'www.google.com'" )
    && str_contains( $player_js, "url.host === 'www.recaptcha.net'" )
    && str_contains( $player_js, "url.pathname === '/recaptcha/api.js'" )
    && str_contains( $player_js, "url.searchParams.getAll('render').length === 1" )
    && str_contains( $player_js, "url.searchParams.get('render') === 'explicit'" ),
    'dynamic script policy accepts only Elementor reCAPTCHA from its exact HTTPS hosts, path, and render mode'
);
check(
    str_contains( $player_js, 'parseWordfenceHumanLogger.template' )
    && str_contains( $player_js, "url.origin !== window.location.origin" )
    && str_contains( $player_js, "url.searchParams.getAll('wordfence_lh').length !== 1" )
    && str_contains( $player_js, "url.searchParams.getAll('hid').length !== 1" )
    && str_contains( $player_js, '/^[a-f0-9]{32}$/i' )
    && str_contains( $player_js, "canonical === parseWordfenceHumanLogger.template" )
    && str_contains( $player_js, 'var wordfenceInitialized = state.wordfenceLoggerActive || window.wfLogHumanRan;' )
    && str_contains( $player_js, 'if (parseWordfenceHumanLogger(node, text)) wordfenceInitialized = true;' ),
    'fresh Wordfence human logger is accepted only from its byte-exact wrapper and exact same-origin endpoint'
);
check(
    str_contains( $player_js, 'initializeWordfenceHumanLogger(plan.wordfenceUrl)' )
    && str_contains( $player_js, "script.id = 'smp-wordfence-human-logger'" )
    && str_contains( $player_js, "url.searchParams.set('r', String(Math.random()))" )
    && str_contains( $player_js, 'document.removeEventListener(eventName, logHuman, false)' ),
    'validated Wordfence logger is reconstructed as a one-shot owned script without executing fetched inline code'
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
check(
    str_contains( $player_js, "'jet-engine-data-stores-js-before'" )
    && str_contains( $player_js, "'jet-engine-frontend-js-before'" )
    && str_contains( $player_js, 'canonical === safeDynamicInlineScript.sources[id]' )
    && str_contains( $player_js, "new RegExp('(?:^|\\\\n)//# sourceURL='" ),
    'only byte-exact captured JetEngine before-initializers may cross an active AJAX navigation'
);
check(
    substr_count( $player_js, 'safeDynamicInlineScript(source,' ) >= 2
    && str_contains( $player_js, 'copyExecutableInlineScript(source)' )
    && str_contains( $player_js, "reject(unsupportedNavigation('unsupported-inline-script'))" )
    && substr_count( $player_js, 'if (signal && signal.aborted)' ) >= 4,
    'trusted inline initializers are revalidated and abort-checked immediately before ordered execution'
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
    str_contains( $player_css, '--smp-player-accent: #e00813' )
    && str_contains( $player_css, 'aspect-ratio: 16 / 9' )
    && str_contains( $player_css, '.smp-podcast-player .smp-podcast-player__toggle' ),
    'player CSS owns the podcast brand, landscape artwork, and theme-resistant control specificity'
);
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
