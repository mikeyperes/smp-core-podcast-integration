<?php

declare( strict_types=1 );

$root = dirname( __DIR__ );
$checks = 0;
$failures = [];

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

function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?: '' ); }
function sanitize_title( string $value ): string { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?: '' ), '-' ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return esc_html( $value ); }
function esc_url( mixed $value ): string { return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: ''; }
function wp_kses_post( mixed $value ): string { return (string) $value; }
function wp_strip_all_tags( mixed $value ): string { return strip_tags( (string) $value ); }
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
function get_post( int $post_id ): ?object { return $post_id > 0 ? (object) [ 'ID' => $post_id, 'post_author' => 1, 'post_type' => 'post' ] : null; }
function get_post_type( int $post_id ): string|false { return $GLOBALS['test_post_types'][ $post_id ] ?? false; }
function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool { return 'post' === $meta_type && ! empty( $GLOBALS['test_post_meta_keys'][ $object_id ][ $meta_key ] ); }
function get_the_title( mixed $post_id = 0 ): string { return 'Object ' . (int) ( is_object( $post_id ) ? $post_id->ID : $post_id ); }
function get_permalink( int $post_id ): string { return 'https://example.test/object-' . $post_id . '/'; }
function get_userdata( int $user_id ): ?object { return $user_id > 0 ? (object) [ 'ID' => $user_id, 'display_name' => 'User ' . $user_id, 'user_email' => '', 'user_url' => '' ] : null; }
function get_users( array $args = [] ): array { unset( $args ); return []; }
function get_author_posts_url( int $user_id ): string { return 'https://example.test/author/' . $user_id . '/'; }
function get_avatar( int $id, int $size = 96, string $default = '', string $alt = '' ): string { unset( $default ); return '<img data-id="' . $id . '" width="' . $size . '" alt="' . esc_attr( $alt ) . '">'; }
function get_the_post_thumbnail( int $id, mixed $size = 'thumbnail', array $attrs = [] ): string { unset( $size, $attrs ); return '<img data-post="' . $id . '">'; }
function has_post_thumbnail( int $id ): bool { return $id > 0; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { unset( $hook, $args ); return $value; }
function setup_postdata( object $post ): void { unset( $post ); }
function wp_reset_postdata(): void {}
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { unset( $hook, $callback, $priority, $accepted_args ); }
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
check( '3.0.0' === $header_version, 'plugin header reports 3.0.0', $header_version );
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

$rewrite = new SMP\Podcast\Support\RewriteLifecycle();
unset( $GLOBALS['test_options']['smp_podcast_rewrite_version'], $GLOBALS['test_options']['smp_podcast_flush_rewrite_rules'] );
$rewrite->register();
check( 1 === ( $GLOBALS['test_options']['smp_podcast_flush_rewrite_rules'] ?? 0 ), 'a new release schedules one rewrite refresh' );
$rewrite->maybe_flush();
check( SMP\Podcast\Config::VERSION === ( $GLOBALS['test_options']['smp_podcast_rewrite_version'] ?? '' ), 'rewrite refresh records the current release' );
check( ! isset( $GLOBALS['test_options']['smp_podcast_flush_rewrite_rules'] ), 'rewrite refresh clears its pending flag' );

$scoped = SMP\Podcast\Settings\PodcastSettings::scoped_query_args( [ 'post_status' => 'publish' ] );
check( 'post' === $scoped['post_type'], 'post-mode queries retain the WordPress post type' );
check( 'OR' === ( $scoped['meta_query']['relation'] ?? '' ), 'post-mode queries require a podcast metadata marker' );
check( 6 === count( $scoped['meta_query'] ) - 1, 'all podcast metadata markers participate in post-mode queries' );

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
$expected_tags = [ 'podcast_url', 'episode_fields', 'article_guests', 'podcast_hosts', 'display_single_episode_hosts', 'guest_grid', 'display_guest_profile_info', 'display_host_profile_info', 'podcast_host' ];
check( $expected_tags === SMP\Podcast\Frontend\Shortcodes::tags(), 'all nine shortcode tags remain canonical' );
check( [] === array_diff( $expected_tags, array_keys( $GLOBALS['test_shortcodes'] ) ), 'all shortcode callbacks register' );

$GLOBALS['test_fields']['101:audio_url'] = 'https://cdn.example.test/audio.mp3';
check( 'https://cdn.example.test/audio.mp3' === SMP\Podcast\Frontend\ShortcodeCallbacks::episode_fields( [ 'name' => 'audio_url', 'post_id' => 101 ] ), 'top-level audio_url shortcode is not misread as a group' );
$GLOBALS['test_fields']['101:urls'] = [ 'spotify' => 'https://open.spotify.com/example' ];
check( 'https://open.spotify.com/example' === SMP\Podcast\Frontend\ShortcodeCallbacks::episode_fields( [ 'name' => 'urls_spotify', 'post_id' => 101 ] ), 'grouped URL shortcode resolves' );

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

echo "\n{$checks} checks, " . count( $failures ) . " failed.\n";
if ( $failures ) {
    foreach ( $failures as $failure ) {
        fwrite( STDERR, "- {$failure}\n" );
    }
    exit( 1 );
}
