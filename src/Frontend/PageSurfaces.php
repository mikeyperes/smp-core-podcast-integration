<?php
namespace SMP\Podcast\Frontend;
use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;
/** Explicit shortcode surfaces. No automatic markup or site-specific copy. */
final class PageSurfaces implements ModuleInterface {
 public function register(): void {}
 public static function callbacks(): array { return [
 'smp_episode_count'=>[self::class,'episode_count'],
 'smp_podcast_metric'=>[self::class,'metric'],
 'smp_podcast_metrics'=>[self::class,'metrics'],
 'smp_podcast_latest_episode'=>[self::class,'latest'],
 'smp_podcast_subject_table'=>[self::class,'subjects'],
 ]; }
 public static function count(): int {return max(0,(int)(wp_count_posts(PodcastSettings::content_type())->publish??0));}
 public static function episode_count(array $atts=[]): string {return esc_html(number_format_i18n(self::count()));}
 public static function metric(array $atts=[]): string {
 $a=shortcode_atts(['name'=>'','format'=>'number'],$atts,'smp_podcast_metric');
 $guest=in_array($a['name'],['guest_count','guests'],true);
 if(!$guest&&!in_array($a['name'],['episode_count','episodes'],true))return '';
 $n=esc_html(number_format_i18n($guest?GuestCount::count():self::count()));
 return $a['format']==='footer'?'<b>'.$n.'</b><span>'.esc_html($guest?__('Guests','smp-core-podcast-integration'):__('Episodes','smp-core-podcast-integration')).'</span>':$n;
 }
 public static function metrics(array $atts=[]): string {
 $a=shortcode_atts(['platform_count'=>'','class'=>'smp-podcast-metrics'],$atts,'smp_podcast_metrics');
 $out='<ul class="'.esc_attr($a['class']).'"><li><b>'.self::episode_count().'</b><span>'.esc_html__('episodes','smp-core-podcast-integration').'</span></li><li><b>'.GuestCount::shortcode().'</b><span>'.esc_html__('guests','smp-core-podcast-integration').'</span></li>';
 if($a['platform_count']!=='')$out.='<li><b>'.esc_html($a['platform_count']).'</b><span>'.esc_html__('listening platforms','smp-core-podcast-integration').'</span></li>';
 return $out.'</ul>';
 }
 public static function latest(array $atts=[]): string {
 $a=shortcode_atts(['class'=>'smp-podcast-latest','show_deck'=>'no'],$atts,'smp_podcast_latest_episode');
 $ids=get_posts(['post_type'=>PodcastSettings::content_type(),'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','orderby'=>'date','order'=>'DESC','meta_query'=>[['key'=>'_thumbnail_id','compare'=>'EXISTS']],'no_found_rows'=>true]);
 $id=absint($ids[0]??0);if(!$id)return '';
 $image=get_the_post_thumbnail($id,'medium_large',['alt'=>sprintf(__('Episode artwork for %s','smp-core-podcast-integration'),get_the_title($id))]);if($image==='')return '';
 $deck=trim(wp_strip_all_tags(EpisodeData::field_shortcode(['name'=>'deck','post_id'=>$id])));
 $caption=sprintf(__('Latest episode — %1$s, published %2$s.','smp-core-podcast-integration'),'<a href="'.esc_url(get_permalink($id)).'">'.esc_html(get_the_title($id)).'</a>',esc_html(get_the_date('F j, Y',$id)));
 if($a['show_deck']==='yes'&&$deck!=='')$caption.=' '.esc_html($deck);
 return '<figure class="'.esc_attr($a['class']).'"><div class="'.esc_attr($a['class']).'__plate"><a href="'.esc_url(get_permalink($id)).'">'.$image.'</a></div><figcaption>'.wp_kses_post($caption).'</figcaption></figure>';
 }
 public static function subjects(array $atts=[]): string {
 $a=shortcode_atts(['minimum'=>5,'class'=>'smp-podcast-table-wrap'],$atts,'smp_podcast_subject_table');
 $minimum=max(1,absint($a['minimum']));
 $ids=get_posts(['post_type'=>PodcastSettings::content_type(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true]);
 $terms=$ids?wp_get_object_terms($ids,'category',['fields'=>'all_with_object_id']):[];if(is_wp_error($terms))return '';
 $objects=[];$unique=[];foreach($terms as $term){$objects[$term->term_id][$term->object_id]=true;$unique[$term->term_id]=$term;}
 $terms=array_values($unique);foreach($terms as $term)$term->count=count($objects[$term->term_id]);
 usort($terms,static fn($a,$b)=>$b->count<=>$a->count?:strcasecmp($a->name,$b->name));
 $body=[];foreach($terms as $term){if($term->term_id===absint(get_option('default_category'))||$term->slug==='uncategorized'||(int)$term->count<$minimum)continue;$url=get_term_link($term);$label=is_wp_error($url)?esc_html($term->name):'<a href="'.esc_url($url).'">'.esc_html($term->name).'</a>';$body[]='<tr><th scope="row">'.$label.'</th><td>'.esc_html(number_format_i18n($term->count)).'</td></tr>';}
 if(!$body)return '';
 $label=sprintf(__('Subjects with %s or more published episodes','smp-core-podcast-integration'),$minimum===5?'five':number_format_i18n($minimum));
 return '<div class="'.esc_attr($a['class']).'" tabindex="0" role="region" aria-label="'.esc_attr($label).'"><table><caption>'.esc_html(sprintf(__('%1$s, out of %2$s published in total.','smp-core-podcast-integration'),$label,number_format_i18n(self::count()))).'</caption><thead><tr><th scope="col">'.esc_html__('Subject','smp-core-podcast-integration').'</th><th scope="col">'.esc_html__('Episodes','smp-core-podcast-integration').'</th></tr></thead><tbody>'.implode('',$body).'</tbody></table></div>';
 }
}
