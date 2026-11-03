<?php
namespace SMP\Podcast\Frontend;
use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\SchemaTools\{SchemaPage,SchemaNodes,SchemaSources,SchemaIds};
/** Podcast relationships supplement the profile owner's Person node by shared ID. */
final class ProfileEpisodesSchema implements ModuleInterface {
 public const OPTION='smp_podcast_profile_episode_schema';
 public function register(): void {SchemaPage::register('smp-podcast-profile-episodes',[self::class,'nodes'],20);}
 public static function nodes(): array {
 if(!get_option(self::OPTION,false)||!is_singular(EpisodeData::guest_types()))return [];
 $id=(int)get_queried_object_id();$url=(string)get_permalink($id);$person=SchemaIds::for_url($url,'person');$home=trailingslashit(home_url('/'));$series=$home.'#podcast-series';
 $nodes=[];$refs=[];
 foreach(EpisodeData::profile_episode_ids($id) as $episode){
 $episode=(int)$episode;$p=get_post($episode);if(!$p||$p->post_status!=='publish')continue;
 $u=(string)get_permalink($episode);$parts=EpisodeData::content_parts($episode);$audio=EpisodeData::audio_url($episode);
 $node=SchemaNodes::node('PodcastEpisode',[
 'url'=>$u,'name'=>wp_strip_all_tags(get_the_title($episode)),
 'datePublished'=>get_post_time(DATE_W3C,true,$episode),'dateModified'=>get_post_modified_time(DATE_W3C,true,$episode),
 'partOfSeries'=>SchemaNodes::ref($series),'contributor'=>SchemaNodes::ref($person),
 'description'=>$parts['deck']??'', 'image'=>SchemaSources::post_image($episode),
 'associatedMedia'=>$audio!==''?SchemaNodes::node('AudioObject',['contentUrl'=>$audio]):null,
 ],$u.'#podcast-episode');$nodes[]=$node;$refs[]=SchemaNodes::ref($u.'#podcast-episode');
 }
 $entity=\Hexa\PluginCore\EntitySources\CanonicalEntityResolver::resolve();
 $publisher=is_array($entity)&&($entity['entity_type']??'')==='person'&&!empty($entity['attached_user_id'])?SchemaIds::person():SchemaIds::organization();
 $host=\SMP\Podcast\Settings\PodcastSettings::primary_host_id();
 $nodes[]=SchemaNodes::node('PodcastSeries',['url'=>$home,'name'=>get_bloginfo('name'),'description'=>get_bloginfo('description'),'publisher'=>SchemaNodes::ref($publisher),'actor'=>$host?SchemaNodes::ref(SchemaIds::for_url((string)get_permalink($host),'person')):null],$series);
 if($refs)$nodes[]=['@type'=>'Person','@id'=>$person,'subjectOf'=>$refs];
 return $nodes;
 }
}
