<?php

namespace SMP\Podcast\Diagnostics;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\IntegrationTests\TestRegistry;
use SMP\Podcast\Acf\EpisodeFieldGroup;
use SMP\Podcast\Acf\PodcastOptionsFieldGroup;
use SMP\Podcast\Config;
use SMP\Podcast\Frontend\Shortcodes;
use SMP\Podcast\Frontend\ShortcodeCallbacks;
use SMP\Podcast\Settings\PodcastSettings;
use SMP\Podcast\Support\Dependencies;

final class IntegrationTests implements ModuleInterface {
    public function register(): void {
        add_action( 'hexa_plugin_core_register_integration_tests', [ $this, 'add_tests' ] );
    }

    public function add_tests( TestRegistry $registry ): void {
        $group = 'Scale My Podcast';
        $host = Config::SLUG;

        $registry->register(
            $host . '.dependencies',
            'Podcast runtime dependencies are ready',
            static function(): array {
                $dependencies = Dependencies::all();
                $missing = [];
                foreach ( $dependencies as $dependency ) {
                    if ( ! empty( $dependency['required'] ) && empty( $dependency['active'] ) ) {
                        $missing[] = (string) $dependency['label'];
                    }
                }
                return [
                    'passed' => [] === $missing,
                    'summary' => [] === $missing ? 'All required podcast dependencies are active.' : 'Required dependencies are missing.',
                    'expected' => 'Advanced Custom Fields Pro active',
                    'actual' => [] === $missing ? 'Ready' : implode( ', ', $missing ),
                    'details' => [
                        'powerpress' => ! empty( $dependencies['powerpress']['active'] ) ? 'active' : 'optional and unavailable',
                        'verified_profiles' => ! empty( $dependencies['verified-profiles']['active'] ) ? 'active' : 'optional and unavailable',
                    ],
                ];
            },
            [ 'group' => $group, 'host' => $host, 'description' => 'Confirms the plugin remains diagnosable while enforcing only dependencies needed for field registration.' ]
        );

        $registry->register(
            $host . '.content-model',
            'Podcast content model is registered',
            static function(): array {
                $post_type = PodcastSettings::content_type();
                $exists = post_type_exists( $post_type );
                return [
                    'passed' => $exists,
                    'summary' => $exists ? 'The configured podcast content type is available.' : 'The configured podcast content type is missing.',
                    'expected' => $post_type,
                    'actual' => $exists ? $post_type : 'not registered',
                    'details' => [ 'stored_posts' => (string) self::post_count( $post_type ) ],
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.acf-groups',
            'Canonical podcast ACF groups are registered once',
            static function(): array {
                $keys = [ EpisodeFieldGroup::GROUP_KEY, PodcastOptionsFieldGroup::GROUP_KEY ];
                $missing = [];
                foreach ( $keys as $key ) {
                    if ( ! function_exists( 'acf_get_field_group' ) || ! acf_get_field_group( $key ) ) {
                        $missing[] = $key;
                    }
                }
                return [
                    'passed' => [] === $missing,
                    'summary' => [] === $missing ? 'Both canonical field groups are active.' : 'One or more canonical field groups are unavailable.',
                    'expected' => implode( ', ', $keys ),
                    'actual' => [] === $missing ? 'Both registered' : 'Missing: ' . implode( ', ', $missing ),
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.acf-key-contract',
            'Episode ACF field keys remain compatible',
            static function(): array {
                $required = [
                    'field_6844c5f82ace2', 'field_6844c7776e21e', 'field_6844c7e536052',
                    'field_6844c7e536056', 'field_6844c7e536050', 'field_6844c7e536051',
                    'field_6844c7e536053', 'field_6844c7e536054', 'field_6844c7e536055',
                    'field_6844c7e536058', 'field_6844c7e536057',
                ];
                $definition = EpisodeFieldGroup::definition();
                $keys = self::field_keys( (array) ( $definition['fields'] ?? [] ) );
                $missing = array_values( array_diff( $required, $keys ) );
                return [
                    'passed' => [] === $missing,
                    'summary' => [] === $missing ? 'Every compatibility-critical episode field key is present.' : 'Episode field keys changed.',
                    'expected' => count( $required ) . ' required field keys',
                    'actual' => ( count( $required ) - count( $missing ) ) . ' present',
                    'details' => [ 'total_definition_keys' => (string) count( $keys ), 'missing' => implode( ', ', $missing ) ?: 'none' ],
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.shortcodes',
            'All legacy podcast shortcodes are registered',
            static function(): array {
                $missing = array_values( array_filter( Shortcodes::tags(), static fn( string $tag ): bool => ! shortcode_exists( $tag ) ) );
                return [
                    'passed' => [] === $missing,
                    'summary' => [] === $missing ? 'Every supported podcast shortcode is active.' : 'Podcast shortcode registrations are incomplete.',
                    'expected' => count( Shortcodes::tags() ) . ' shortcode tags',
                    'actual' => ( count( Shortcodes::tags() ) - count( $missing ) ) . ' active',
                    'details' => [ 'missing' => implode( ', ', $missing ) ?: 'none' ],
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.powerpress-feed',
            'Public PowerPress feed remains registered',
            static function(): array {
                global $wp_rewrite;
                $feeds = is_object( $wp_rewrite ) ? (array) $wp_rewrite->feeds : [];
                $passed = in_array( 'podcast', $feeds, true ) || in_array( 'podcast-feed', $feeds, true );
                return [
                    'passed' => $passed,
                    'summary' => $passed ? 'The existing public podcast feed rewrite is intact.' : 'No public podcast feed rewrite was detected.',
                    'expected' => 'podcast or podcast-feed',
                    'actual' => implode( ', ', array_intersect( [ 'podcast', 'podcast-feed' ], $feeds ) ) ?: 'missing',
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.audio-data',
            'Saved audio and enclosure data remain aligned',
            static function(): array {
                $ids = get_posts(
                    PodcastSettings::scoped_query_args(
                        [
                        'post_type' => PodcastSettings::content_type(),
                        'post_status' => 'any',
                        'posts_per_page' => 1,
                        'fields' => 'ids',
                        'meta_key' => 'enclosure',
                        'orderby' => 'modified',
                        'order' => 'DESC',
                        ]
                    )
                );
                if ( ! $ids ) {
                    return [ 'passed' => true, 'summary' => 'No enclosure-backed podcast content exists yet.', 'expected' => 'No destructive assumption', 'actual' => 'No sample available' ];
                }
                $post_id = (int) $ids[0];
                $enclosure = (string) get_post_meta( $post_id, 'enclosure', true );
                $audio_url = (string) get_post_meta( $post_id, 'audio_url', true );
                $first_line = trim( (string) strtok( $enclosure, "\r\n" ) );
                $passed = '' !== $first_line && '' !== $audio_url && $first_line === $audio_url;
                return [
                    'passed' => $passed,
                    'summary' => $passed ? 'The latest enclosure-backed post uses the same audio URL in ACF and PowerPress.' : 'Audio URL and enclosure data differ.',
                    'expected' => $audio_url ?: 'Saved audio_url',
                    'actual' => $first_line ?: 'Missing enclosure URL',
                    'details' => [ 'post_id' => (string) $post_id ],
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.active-basename',
            'Canonical plugin entrypoint is active',
            static function(): array {
                $active = (array) get_option( 'active_plugins', [] );
                $canonical = Config::SLUG . '/' . Config::MAIN_FILE;
                $legacy = Config::SLUG . '/' . Config::LEGACY_FILE;
                $passed = in_array( $canonical, $active, true ) && ! in_array( $legacy, $active, true );
                return [
                    'passed' => $passed,
                    'summary' => $passed ? 'WordPress uses the canonical 3.x plugin entrypoint.' : 'The active plugin basename did not finish migrating.',
                    'expected' => $canonical,
                    'actual' => in_array( $canonical, $active, true ) ? $canonical : ( in_array( $legacy, $active, true ) ? $legacy : 'not active' ),
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.registry-contracts',
            'Core CPT, ACF, and snippet registries resolve',
            static function(): array {
                $content = \SMP\Podcast\Bootstrap\Registries::content_types()->resolved_definitions();
                $options = \SMP\Podcast\Bootstrap\Registries::option_fields()->resolved_definitions();
                $snippets = \SMP\Podcast\Bootstrap\Registries::snippets()->all();
                $definition = $content[0] ?? [];
                $expected_id = 'post' === PodcastSettings::content_type() ? 'podcast-post-integration' : 'podcast-episode';
                $passed = 1 === count( $content ) && 1 === count( $options ) && 6 === count( $snippets ) && $expected_id === ( $definition['id'] ?? '' );
                return [
                    'passed' => $passed,
                    'summary' => $passed ? 'All shared registries resolve through Hexa WP Core.' : 'A shared registry count or content-model contract differs.',
                    'expected' => '1 content type, 1 option group, 6 snippets, ' . $expected_id,
                    'actual' => count( $content ) . ' content type(s), ' . count( $options ) . ' option group(s), ' . count( $snippets ) . ' snippet(s), ' . (string) ( $definition['id'] ?? 'missing' ),
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.default-host',
            'Configured default host resolves to a profile',
            static function(): array {
                $host_id = PodcastSettings::default_host_id();
                $post_type = $host_id > 0 ? get_post_type( $host_id ) : '';
                $passed = 0 === $host_id || 'profile' === $post_type;
                return [
                    'passed' => $passed,
                    'summary' => 0 === $host_id ? 'No default host is configured; the optional relationship remains valid.' : ( $passed ? 'The saved default host resolves to a verified profile.' : 'The saved default host points to an unexpected object type.' ),
                    'expected' => 'No selection or profile post',
                    'actual' => 0 === $host_id ? 'No selection' : $post_type . ' #' . $host_id,
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.shortcode-data',
            'Episode field shortcode reads saved audio data',
            static function(): array {
                $ids = get_posts( PodcastSettings::scoped_query_args( [ 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => 'audio_url', 'orderby' => 'modified', 'order' => 'DESC' ] ) );
                if ( ! $ids ) {
                    return [ 'passed' => true, 'summary' => 'No saved audio URL exists yet; shortcode registration is covered separately.' ];
                }
                $post_id = (int) $ids[0];
                $expected = (string) get_post_meta( $post_id, 'audio_url', true );
                $actual = wp_strip_all_tags( ShortcodeCallbacks::episode_fields( [ 'name' => 'audio_url', 'post_id' => $post_id ] ) );
                return [
                    'passed' => '' !== $expected && $expected === $actual,
                    'summary' => $expected === $actual ? 'The canonical shortcode returns the stored audio URL.' : 'The audio shortcode output differs from stored metadata.',
                    'expected' => $expected,
                    'actual' => $actual,
                    'details' => [ 'post_id' => $post_id ],
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.powerpress-runtime',
            'PowerPress synchronization state is coherent',
            static function(): array {
                $enabled = (bool) get_option( 'enable_powerpress_post_functionality', false );
                $ready = Dependencies::powerpress_ready();
                $passed = ! $enabled || $ready;
                return [
                    'passed' => $passed,
                    'summary' => $passed ? ( $enabled ? 'PowerPress synchronization is enabled and its API is available.' : 'PowerPress synchronization is intentionally disabled.' ) : 'PowerPress synchronization is enabled but its API is unavailable.',
                    'expected' => $enabled ? 'PowerPress API available' : 'Disabled or available',
                    'actual' => $ready ? 'API available' : 'API unavailable',
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.public-feed-http',
            'Public podcast feed returns valid RSS',
            static fn(): array => self::http_test( home_url( '/feed/podcast/' ), '<rss', 'Public PowerPress feed' ),
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.internal-feed-http',
            'Enabled internal feed returns valid RSS',
            static function(): array {
                if ( ! (bool) get_option( 'enable_rss', false ) ) {
                    return [ 'passed' => true, 'summary' => 'The optional internal feed is disabled.', 'expected' => 'Disabled or HTTP 200 RSS', 'actual' => 'Disabled' ];
                }
                return self::http_test( home_url( '/feed/internal-rss/' ), '<rss', 'Internal integration feed' );
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.schema-continuity',
            'Existing episode pages retain JSON-LD schema',
            static function(): array {
                $ids = get_posts( PodcastSettings::scoped_query_args( [ 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] ) );
                if ( ! $ids ) {
                    return [ 'passed' => true, 'summary' => 'No published podcast item exists yet.' ];
                }
                return self::http_test( get_permalink( (int) $ids[0] ), 'application/ld+json', 'Published podcast schema' );
            },
            [ 'group' => $group, 'host' => $host ]
        );

        $registry->register(
            $host . '.updater-contract',
            'GitHub updater targets the canonical release',
            static function(): array {
                $config = \SMP\Podcast\updater_config();
                $passed = Config::GITHUB_REPO === $config->github_repo()
                    && Config::SLUG . '/' . Config::MAIN_FILE === $config->canonical_plugin_basename()
                    && Config::VERSION === $config->version();
                return [
                    'passed' => $passed,
                    'summary' => $passed ? 'Updater repository, basename, and release version are canonical.' : 'Updater metadata does not match the plugin release.',
                    'expected' => Config::GITHUB_REPO . ' at ' . Config::VERSION,
                    'actual' => $config->github_repo() . ' at ' . $config->version(),
                    'details' => [ 'basename' => $config->canonical_plugin_basename() ],
                ];
            },
            [ 'group' => $group, 'host' => $host ]
        );
    }

    private static function post_count( string $post_type ): int {
        unset( $post_type );
        $query = new \WP_Query( PodcastSettings::scoped_query_args( [ 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false ] ) );
        return (int) $query->found_posts;
    }

    /** @return array<string,mixed> */
    private static function http_test( string $url, string $needle, string $label ): array {
        $response = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 3, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
        if ( is_wp_error( $response ) ) {
            return [ 'passed' => false, 'summary' => $label . ' request failed.', 'expected' => 'HTTP 200 containing ' . $needle, 'actual' => $response->get_error_message(), 'details' => [ 'url' => $url ] ];
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $passed = 200 === $status && false !== stripos( $body, $needle );
        return [
            'passed' => $passed,
            'summary' => $passed ? $label . ' returned the expected live document.' : $label . ' response was incomplete.',
            'expected' => 'HTTP 200 containing ' . $needle,
            'actual' => 'HTTP ' . $status . ( false !== stripos( $body, $needle ) ? ' with marker' : ' without marker' ),
            'details' => [ 'url' => $url, 'bytes' => strlen( $body ) ],
        ];
    }

    /** @param array<int,array<string,mixed>> $fields @return array<int,string> */
    private static function field_keys( array $fields ): array {
        $keys = [];
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            if ( isset( $field['key'] ) ) {
                $keys[] = (string) $field['key'];
            }
            if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                $keys = array_merge( $keys, self::field_keys( $field['sub_fields'] ) );
            }
        }
        return array_values( array_unique( $keys ) );
    }
}
