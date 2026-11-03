<?php
namespace SMP\Podcast\Content;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;

/** One cached season payload, shared by templates, shortcodes and other consumers. */
final class SeasonData implements ModuleInterface {
    private const GROUP = 'smp_podcast_seasons';

    public function register(): void {
        foreach (['clean_post_cache','clean_term_cache','added_term_meta','updated_term_meta','deleted_term_meta'] as $hook) {
            add_action($hook, [self::class, 'invalidate']);
        }
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'post_meta_changed'], 10, 3);
        }
    }

    public static function invalidate(): void {
        wp_cache_set('generation', microtime(), self::GROUP);
    }

    public static function post_meta_changed($meta_id, $post_id, $key): void {
        if ($key === ContentKind::META_KEY) self::invalidate();
    }

    /** Explicit term accepts a term ID or slug. Without it, only season archives qualify. */
    public static function term($selector = ''): ?\WP_Term {
        if ($selector === '' || $selector === null) {
            if (!is_tax(EpisodeMetadata::TAXONOMY)) return null;
            $term = get_queried_object();
        } else {
            $selector = is_scalar($selector) ? trim((string)$selector) : '';
            if ($selector === '') return null;
            $term = ctype_digit($selector) ? get_term((int)$selector, EpisodeMetadata::TAXONOMY) : null;
            if (!$term || is_wp_error($term)) $term = get_term_by('slug', $selector, EpisodeMetadata::TAXONOMY);
        }
        return $term instanceof \WP_Term && $term->taxonomy === EpisodeMetadata::TAXONOMY ? $term : null;
    }

    /** @return array{number:int,episode_count:int,first_date:int,last_date:int}|array{} */
    public static function get(int $term_id): array {
        $term = get_term($term_id, EpisodeMetadata::TAXONOMY);
        if (!$term instanceof \WP_Term) return [];
        $generation = (string)wp_cache_get('generation', self::GROUP);
        $key = $term_id.':'.md5($generation.wp_cache_get_last_changed('posts').wp_cache_get_last_changed('terms'));
        $found = false;
        $data = wp_cache_get($key, self::GROUP, false, $found);
        if ($found && is_array($data)) return $data;
        $ids = get_posts(PodcastSettings::scoped_query_args([
            'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids',
            'orderby'=>['date'=>'ASC','ID'=>'ASC'],
            'tax_query'=>[['taxonomy'=>EpisodeMetadata::TAXONOMY,'field'=>'term_id','terms'=>[$term_id]]],
        ]));
        $first = $ids ? get_post_datetime((int)$ids[0]) : false;
        $last = $ids ? get_post_datetime((int)end($ids)) : false;
        $data = [
            'number'=>(int)get_term_meta($term_id,'season_number',true),
            'episode_count'=>count($ids),
            'first_date'=>$first ? $first->getTimestamp() : 0,
            'last_date'=>$last ? $last->getTimestamp() : 0,
        ];
        wp_cache_set($key, $data, self::GROUP, HOUR_IN_SECONDS);
        return $data;
    }
}
