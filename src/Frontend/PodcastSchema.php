<?php
namespace SMP\Podcast\Frontend;
use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\SchemaTools\{SchemaPage,SchemaNodes,SchemaSources,SchemaIds};
use SMP\Podcast\Settings\PodcastSettings;
use SMP\Podcast\Content\EpisodeMetadata;

/** Podcast graph contribution. Every relation uses the shared stable ID convention. */
final class PodcastSchema implements ModuleInterface {
    public function register(): void { SchemaPage::register('smp-podcast', [self::class,'nodes'], 5); }
    public static function nodes(): array {
        if(!get_option(EpisodeMetadata::SCHEMA,false))return [];
        $episode=is_singular(PodcastSettings::content_type()) && PodcastSettings::is_podcast_content((int)get_queried_object_id());
        $season=is_tax(EpisodeMetadata::TAXONOMY);
        if(!is_front_page() && !$episode && !$season)return [];
        $home=SchemaIds::home();$series=SchemaIds::for_url($home,'podcast-series');$feed=home_url('/feed/podcast/');
        $publisher=SchemaIds::organization();
        $host=PodcastSettings::primary_host_id();
        $nodes=[SchemaNodes::organization(['@id'=>$publisher,'name'=>get_bloginfo('name'),'url'=>$home])];
        $nodes[]=SchemaNodes::podcast_series([
            'url'=>$home,'name'=>get_bloginfo('name'),'description'=>get_bloginfo('description'),
            'publisher'=>SchemaNodes::ref($publisher),'webFeed'=>SchemaNodes::ref(SchemaIds::for_url($feed,'datafeed')),
            'actor'=>$host ? SchemaNodes::ref(SchemaIds::for_url((string)get_permalink($host),'person')) : null,
            'inLanguage'=>SchemaSources::language(),
        ]);
        $nodes[]=SchemaNodes::data_feed(['url'=>$feed,'name'=>get_bloginfo('name').' podcast RSS feed','encodingFormat'=>'application/rss+xml','about'=>SchemaNodes::ref($series)]);
        if($host)$nodes[]=self::person($host);
        $term=$season?get_queried_object():($episode?EpisodeMetadata::season((int)get_queried_object_id()):null);
        if($term instanceof \WP_Term){
            $url=get_term_link($term);
            if(!is_wp_error($url))$nodes[]=SchemaNodes::podcast_season(['url'=>$url,'name'=>$term->name,'description'=>wp_strip_all_tags($term->description),'seasonNumber'=>(int)get_term_meta($term->term_id,'season_number',true),'partOfSeries'=>SchemaNodes::ref($series),'publisher'=>SchemaNodes::ref($publisher)]);
        }
        if($episode){
            $id=(int)get_queried_object_id();$url=(string)get_permalink($id);$parts=EpisodeData::content_parts($id);
            $duration=(int)get_post_meta($id,'duration_seconds',true);$audio=EpisodeData::audio_url($id);
            $common=['name'=>wp_strip_all_tags(get_the_title($id)),'description'=>($parts['deck']??'')?:SchemaSources::post_description(get_post($id)),
                'uploadDate'=>get_post_time(DATE_W3C,true,$id),'thumbnailUrl'=>get_the_post_thumbnail_url($id,'full')?:null];
            $media=[];
            if($audio && !get_post_meta($id,'_smp_audio_metadata_error',true)){$media[]=SchemaNodes::ref(SchemaIds::for_url($url,'audio'));$nodes[]=SchemaNodes::audio_object($common+[
                '@id'=>SchemaIds::for_url($url,'audio'),'contentUrl'=>$audio,'encodingFormat'=>'audio/mpeg',
                'duration'=>$duration?'PT'.$duration.'S':null,'contentSize'=>get_post_meta($id,'audio_file_size',true)?get_post_meta($id,'audio_file_size',true).' bytes':null]);}
            $video=VideoSourceResolver::resolve($id);
            if(!empty($video['id'])){
                $vid=$video['id'];$media[]=SchemaNodes::ref(SchemaIds::for_url($url,'video'));
                $nodes[]=SchemaNodes::video_object(array_replace($common,['@id'=>SchemaIds::for_url($url,'video'),'embedUrl'=>'https://www.youtube.com/embed/'.$vid,'thumbnailUrl'=>'https://i.ytimg.com/vi/'.$vid.'/hqdefault.jpg']));
            }
            $actors=[];$contributors=[];
            foreach(['hosts','guests'] as $field){
                foreach((array)get_post_meta($id,$field,true) as $person){$pid=is_object($person)?(int)$person->ID:(int)$person;if(!$pid || !get_post($pid))continue;
                    $nodes[]=self::person($pid);$ref=SchemaNodes::ref(SchemaIds::for_url((string)get_permalink($pid),'person'));
                    if($field==='hosts')$actors[]=$ref;else $contributors[]=$ref;
                }
            }
            $season_url=$term instanceof \WP_Term?get_term_link($term):null;
            $nodes[]=SchemaNodes::podcast_episode([
                'url'=>$url,'name'=>$common['name'],'description'=>$common['description'],
                'datePublished'=>get_post_time(DATE_W3C,true,$id),'dateModified'=>get_post_modified_time(DATE_W3C,true,$id),
                'episodeNumber'=>(int)get_post_meta($id,'number',true)?:null,'duration'=>$duration?'PT'.$duration.'S':null,
                'partOfSeries'=>SchemaNodes::ref($series),'partOfSeason'=>is_string($season_url)?SchemaNodes::ref(SchemaIds::for_url($season_url,'podcast-season')):null,
                'associatedMedia'=>$media,'actor'=>$actors,'contributor'=>$contributors,'publisher'=>SchemaNodes::ref($publisher),'image'=>SchemaSources::post_image($id),
                'mainEntityOfPage'=>SchemaNodes::ref(SchemaIds::webpage($url)),
            ]);
        }
        return $nodes;
    }
    private static function person(int $id): array {return SchemaNodes::person(['@id'=>SchemaIds::for_url((string)get_permalink($id),'person'),'name'=>get_the_title($id),'url'=>get_permalink($id)]);}
}
