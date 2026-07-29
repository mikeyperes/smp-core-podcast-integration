<?php

namespace Hexa\PluginCore\SearchQuery;

/**
 * Applies a host-provided search configuration to one exact frontend query.
 *
 * The SQL filter is attached from pre_get_posts, checks object identity, and
 * removes itself immediately after the target query reaches posts_search.
 */
final class SearchQueryEngine {
    public const EXPLICIT_QUERY_VAR = 'hexa_search_query_explicit';

    /** @var callable */
    private $settings_provider;

    private string $marker_key;

    public function __construct( callable $settings_provider, string $marker_key = 'hexa_search' ) {
        $this->settings_provider = $settings_provider;
        $this->marker_key = self::key( $marker_key );

        if ( '' === $this->marker_key ) {
            $this->marker_key = 'hexa_search';
        }
    }

    public function register(): void {
        add_filter( 'query_vars', [ $this, 'register_query_var' ] );
        add_action( 'pre_get_posts', [ $this, 'prepare_query' ], 20 );
    }

    /** @param string[] $query_vars @return string[] */
    public function register_query_var( array $query_vars ): array {
        if ( ! in_array( $this->marker_key, $query_vars, true ) ) {
            $query_vars[] = $this->marker_key;
        }

        return $query_vars;
    }

    /** @param object $query */
    public function prepare_query( $query ): void {
        if ( ! $this->is_candidate_query( $query ) ) {
            return;
        }

        $provided = call_user_func( $this->settings_provider );
        if ( ! is_array( $provided ) ) {
            return;
        }

        $settings = SearchQueryConfiguration::normalize(
            $provided,
            (array) ( $provided['post_types'] ?? [] ),
            (array) ( $provided['taxonomies'] ?? [] )
        );

        if ( ! $this->configuration_allows_query( $query, $settings ) ) {
            return;
        }

        $query->set( 'post_type', $settings['post_types'] );
        if ( $settings['results_per_page'] > 0 ) {
            $query->set( 'posts_per_page', $settings['results_per_page'] );
        }
        $this->apply_ordering( $query, (string) $settings['orderby'] );
        $query->set( 'hexa_search_query_active', 1 );

        $raw_query = trim( (string) $query->get( 's' ) );
        $filter = null;
        $filter = function ( $search_sql, $candidate_query ) use ( &$filter, $query, $settings, $raw_query ) {
            if ( $candidate_query !== $query ) {
                return $search_sql;
            }

            remove_filter( 'posts_search', $filter, 999 );

            return $this->build_search_sql( $raw_query, $settings );
        };

        add_filter( 'posts_search', $filter, 999, 2 );
    }

    /**
     * @param array<string,mixed> $settings
     * @param object|null $database wpdb-compatible object; injectable for tests.
     */
    public function build_search_sql( string $raw_query, array $settings, $database = null ): string {
        if ( null === $database ) {
            global $wpdb;
            $database = $wpdb;
        }

        if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'esc_like' ) ) {
            return '';
        }

        $settings = SearchQueryConfiguration::normalize(
            $settings,
            (array) ( $settings['post_types'] ?? [] ),
            (array) ( $settings['taxonomies'] ?? [] )
        );
        $terms = SearchTermParser::parse( $raw_query, (string) $settings['term_logic'] );
        if ( [] === $terms ) {
            return '';
        }

        $groups = [];
        foreach ( $terms as $term ) {
            $sources = $this->source_conditions( $database, $term, $settings );
            if ( [] !== $sources ) {
                $groups[] = '(' . implode( ' OR ', $sources ) . ')';
            }
        }

        if ( [] === $groups ) {
            return '';
        }

        $relation = 'any' === $settings['term_logic'] ? ' OR ' : ' AND ';
        $sql = ' AND (' . implode( $relation, $groups ) . ')';
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            $sql .= ' AND (' . $database->posts . ".post_password = '')";
        }

        return $sql . ' ';
    }

    /** @param object $query */
    private function is_candidate_query( $query ): bool {
        if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) || ! method_exists( $query, 'set' ) ) {
            return false;
        }
        if ( function_exists( 'is_admin' ) && is_admin() ) {
            return false;
        }
        if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return false;
        }
        if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return false;
        }
        if ( ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return false;
        }
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return false;
        }
        $is_main_query = method_exists( $query, 'is_main_query' ) && $query->is_main_query();
        $is_explicit_query = '1' === (string) $query->get( self::EXPLICIT_QUERY_VAR );
        if ( ! $is_main_query && ! $is_explicit_query ) {
            return false;
        }
        if ( ! method_exists( $query, 'is_search' ) || ! $query->is_search() ) {
            return false;
        }
        if ( method_exists( $query, 'is_feed' ) && $query->is_feed() ) {
            return false;
        }
        if ( '' === trim( (string) $query->get( 's' ) ) || $query->get( 'suppress_filters' ) || $query->get( 'hexa_search_query_disabled' ) ) {
            return false;
        }

        return true;
    }

    /** @param object $query @param array<string,mixed> $settings */
    private function configuration_allows_query( $query, array $settings ): bool {
        if ( ! $settings['enabled'] ) {
            return false;
        }
        if ( 'shortcode' === $settings['scope'] && '1' !== (string) $query->get( $this->marker_key ) ) {
            return false;
        }

        if ( function_exists( 'apply_filters' ) ) {
            return (bool) apply_filters( 'hexa_plugin_core_search_query_should_handle', true, $query, $settings );
        }

        return true;
    }

    /** @param object $query */
    private function apply_ordering( $query, string $orderby ): void {
        switch ( $orderby ) {
            case 'newest':
                $query->set( 'orderby', 'date' );
                $query->set( 'order', 'DESC' );
                break;
            case 'oldest':
                $query->set( 'orderby', 'date' );
                $query->set( 'order', 'ASC' );
                break;
            case 'title':
                $query->set( 'orderby', 'title' );
                $query->set( 'order', 'ASC' );
                break;
            case 'relevance':
            default:
                $query->set( 'orderby', 'relevance' );
                break;
        }
    }

    /** @param object $database @param array<string,mixed> $settings @return string[] */
    private function source_conditions( $database, string $term, array $settings ): array {
        $field_columns = [
            'title'   => $database->posts . '.post_title',
            'content' => $database->posts . '.post_content',
            'excerpt' => $database->posts . '.post_excerpt',
            'slug'    => $database->posts . '.post_name',
        ];
        $matching = 'exact' === $settings['term_logic'] ? 'contains' : (string) $settings['word_matching'];
        $conditions = [];

        foreach ( $settings['fields'] as $field ) {
            if ( isset( $field_columns[ $field ] ) ) {
                $conditions[] = $this->match_condition( $database, $field_columns[ $field ], $term, $matching );
            }
        }

        if ( ! empty( $settings['taxonomies'] ) ) {
            $taxonomy_conditions = [];
            foreach ( $settings['taxonomies'] as $taxonomy ) {
                $taxonomy_conditions[] = $database->prepare( 'hexa_sq_tt.taxonomy = %s', $taxonomy );
            }
            $conditions[] = 'EXISTS (SELECT 1 FROM ' . $database->term_relationships . ' hexa_sq_tr'
                . ' INNER JOIN ' . $database->term_taxonomy . ' hexa_sq_tt ON hexa_sq_tt.term_taxonomy_id = hexa_sq_tr.term_taxonomy_id'
                . ' INNER JOIN ' . $database->terms . ' hexa_sq_t ON hexa_sq_t.term_id = hexa_sq_tt.term_id'
                . ' WHERE hexa_sq_tr.object_id = ' . $database->posts . '.ID'
                . ' AND (' . implode( ' OR ', $taxonomy_conditions ) . ')'
                . ' AND ' . $this->match_condition( $database, 'hexa_sq_t.name', $term, $matching ) . ')';
        }

        if ( ! empty( $settings['authors'] ) ) {
            $conditions[] = 'EXISTS (SELECT 1 FROM ' . $database->users . ' hexa_sq_u'
                . ' WHERE hexa_sq_u.ID = ' . $database->posts . '.post_author'
                . ' AND ' . $this->match_condition( $database, 'hexa_sq_u.display_name', $term, $matching ) . ')';
        }

        if ( ! empty( $settings['custom_fields'] ) ) {
            $meta_conditions = [];
            foreach ( $settings['custom_fields'] as $meta_key ) {
                $meta_conditions[] = $database->prepare( 'hexa_sq_pm.meta_key = %s', $meta_key );
            }
            $conditions[] = 'EXISTS (SELECT 1 FROM ' . $database->postmeta . ' hexa_sq_pm'
                . ' WHERE hexa_sq_pm.post_id = ' . $database->posts . '.ID'
                . ' AND (' . implode( ' OR ', $meta_conditions ) . ')'
                . ' AND ' . $this->match_condition( $database, 'hexa_sq_pm.meta_value', $term, $matching ) . ')';
        }

        return array_values( array_filter( $conditions ) );
    }

    /** @param object $database */
    private function match_condition( $database, string $column, string $term, string $matching ): string {
        if ( 'contains' === $matching ) {
            return $database->prepare( $column . ' LIKE %s', '%' . $database->esc_like( $term ) . '%' );
        }

        $literal = preg_quote( $term, '/' );
        $pattern = '(^|[^[:alnum:]_])' . $literal;
        if ( 'whole' === $matching ) {
            $pattern .= '([^[:alnum:]_]|$)';
        }

        return $database->prepare( $column . ' REGEXP %s', $pattern );
    }

    private static function key( string $value ): string {
        $value = strtolower( trim( $value ) );

        return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
    }
}
