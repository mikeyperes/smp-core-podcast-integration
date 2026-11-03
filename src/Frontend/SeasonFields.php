<?php
namespace SMP\Podcast\Frontend;

use SMP\Podcast\Content\SeasonData;

/** Plain escaped values only. No output without a real season context. */
final class SeasonFields {
    public static function shortcode(array $atts = []): string {
        $atts = shortcode_atts(['name'=>'','term'=>'','format'=>'M j, Y'], $atts, 'smp_season_field');
        $name = (string)$atts['name'];
        if (!in_array($name, ['number','date_range','episode_count','first_date','last_date'], true)) return '';
        $term = SeasonData::term($atts['term']);
        if (!$term) return '';
        $data = SeasonData::get((int)$term->term_id);
        if (!$data) return '';
        if ($name === 'number') return $data['number'] > 0 ? esc_html((string)$data['number']) : '';
        if ($name === 'episode_count') return esc_html((string)$data['episode_count']);
        $format = is_scalar($atts['format']) ? (string)$atts['format'] : 'M j, Y';
        $first = $data['first_date'] ? wp_date($format, $data['first_date']) : '';
        $last = $data['last_date'] ? wp_date($format, $data['last_date']) : '';
        if ($name === 'first_date') return esc_html($first);
        if ($name === 'last_date') return esc_html($last);
        return $first !== '' && $last !== '' ? esc_html($first === $last ? $first : $first.' - '.$last) : '';
    }
}
