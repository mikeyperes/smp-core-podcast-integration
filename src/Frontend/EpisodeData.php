<?php
namespace SMP\Podcast\Frontend;
use WP_Post;
use WP_Term;
use WP_Query;
use DOMDocument;
use DOMXPath;
use DOMElement;
use Hexa\PluginCore\CoreContracts\ModuleInterface;

/** Episode presentation using saved podcast fields; no automatic frontend output. */
final class EpisodeData implements ModuleInterface {
public function register(): void {
        add_action('elementor/frontend/widget/before_render', [self::class, 'bind_widget_query']);
        add_action('elementor/query/smp_verified_profiles_episode_hosts', static function (WP_Query $query): void { self::apply_profile_query($query, 'hosts'); });
        add_action('elementor/query/smp_verified_profiles_episode_guests', static function (WP_Query $query): void { self::apply_profile_query($query, 'profiles'); });
        add_action('elementor/query/smp_verified_profiles_profile_episodes', [self::class, 'profile_apply_episode_query']);
        add_action('elementor/query/smp_episode_related', [self::class, 'related_query']);
    }
    /** Resolve saved Elementor sources before its query module validates the post type. */
    public static function bind_widget_query($widget): void {
        if (!is_object($widget) || !method_exists($widget, 'get_settings') || !method_exists($widget, 'set_settings')) { return; }
        $id = (string)$widget->get_settings('post_query_query_id');
        if (in_array($id, ['smp_verified_profiles_episode_hosts','smp_verified_profiles_episode_guests'], true)) {
            $types = self::guest_types();
            $widget->set_settings('post_query_post_type', $types[0] ?? '__smp_disabled');
        } elseif (in_array($id, ['smp_verified_profiles_profile_episodes','smp_episode_related'], true)) {
            $widget->set_settings('post_query_post_type', \SMP\Podcast\Settings\PodcastSettings::content_type());
        }
    }
    public static function guest_types(): array {
        if (!class_exists('SMP\\VerifiedProfiles\\ProfileTypes\\ProfileTypes')) { return []; }
        $type = method_exists(\SMP\VerifiedProfiles\ProfileTypes\ProfileTypes::class, 'guest_type') ? \SMP\VerifiedProfiles\ProfileTypes\ProfileTypes::guest_type() : '';
        return $type !== '' ? [$type] : \SMP\VerifiedProfiles\ProfileTypes\ProfileTypes::post_types();
    }
    public static function callbacks(): array { return [
        'smp_episode_presence' => [self::class, 'presence_shortcode'],
        'mpp_episode_presence' => [self::class, 'presence_shortcode'],
        'smp_episode_field' => [self::class, 'field_shortcode'],
        'smp_episode_audio_url' => [self::class, 'audio_url_shortcode'],
        'smp_episode_video_url' => [self::class, 'video_url_shortcode'],
        'smp_episode_platform_url' => [self::class, 'platform_url_shortcode'],
        'smp_episode_platforms' => [self::class, 'platforms_shortcode'],
        'smp_episode_takeaways' => [self::class, 'takeaways_shortcode'],
        'smp_episode_primary_category' => [self::class, 'primary_category_shortcode'],
        'smp_episode_label' => [self::class, 'label_shortcode'],
        'smp_episode_details' => [self::class, 'details_shortcode'],
        'smp_episode_archive_url' => [self::class, 'archive_url_shortcode'],
        'smp_episode_host_links' => [self::class, 'host_links_shortcode'],
        'smp_episode_guest_fallback' => [self::class, 'guest_fallback_shortcode'],
    ]; }
/** Presence uses the same parsed content, relationships and usable media as episode output. */
public static function presence_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['field' => '', 'post_id' => 0], $atts, 'smp_episode_presence');
    $episode_id = self::context_id(absint($atts['post_id']));
    if (!$episode_id) {
        return '';
    }

    $field = sanitize_key((string) $atts['field']);
    $parts = null;
    $hosts = null;
    $profiles = null;
    $fallback = null;

    $content_parts = static function () use (&$parts, $episode_id): array {
        if ($parts === null) {
            $parts = self::content_parts($episode_id);
        }
        return $parts;
    };
    $host_ids = static function () use (&$hosts, $episode_id): array {
        if ($hosts === null) {
            $hosts = self::relationship_ids('hosts', $episode_id);
        }
        return $hosts;
    };
    $profile_ids = static function () use (&$profiles, $episode_id): array {
        if ($profiles === null) {
            $profiles = self::relationship_ids('profiles', $episode_id);
        }
        return $profiles;
    };
    $fallback_name = static function () use (&$fallback, $episode_id): string {
        if ($fallback === null) {
            $fallback = self::guest_fallback_name($episode_id);
        }
        return $fallback;
    };

    $present = match ($field) {
        'deck' => $content_parts()['deck'] !== '',
        'conversation' => $content_parts()['conversation'] !== [],
        'takeaways' => $content_parts()['takeaways'] !== [],
        'hosts' => $host_ids() !== [],
        'guest_profiles' => $profile_ids() !== [],
        'guest_fallback' => $fallback_name() !== '',
        'guests' => $profile_ids() !== [] || $fallback_name() !== '',
        'people' => $host_ids() !== [] || $profile_ids() !== [] || $fallback_name() !== '',
        'people_separator' => $host_ids() !== []
            && ($profile_ids() !== [] || $fallback_name() !== ''),
        'category' => self::primary_category($episode_id) instanceof WP_Term,
        'video' => self::video_url($episode_id) !== '',
        'audio' => self::audio_url($episode_id) !== '' && shortcode_exists('smp_listen_button'),
        'player' => self::video_url($episode_id) !== ''
            || (self::audio_url($episode_id) !== '' && shortcode_exists('smp_listen_button')),
        'record' => $content_parts()['conversation'] !== []
            || $content_parts()['takeaways'] !== []
            || $profile_ids() !== []
            || $fallback_name() !== '',
        'episode' => self::is_episode($episode_id),
        default => false,
    };

    return $present ? '1' : '';
}

public static function context_id(int $candidate = 0): int {
    if ($candidate > 0 && get_post_type($candidate) === \SMP\Podcast\Settings\PodcastSettings::content_type()) {
        return $candidate;
    }

    // Prefer the active loop object when it is itself an episode. This keeps
    // related-episode Loop Items bound to their own audio/video instead of the
    // outer singular post. Profile Loop Items safely fall through because
    // their global object is not a `post`.
    $global_id = get_the_ID();
    if ($global_id && get_post_type($global_id) === \SMP\Podcast\Settings\PodcastSettings::content_type()) {
        return (int) $global_id;
    }

    $queried_id = get_queried_object_id();
    if ($queried_id && get_post_type($queried_id) === \SMP\Podcast\Settings\PodcastSettings::content_type()) {
        return (int) $queried_id;
    }

    // Elementor Theme Builder uses the selected preview ID in the editor.
    if (class_exists('Elementor\\Plugin')) {
        $document = \Elementor\Plugin::$instance->documents->get_current();
        if ($document && method_exists($document, 'get_settings')) {
            $preview_id = absint($document->get_settings('preview_id'));
            if ($preview_id && get_post_type($preview_id) === \SMP\Podcast\Settings\PodcastSettings::content_type()) {
                return $preview_id;
            }
        }
    }

    return 0;
}

public static function is_source_credit_line(string $line): bool {
    $line = trim(wp_strip_all_tags($line));
    if ($line === '' || mb_strlen($line) > 160) {
        return false;
    }

    if (preg_match('/^(?:read|view)\s+the\s+original(?:\s+(?:article|episode|post))?(?:\s*[\x{2192}\x{2197}])?[.!]?$/iu', $line)) {
        return true;
    }

    if (preg_match('/^source\s*:\s*[^,;!?]{1,120}[.!]?$/iu', $line)) {
        return true;
    }

    // A publication credit is a short label/value, not a sentence. Requiring
    // a compact punctuation-free publication name prevents ordinary prose
    // such as “Published in 2015, the study changed…” from being discarded.
    return (bool) preg_match(
        '/^(?:(?:first|originally)\s+)?published\s+(?:in|on|by)\s+(?!\d{4}\b)(?![^\r\n]*\.\s+\p{L})[\p{L}\p{N}][\p{L}\p{N}&\'’ .\-]{0,70}(?:\s*[\x{2192}\x{2197}])?[.!]?$/iu',
        $line
    );
}

public static function is_copy_boundary(string $line): bool {
    $line = trim(wp_strip_all_tags($line));
    if ($line === '') {
        return false;
    }

    if (self::is_source_credit_line($line)) {
        return true;
    }

    $boundary_label = '(?:'
        . 'host|hosts|guest|guests'
        . '|about\s+(?:the\s+)?(?:host|hosts|guest|guests|show)'
        . '|additional\s+information'
        . '|(?:youtube|spotify|apple(?:\s+podcasts?)?|amazon(?:\s+music)?|audible|tunein|soundcloud|rss)\s+(?:url|link)'
        . '|learn\s+more|follow(?:\s+(?:us|me))?|connect\s+with|resources?|links?'
        . ')';
    if (preg_match('/^' . $boundary_label . '\s*:?$/iu', $line)) {
        return true;
    }
    if (mb_strlen($line) <= 180 && preg_match('/^' . $boundary_label . '\s*:\s*\S.{0,150}$/iu', $line)) {
        return true;
    }

    return (bool) preg_match('/^(?:https?:\/\/|www\.)\S+$/iu', $line);
}

public static function visible_markup(string $markup): string {
    $markup = strip_shortcodes($markup);
    if ($markup === '' || !class_exists('DOMDocument')) {
        return preg_replace(
            '/<([a-z0-9]+)\b[^>]*(?:hidden\b|aria-hidden\s*=\s*["\']?true|style\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^"\']*["\'])[^>]*>.*?<\/\1\s*>/isu',
            '',
            $markup
        ) ?? $markup;
    }

    $previous_errors = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><!doctype html><html><body><div id="mpp-episode-parser-root">' . $markup . '</div></body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);

    if (!$loaded) {
        return $markup;
    }

    $xpath = new DOMXPath($document);
    $remove = [];
    foreach ($xpath->query('//script|//style|//noscript|//template|//iframe|//object|//embed|//form|//*[@hidden]|//*[@aria-hidden="true"]') ?: [] as $node) {
        $remove[] = $node;
    }

    foreach ($xpath->query('//*[@style]') ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $style = strtolower((string) preg_replace('/\s+/u', '', $node->getAttribute('style')));
        $explicitly_hidden = str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden');
        $clipped = str_contains($style, 'clip:rect') || str_contains($style, 'clip-path:inset(50%');
        $tiny = (bool) preg_match('/(?:height|max-height|width|max-width):(?>0|1px)(?:[;!]|$)/u', $style);
        $overflow_hidden = str_contains($style, 'overflow:hidden');
        $off_canvas = (bool) preg_match('/(?:left|top):-\d{3,}(?:px|rem|em)/u', $style);
        if ($explicitly_hidden || $clipped || ($overflow_hidden && $tiny) || $off_canvas) {
            $remove[] = $node;
        }
    }

    foreach ($remove as $node) {
        if ($node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }

    $root = $document->getElementById('mpp-episode-parser-root');
    if (!$root) {
        return $markup;
    }

    $visible = '';
    foreach ($root->childNodes as $child) {
        $visible .= $document->saveHTML($child);
    }
    return $visible;
}

public static function visible_text(string $markup): string {
    $markup = self::visible_markup($markup);
    $markup = preg_replace('/<br\s*\/?\s*>/iu', "\n", $markup) ?? $markup;
    $markup = preg_replace('/<li\b[^>]*>/iu', "\n• ", $markup) ?? $markup;
    $markup = preg_replace('/<\/li\s*>/iu', "\n", $markup) ?? $markup;
    $markup = preg_replace('/<\/?(?:p|div|section|article|aside|blockquote|h[1-6]|ul|ol)\b[^>]*>/iu', "\n\n", $markup) ?? $markup;
    $text = html_entity_decode(wp_strip_all_tags($markup), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $text = preg_replace("/\r\n?/u", "\n", $text) ?? $text;
    $text = preg_replace('/[\t\x{00A0} ]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
    return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
}

public static function summary_paragraphs(int $episode_id): array {
    foreach (['summary', 'episode_summary'] as $meta_key) {
        $summary = self::visible_text((string) get_post_meta($episode_id, $meta_key, true));
        if ($summary === '') {
            continue;
        }
        $blocks = preg_split('/\n{2,}/u', $summary) ?: [];
        $paragraphs = [];
        $stop = false;
        foreach ($blocks as $block) {
            $kept_lines = [];
            foreach (preg_split('/\n/u', (string) $block) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (self::is_copy_boundary($line)) {
                    $stop = true;
                    break;
                }
                $kept_lines[] = $line;
            }
            $paragraph = trim(implode(' ', $kept_lines));
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
            if ($stop) {
                break;
            }
        }
        if ($paragraphs !== []) {
            return $paragraphs;
        }
    }
    return [];
}

public static function takeaway_heading_pattern(): string {
    return '/(?:'
        . 'in\s+this\s+(?:episode|conversation|podcast)(?:\s*,?\s*(?:you[\'’]?ll\s+(?:learn|discover)|we(?:\s+will|[\'’]?ll)?\s+(?:learn|cover|discuss)|(?:learn|discover|cover)(?:ing)?)[^:\n•]*)?'
        . '|you[\'’]?ll\s+learn'
        . '|we\s+(?:dive|delve)\s+deep\s+into'
        . '|(?:some\s+of\s+)?the\s+topics\s+(?:discussed|covered)'
        . '|questions(?:\s+(?:asked|answered|discussed))?'
        . '|(?:the\s+)?episode\s+highlights'
        . '|key\s+takeaways'
        . ')\s*:?/iu';
}

public static function line_has_bullet(string $line): bool {
    return (bool) preg_match('/(?:^|\s)•\s*\S|^[*\-–—]\s+\S/u', trim($line));
}

public static function find_takeaway_heading(array $lines): ?array {
    $pattern = self::takeaway_heading_pattern();
    foreach ($lines as $index => $line) {
        if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $matched = (string) $match[0];
            $offset = (int) $match[1];
            $remainder = trim(substr($line, $offset + strlen($matched)));
            $immediate_bullet = self::line_has_bullet($remainder);

            // A candidate followed by ordinary prose is not a heading. Keep
            // scanning the same line: post 17060 begins "In this episode of…"
            // and later contains the real "The episode highlights:" heading.
            if (!$immediate_bullet && trim($remainder, " \t\n\r\0\x0B:–—-") !== '') {
                continue;
            }
            if (!$immediate_bullet) {
                for ($lookahead = $index + 1, $limit = min(count($lines), $index + 5); $lookahead < $limit; $lookahead++) {
                    $candidate = trim((string) $lines[$lookahead]);
                    if ($candidate === '') {
                        continue;
                    }
                    $immediate_bullet = self::line_has_bullet($candidate);
                    break;
                }
            }
            if (!$immediate_bullet) {
                continue;
            }

            return [
                'index' => (int) $index,
                'offset' => $offset,
                'length' => strlen($matched),
                'remainder' => $remainder,
                'prefix' => trim(substr($line, 0, $offset)),
            ];
        }
    }
    return null;
}

public static function parse_takeaways(array $lines, array $heading): array {
    $queue = array_merge([$heading['remainder']], array_slice($lines, $heading['index'] + 1));
    $takeaways = [];
    $started = false;
    $block_break = false;

    foreach ($queue as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            if ($started) {
                $block_break = true;
            }
            continue;
        }
        if (self::is_copy_boundary($line)) {
            break;
        }

        $new_items = [];
        if (str_contains($line, '•')) {
            $parts = preg_split('/\s*•\s*/u', $line) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $new_items[] = $part;
                }
            }
        } elseif (preg_match('/^[*\-–—]\s+(.+)$/u', $line, $match)) {
            $new_items[] = trim($match[1]);
        }

        if ($new_items !== []) {
            foreach ($new_items as $item) {
                if ($item !== '' && !self::is_copy_boundary($item)) {
                    $takeaways[] = $item;
                }
            }
            $started = true;
            $block_break = false;
            continue;
        }

        if (!$started || $block_break) {
            break;
        }

        $last = array_key_last($takeaways);
        if ($last !== null) {
            $takeaways[$last] = trim($takeaways[$last] . ' ' . $line);
        }
    }

    return array_values(array_filter(array_map(static function (string $item): string {
        $item = trim(wp_strip_all_tags($item));
        $item = preg_replace('/\s+/u', ' ', $item) ?? $item;
        return trim($item);
    }, $takeaways), static fn(string $item): bool => $item !== '' && !self::is_copy_boundary($item)));
}

public static function relationship_ids(string $relationship, int $episode_id = 0): array {
    $episode_id = self::context_id($episode_id);
    if (!$episode_id || !in_array($relationship, ['hosts', 'profiles'], true)) {
        return [];
    }

    $ids = [];
    if (function_exists('get_field')) {
        $value = get_field($relationship, $episode_id);
        foreach (is_array($value) ? $value : [] as $row) {
            if ($relationship === 'profiles' && is_array($row) && array_key_exists('profile', $row)) {
                $row = $row['profile'];
            }
            if (is_object($row) && isset($row->ID)) {
                $row = $row->ID;
            }
            $profile_id = absint($row);
            if ($profile_id && in_array(get_post_type($profile_id), self::guest_types(), true)) {
                $ids[] = $profile_id;
            }
        }
    }

    if ($ids === [] && $relationship === 'hosts') {
        foreach ((array) maybe_unserialize(get_post_meta($episode_id, 'hosts', true)) as $profile_id) {
            $profile_id = absint($profile_id);
            if ($profile_id && in_array(get_post_type($profile_id), self::guest_types(), true)) {
                $ids[] = $profile_id;
            }
        }
    }

    if ($ids === [] && $relationship === 'profiles') {
        $count = absint(get_post_meta($episode_id, 'profiles', true));
        for ($index = 0; $index < $count; $index++) {
            $profile_id = absint(get_post_meta($episode_id, "profiles_{$index}_profile", true));
            if ($profile_id && in_array(get_post_type($profile_id), self::guest_types(), true)) {
                $ids[] = $profile_id;
            }
        }
    }

    if ($ids === [] && $relationship === 'hosts') {
        $host = \SMP\Podcast\Settings\PodcastSettings::default_host_id();
        if ($host && in_array(get_post_type($host), self::guest_types(), true)) { $ids[] = $host; }
    }
    return array_values(array_unique($ids));
}

public static function content_parts(int $episode_id = 0): array {
    static $cache = [];

    $episode_id = self::context_id($episode_id);
    if (!$episode_id) {
        return ['deck' => '', 'conversation' => [], 'takeaways' => []];
    }
    if (isset($cache[$episode_id])) {
        return $cache[$episode_id];
    }

    $text = self::visible_text((string) get_post_field('post_content', $episode_id));
    $lines = preg_split('/\n/u', $text) ?: [];
    $heading = self::find_takeaway_heading($lines);
    $takeaways = $heading ? self::parse_takeaways($lines, $heading) : [];

    $conversation = self::summary_paragraphs($episode_id);
    if ($conversation === []) {
        $conversation_limit = $heading ? (int) $heading['index'] + 1 : count($lines);
        for ($index = 0; $index < $conversation_limit; $index++) {
            $line = trim((string) ($lines[$index] ?? ''));
            if ($heading && $index === (int) $heading['index']) {
                $line = (string) $heading['prefix'];
            }
            if ($line === '') {
                continue;
            }
            if (self::is_copy_boundary($line)) {
                break;
            }
            $line = preg_replace('/\s+/u', ' ', wp_strip_all_tags($line)) ?? $line;
            if ($line !== '') {
                $conversation[] = trim($line);
            }
        }
    }

    $conversation = array_values(array_filter(array_map('trim', $conversation), static fn(string $value): bool => $value !== '' && !self::is_copy_boundary($value)));

    return $cache[$episode_id] = [
        'deck' => $conversation[0] ?? '',
        'conversation' => $conversation,
        'takeaways' => $takeaways,
    ];
}

public static function field_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['name' => '', 'post_id' => 0], $atts, 'smp_episode_field');
    $episode_id = self::context_id(absint($atts['post_id']));
    $name = sanitize_key((string) $atts['name']);
    if (!$episode_id) {
        return '';
    }

    $parts = self::content_parts($episode_id);
    if ($name === 'deck') {
        return esc_html($parts['deck']);
    }
    if ($name === 'conversation') {
        $html = '';
        foreach ($parts['conversation'] as $paragraph) {
            $html .= '<p>' . esc_html($paragraph) . '</p>';
        }
        return $html;
    }
    if ($name === 'guest_count') {
        $count = count(self::relationship_ids('profiles', $episode_id));
        if ($count === 0 && self::guest_fallback_name($episode_id) !== '') {
            $count = 1;
        }
        return $count > 0 ? (string) $count : '';
    }
    if ($name === 'host_count') {
        $count = count(self::relationship_ids('hosts', $episode_id));
        return $count > 0 ? (string) $count : '';
    }
    if ($name === 'video_url') {
        return esc_url(self::video_url($episode_id));
    }
    if ($name === 'audio_url') {
        return esc_url(self::audio_url($episode_id));
    }
    if ($name === 'guest_name') {
        return esc_html(self::guest_fallback_name($episode_id));
    }

    return '';
}

public static function takeaways_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_takeaways');
    $parts = self::content_parts(absint($atts['post_id']));
    if ($parts['takeaways'] === []) {
        return '';
    }

    $items = '';
    foreach ($parts['takeaways'] as $takeaway) {
        $items .= '<li><span>' . esc_html($takeaway) . '</span></li>';
    }
    $label_id = 'mpp-episode-takeaways-' . absint(self::context_id(absint($atts['post_id'])));
    return '<section class="mpp-episode-takeaways" aria-labelledby="' . esc_attr($label_id) . '">'
        . '<p class="mpp-episode-takeaways__label" id="' . esc_attr($label_id) . '">'
        . esc_html(self::label('takeaways', self::context_id(absint($atts['post_id']))))
        . '</p>'
        . '<ol class="mpp-episode-takeaway-list">' . $items . '</ol>'
        . '</section>';
}

public static function guest_fallback_name(int $episode_id = 0): string {
    $episode_id = self::context_id($episode_id);
    if (!$episode_id || self::relationship_ids('profiles', $episode_id) !== []) {
        return '';
    }

    // Deliberately do not use interviewee_name. The corpus audit found stale,
    // incorrect data in that field for post 16982.
    foreach (['guest_information_name', 'guest_name'] as $meta_key) {
        $name = trim(wp_strip_all_tags((string) get_post_meta($episode_id, $meta_key, true)));
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

public static function guest_fallback_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0, 'variant' => 'dossier'], $atts, 'smp_episode_guest_fallback');
    $name = self::guest_fallback_name(absint($atts['post_id']));
    if ($name === '') {
        return '';
    }

    $variant = in_array($atts['variant'], ['chip', 'dossier', 'rail'], true) ? $atts['variant'] : 'dossier';

    return '<article class="mpp-guest-fallback mpp-guest-fallback--' . esc_attr($variant) . '">'
        . '<span class="mpp-guest-fallback__mark" aria-hidden="true">' . esc_html(mb_substr($name, 0, 1)) . '</span>'
        . '<span><span class="mpp-guest-fallback__name">' . esc_html($name) . '</span>'
        . '<span class="mpp-guest-fallback__note">' . esc_html__('Guest', 'smp-core-podcast-integration') . '</span></span>'
        . '</article>';
}

public static function video_id(int $episode_id = 0): string { return (string) (VideoSourceResolver::resolve(self::context_id($episode_id))['id'] ?? ''); }

public static function video_url(int $episode_id = 0): string { return (string) (VideoSourceResolver::resolve(self::context_id($episode_id))['watch_url'] ?? ''); }

public static function valid_audio_url($value): string {
    if (is_array($value)) {
        if (!empty($value['url'])) {
            $value = $value['url'];
        } elseif (!empty($value['ID'])) {
            $value = wp_get_attachment_url(absint($value['ID']));
        } elseif (!empty($value['id'])) {
            $value = wp_get_attachment_url(absint($value['id']));
        }
    } elseif (is_object($value) && isset($value->ID)) {
        $value = wp_get_attachment_url(absint($value->ID));
    } elseif (is_numeric($value)) {
        $value = wp_get_attachment_url(absint($value));
    }

    $url = esc_url_raw(trim((string) $value));
    if ($url === '' || !wp_http_validate_url($url)) {
        return '';
    }
    $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'wav', 'flac'], true) ? $url : '';
}

public static function audio_url(int $episode_id = 0): string {
    $episode_id = self::context_id($episode_id);
    if (!$episode_id) {
        return '';
    }

    // PowerPress is canonical when present. Post 21902 proves why this order
    // matters: its audio_url/attachment resolves to .txt while enclosure is a
    // valid MP3.
    $enclosure = (string) get_post_meta($episode_id, 'enclosure', true);
    $enclosure_url = trim((string) (preg_split('/\R/u', $enclosure)[0] ?? ''));
    $valid = self::valid_audio_url($enclosure_url);
    if ($valid !== '') {
        return $valid;
    }

    $valid = self::valid_audio_url(get_post_meta($episode_id, 'audio_url', true));
    if ($valid !== '') {
        return $valid;
    }

    $audio = function_exists('get_field') ? get_field('audio', $episode_id) : get_post_meta($episode_id, 'audio', true);
    return self::valid_audio_url($audio);
}

public static function is_episode(int $episode_id = 0): bool {
    $episode_id = self::context_id($episode_id);
    return $episode_id > 0
        && (self::audio_url($episode_id) !== '' || self::video_url($episode_id) !== '');
}

public static function label(string $name, int $episode_id = 0): string {
    $is_episode = self::is_episode($episode_id);
    return match (sanitize_key($name)) {
        'takeaways' => $is_episode
            ? __('In this episode, you’ll learn', 'smp-core-podcast-integration')
            : __('Key takeaways', 'smp-core-podcast-integration'),
        'record_heading' => $is_episode
            ? __('The conversation', 'smp-core-podcast-integration')
            : __('The article', 'smp-core-podcast-integration'),
        'related_eyebrow' => $is_episode
            ? __('Keep listening', 'smp-core-podcast-integration')
            : __('Keep reading', 'smp-core-podcast-integration'),
        'related_heading' => $is_episode
            ? __('More conversations', 'smp-core-podcast-integration')
            : __('More stories', 'smp-core-podcast-integration'),
        'view_all' => $is_episode
            ? sprintf(
                _n(
                    'View all %s episode',
                    'View all %s episodes',
                    max(0, (int) (wp_count_posts('post')->publish ?? 0)),
                    'smp-core-podcast-integration'
                ),
                number_format_i18n(max(0, (int) (wp_count_posts('post')->publish ?? 0)))
            )
            : __('View all stories', 'smp-core-podcast-integration'),
        default => '',
    };
}

public static function label_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['name' => '', 'post_id' => 0], $atts, 'smp_episode_label');
    return esc_html(self::label((string) $atts['name'], absint($atts['post_id'])));
}

public static function archive_url_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_archive_url');
    $episode_id = self::context_id(absint($atts['post_id']));
    return esc_url(self::is_episode($episode_id) ? home_url('/episodes/') : home_url('/'));
}

public static function video_url_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_video_url');
    return esc_url(self::video_url(absint($atts['post_id'])));
}

public static function audio_url_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_audio_url');
    return esc_url(self::audio_url(absint($atts['post_id'])));
}

public static function platform_url(string $service, int $episode_id = 0): string {
    $episode_id = self::context_id($episode_id);
    $service = sanitize_key($service);
    $meta_map = [
        'spotify' => 'urls_spotify',
        'apple' => 'urls_apple',
        'youtube' => 'urls_youtube',
        'amazon' => 'urls_amazon',
        'audible' => 'urls_audible',
        'tunein' => 'urls_tunein',
        'soundcloud' => 'urls_soundcloud',
    ];

    // The strip represents show-level destinations (the episode's separate
    // Watch control owns its YouTube video). Prefer the configured show URL,
    // then fall back to episode metadata only when no show URL exists.
    if (shortcode_exists('podcast_url')) {
        $url = trim(wp_strip_all_tags((string) do_shortcode('[podcast_url social="' . esc_attr($service) . '"]')));
        $url = esc_url_raw($url);
        if ($url !== '' && wp_http_validate_url($url)) {
            return $url;
        }
    }

    if ($service === 'rss') {
        return esc_url_raw(home_url('/feed/podcast/'));
    }
    if ($episode_id && isset($meta_map[$service])) {
        $url = esc_url_raw((string) get_post_meta($episode_id, $meta_map[$service], true));
        if ($url !== '' && wp_http_validate_url($url)) {
            return $url;
        }
    }

    return '';
}

public static function platform_url_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['service' => '', 'post_id' => 0], $atts, 'smp_episode_platform_url');
    return esc_url(self::platform_url((string) $atts['service'], absint($atts['post_id'])));
}

public static function platforms_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_platforms');
    $episode_id = absint($atts['post_id']);
    $services = [
        'spotify' => 'Spotify',
        'apple' => 'Apple Podcasts',
        'youtube' => 'YouTube',
        'amazon' => 'Amazon Music',
        'audible' => 'Audible',
        'tunein' => 'TuneIn',
        'soundcloud' => 'SoundCloud',
        'rss' => 'RSS',
    ];
    $links = [];
    foreach ($services as $service => $label) {
        $url = self::platform_url($service, $episode_id);
        if ($url === '') {
            continue;
        }
        $external = $service !== 'rss';
        $links[] = '<a class="mpp-episode-platform mpp-episode-platform--' . esc_attr($service) . '" href="' . esc_url($url) . '"'
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
            . '<span class="mpp-episode-platform__mark" aria-hidden="true"></span>' . esc_html($label)
            . ($external ? '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'smp-core-podcast-integration') . '</span>' : '')
            . '</a>';
    }

    return $links === [] ? '' : '<nav class="mpp-episode-platforms" aria-label="' . esc_attr__('Listen on', 'smp-core-podcast-integration') . '">' . implode('', $links) . '</nav>';
}

public static function primary_category(int $episode_id): ?WP_Term {
    $categories = get_the_category($episode_id);
    if ($categories === []) {
        return null;
    }
    $default_category = absint(get_option('default_category'));
    foreach ($categories as $category) {
        if ((int) $category->term_id !== $default_category && $category->slug !== 'uncategorized') {
            return $category;
        }
    }
    return null;
}

public static function primary_category_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0, 'linked' => 'yes'], $atts, 'smp_episode_primary_category');
    $episode_id = self::context_id(absint($atts['post_id']));
    $category = $episode_id ? self::primary_category($episode_id) : null;
    if (!$category) {
        return '';
    }
    if ((string) $atts['linked'] !== 'yes') {
        return esc_html($category->name);
    }
    $url = get_term_link($category);
    if (is_wp_error($url)) {
        return esc_html($category->name);
    }
    return '<a href="' . esc_url($url) . '">' . esc_html($category->name) . '</a>';
}

public static function details_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_details');
    $episode_id = self::context_id(absint($atts['post_id']));
    if (!$episode_id) {
        return '';
    }

    $rows = [];
    $date = get_the_date('F j, Y', $episode_id);
    if ($date !== '') {
        $rows[] = ['Published', '<time datetime="' . esc_attr(get_the_date('c', $episode_id)) . '">' . esc_html($date) . '</time>'];
    }
    $category = self::primary_category($episode_id);
    if ($category) {
        $category_url = get_term_link($category);
        if (!is_wp_error($category_url)) {
            $rows[] = ['Subject', '<a href="' . esc_url($category_url) . '">' . esc_html($category->name) . '</a>'];
        }
    }
    $hosts = self::host_links_shortcode(['post_id' => $episode_id]);
    if ($hosts !== '') {
        $rows[] = ['Host', $hosts];
    }
    $guest_count = count(self::relationship_ids('profiles', $episode_id));
    if ($guest_count === 0 && self::guest_fallback_name($episode_id) !== '') {
        $guest_count = 1;
    }
    if ($guest_count > 0) {
        $rows[] = [$guest_count === 1 ? 'Guest' : 'Guests', esc_html((string) $guest_count)];
    }
    $video_url = self::video_url($episode_id);
    if ($video_url !== '') {
        $rows[] = ['Watch', '<a href="' . esc_url($video_url) . '" target="_blank" rel="noopener noreferrer">YouTube <span aria-hidden="true">↗</span><span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'smp-core-podcast-integration') . '</span></a>'];
    }
    $audio_url = self::audio_url($episode_id);
    if ($audio_url !== '') {
        $rows[] = ['Audio', '<a href="' . esc_url($audio_url) . '">MP3 <span aria-hidden="true">→</span></a>'];
    }

    if ($rows === []) {
        return '';
    }
    $html = '<dl class="mpp-episode-details">';
    foreach ($rows as [$label, $value]) {
        $html .= '<div class="mpp-episode-details__row"><dt>' . esc_html($label) . '</dt><dd>' . wp_kses_post($value) . '</dd></div>';
    }
    return $html . '</dl>';
}

public static function host_links_shortcode(array $atts = []): string {
    $atts = shortcode_atts(['post_id' => 0], $atts, 'smp_episode_host_links');
    $ids = self::relationship_ids('hosts', absint($atts['post_id']));
    $links = [];
    foreach ($ids as $profile_id) {
        $title = trim((string) get_the_title($profile_id));
        $url = get_permalink($profile_id);
        if ($title !== '' && $url) {
            $links[] = '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
        }
    }
    return implode(', ', $links);
}
public static function related_query(WP_Query $query): void {

    // Elementor's query-control callback remains on pre_get_posts while this
    // hook runs. The bounded candidate queries below therefore re-enter the
    // same custom-query action unless we explicitly guard this owner. Nested
    // candidate/fill queries must retain their own arguments and must never
    // recursively rebuild the related-episode query.
    static $resolving = false;
    if ($resolving) {
        return;
    }
    $resolving = true;
    try {
    $episode_id = self::context_id();
    $default_category = absint(get_option('default_category'));
    $uncategorized = get_term_by('slug', 'uncategorized', 'category');
    $excluded_categories = array_filter([$default_category, $uncategorized instanceof WP_Term ? (int) $uncategorized->term_id : 0]);
    $categories = $episode_id ? array_values(array_diff(array_map('absint', wp_get_post_categories($episode_id)), $excluded_categories)) : [];

    // Bound the shared-subject candidate pool, fetch all candidate terms in a
    // single query, and rank deterministically by overlap, date, then ID.
    // This replaces the former all-post scan and N+1 category lookup.
    $candidate_posts = $categories === [] ? [] : get_posts([
        'post_type' => \SMP\Podcast\Settings\PodcastSettings::content_type(),
        'post_status' => 'publish',
        'posts_per_page' => 24,
        'post__not_in' => $episode_id ? [$episode_id] : [],
        'category__in' => $categories,
        'category__not_in' => $excluded_categories,
        'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'suppress_filters' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);
    $candidates = [];
    foreach ($candidate_posts as $candidate_post) {
        if (!$candidate_post instanceof WP_Post) {
            continue;
        }
        $candidate_id = absint($candidate_post->ID);
        if (!$candidate_id) {
            continue;
        }
        $date_value = $candidate_post->post_date_gmt !== '0000-00-00 00:00:00'
            ? $candidate_post->post_date_gmt . ' GMT'
            : $candidate_post->post_date;
        $candidates[$candidate_id] = strtotime($date_value) ?: 0;
    }
    $candidate_ids = array_keys($candidates);

    $terms_by_post = [];
    if ($candidate_ids !== []) {
        $terms = wp_get_object_terms($candidate_ids, 'category', ['fields' => 'all_with_object_id']);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $object_id = absint($term->object_id ?? 0);
                $term_id = absint($term->term_id ?? 0);
                if ($object_id && $term_id && !in_array($term_id, $excluded_categories, true)) {
                    $terms_by_post[$object_id][] = $term_id;
                }
            }
        }
    }

    $ranked = [];
    foreach ($candidate_ids as $candidate_id) {
        $overlap = count(array_intersect($categories, array_values(array_unique($terms_by_post[$candidate_id] ?? []))));
        if ($overlap < 1) {
            continue;
        }
        $ranked[] = [
            'id' => $candidate_id,
            'overlap' => $overlap,
            'date' => (int) ($candidates[$candidate_id] ?? 0),
        ];
    }
    usort($ranked, static fn(array $a, array $b): int =>
        ($b['overlap'] <=> $a['overlap'])
        ?: ($b['date'] <=> $a['date'])
        ?: ($b['id'] <=> $a['id'])
    );
    $ids = array_slice(array_column($ranked, 'id'), 0, 3);

    if (count($ids) < 3) {
        $fill = get_posts([
            'post_type' => \SMP\Podcast\Settings\PodcastSettings::content_type(),
            'post_status' => 'publish',
            'posts_per_page' => 3 - count($ids),
            'post__not_in' => array_values(array_unique(array_merge($episode_id ? [$episode_id] : [], $ids))),
            'category__not_in' => $excluded_categories,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $ids = array_slice(array_values(array_unique(array_merge($ids, array_map('absint', $fill)))), 0, 3);
    }

    $query->set('post_type', \SMP\Podcast\Settings\PodcastSettings::content_type());
    $query->set('post_status', 'publish');
    $query->set('post__in', $ids !== [] ? $ids : [0]);
    $query->set('post__not_in', []);
    $query->set('posts_per_page', 3);
    $query->set('orderby', 'post__in');
    $query->set('order', 'ASC');
    $query->set('ignore_sticky_posts', true);
    $query->set('category__in', []);
    $query->set('category__not_in', []);
    // Keep the rendered Loop Grid's `found_posts` populated. Elementor 4.x
    // otherwise reports Nothing Found even when `post_count` contains all
    // three resolved posts. The bounded internal candidate queries above can
    // safely retain `no_found_rows=true` because Elementor never renders them.
    $query->set('no_found_rows', false);
    $query->set('update_post_meta_cache', true);
    $query->set('update_post_term_cache', true);
    } finally {
        $resolving = false;
    }
}
public static function apply_profile_query(WP_Query $query, string $relationship): void {
    static $resolving = false;
    if ($resolving) {
        return;
    }
    $resolving = true;
    try {
        $episode_id = self::context_id();
        $ids = self::relationship_ids($relationship, $episode_id);
        $query->set('post_type', self::guest_types() ?: ['__disabled']);
        $query->set('post_status', 'publish');
        $query->set('post__in', $ids !== [] ? $ids : [0]);
        $query->set('orderby', 'post__in');
        $query->set('order', 'ASC');
        $query->set('posts_per_page', max(1, count($ids)));
        $query->set('ignore_sticky_posts', true);
        // Elementor's Loop Grid tests `found_posts`, not `post_count`, before it
        // renders the Loop Item. The outer display query must therefore calculate
        // found rows even though its relationship ID set is intentionally small.
        $query->set('no_found_rows', false);
    } finally {
        $resolving = false;
    }
}

public static function profile_context_id( int $candidate = 0 ): int {
    $candidates = [ $candidate ];
    if ( function_exists( 'get_queried_object_id' ) ) {
        $candidates[] = (int) get_queried_object_id();
    }
    $candidates[] = (int) get_the_ID();

    try {
        if ( class_exists( 'Elementor\\Plugin' )
            && isset( \Elementor\Plugin::$instance->documents )
            && method_exists( \Elementor\Plugin::$instance->documents, 'get_current' ) ) {
            $document = \Elementor\Plugin::$instance->documents->get_current();
            if ( is_object( $document ) && method_exists( $document, 'get_settings' ) ) {
                $candidates[] = (int) $document->get_settings( 'preview_id' );
            }
        }
    } catch ( Throwable ) {
        // Preview resolution is optional; continue through the safe candidates.
    }

    $post = $GLOBALS['post'] ?? null;
    if ( $post instanceof WP_Post ) {
        $candidates[] = (int) $post->ID;
    }

    foreach ( array_values( array_unique( array_filter( array_map( 'absint', $candidates ) ) ) ) as $post_id ) {
        if (class_exists('SMP\\VerifiedProfiles\\ProfileTypes\\ProfileTypes') && \SMP\VerifiedProfiles\ProfileTypes\ProfileTypes::is_profile_type((string)get_post_type($post_id))) {
            return $post_id;
        }
    }
    return 0;
}

public static function profile_episode_ids( int $profile_id = 0 ): array {
    static $cache = [];
    $profile_id = self::profile_context_id( $profile_id );
    if ( $profile_id < 1 ) {
        return [];
    }
    if ( array_key_exists( $profile_id, $cache ) ) {
        return $cache[ $profile_id ];
    }

    $episodes = get_posts(\SMP\Podcast\Settings\PodcastSettings::scoped_query_args([
        'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
        'orderby' => 'date', 'order' => 'DESC',
    ]));
    $ids = array_values(array_filter(array_map('absint', $episodes), static function(int $id) use ($profile_id): bool {
        return in_array($profile_id, self::relationship_ids('profiles', $id), true)
            || in_array($profile_id, self::relationship_ids('hosts', $id), true);
    }));
    $cache[ $profile_id ] = $ids;
    return $ids;
}

public static function profile_apply_episode_query( WP_Query $query ): void {
    static $resolving = false;
    if ($resolving) { return; }
    $resolving = true;
    try {
    $ids = self::profile_episode_ids();
    $query->set( 'post_type', \SMP\Podcast\Settings\PodcastSettings::content_type() );
    $query->set( 'post_status', 'publish' );
    $query->set( 'post__in', $ids ?: [ 0 ] );
    $query->set( 'orderby', 'post__in' );
    $query->set( 'order', 'ASC' );
    $query->set( 'posts_per_page', max( 1, count( $ids ) ) );
    $query->set( 'ignore_sticky_posts', true );
    $query->set( 'no_found_rows', false );
    } finally { $resolving = false; }
}
}
