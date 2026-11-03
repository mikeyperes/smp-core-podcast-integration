<?php

namespace SMP\Podcast\Frontend;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;

/** Distinct guests in published episodes. No automatic visitor output. */
final class GuestCount implements ModuleInterface {
    private const CACHE_KEY = 'smp_podcast_guest_count_v1';

    public function register(): void {
        add_action('save_post', [self::class, 'invalidate_episode'], PHP_INT_MAX);
        add_action('deleted_post', [self::class, 'invalidate_episode'], 10, 2);
        // Relationship fields can be persisted after save_post, including ACF and REST saves.
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'invalidate_meta'], 10, 2);
        }
    }

    public static function invalidate_episode(int $post_id, $post = null): void {
        $post = $post instanceof \WP_Post ? $post : get_post($post_id);
        if ($post && $post->post_type === PodcastSettings::content_type()) {
            delete_transient(self::cache_key());
        }
    }

    public static function invalidate_meta($meta_id, int $post_id): void {
        self::invalidate_episode($post_id);
    }

    private static function cache_key(): string {
        return self::CACHE_KEY . '_' . sanitize_key(PodcastSettings::content_type());
    }

    public static function count(): int {
        $cached = get_transient(self::cache_key());
        if ($cached !== false && is_numeric($cached)) {
            return (int) $cached;
        }
        $profiles = [];
        $fallbacks = [];
        $episode_ids = get_posts([
            'post_type' => PodcastSettings::content_type(),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($episode_ids as $episode_id) {
            $linked = EpisodeData::relationship_ids('profiles', (int) $episode_id);
            foreach ($linked as $profile_id) {
                $profiles[absint($profile_id)] = true;
            }
            if ($linked === []) {
                $name = trim(EpisodeData::guest_fallback_name((int) $episode_id));
                if ($name !== '') {
                    $fallbacks[sanitize_title($name)] = true;
                }
            }
        }
        unset($profiles[0], $fallbacks['']);
        $count = count($profiles) + count($fallbacks);
        set_transient(self::cache_key(), $count, HOUR_IN_SECONDS);
        return $count;
    }

    public static function shortcode(array $atts = []): string {
        return esc_html(number_format_i18n(self::count()));
    }
}
