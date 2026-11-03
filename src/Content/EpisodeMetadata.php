<?php
namespace SMP\Podcast\Content;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Settings\PodcastSettings;
use SMP\Podcast\Frontend\AudioSourceResolver;

/** Seasons and measured media metadata are independent of presentation and schema output. */
final class EpisodeMetadata implements ModuleInterface {
    public const TAXONOMY = 'podcast_season';
    public const AUTO = 'smp_podcast_auto_episode_numbers';
    public const SCHEMA = 'smp_podcast_schema_enabled';

    public function register(): void {
        add_action('init', [$this, 'taxonomy']);
        add_action('wp_after_insert_post', [$this, 'saved'], 90, 4);
        \Hexa\PluginCore\Fields\Hooks::on('save_post', [$this, 'saved_fields'], 90);
        \Hexa\PluginCore\Fields\Hooks::on('load_value/name=podcast_season', [$this, 'default_season'], 10, 3);
        add_filter('hexa_plugin_core_content_schema_type', [$this, 'declare_content'], 10, 2);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_smp_podcast_metadata_settings', [$this, 'save_settings']);
        add_filter('smp_podcast_dashboard_tabs', [$this, 'tab']);
        add_filter('smp_podcast_render_dashboard_tab', [$this, 'render_tab'], 10, 2);
        add_action('rss2_item', [$this, 'rss_tags'], 90);
        add_action('rss2_item_powerpress', [$this, 'rss_tags'], 90);
        add_action('pre_get_posts', [$this, 'feed_query'], 99);
        add_filter('post_limits', [$this, 'feed_limits'], 99, 2);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('smp-podcast backfill', [$this, 'backfill']);
            \WP_CLI::add_command('smp-podcast required-pages', [$this, 'required_pages']);
        }
    }

    public function taxonomy(): void {
        register_taxonomy(self::TAXONOMY, [PodcastSettings::content_type()], [
            'label'=>'Podcast Seasons', 'public'=>true, 'show_in_rest'=>true,
            'hierarchical'=>false, 'rewrite'=>['slug'=>'season','with_front'=>false],
            'meta_box_cb'=>false,
        ]);
        register_term_meta(self::TAXONOMY, 'season_number', [
            'type'=>'integer','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'absint',
            'auth_callback'=>static fn()=>current_user_can('manage_categories'),
        ]);
        \Hexa\PluginCore\Fields\FieldGroups::add([
            'key'=>'group_smp_podcast_season', 'title'=>'Season details',
            'fields'=>[['key'=>'field_smp_season_number','name'=>'season_number','label'=>'Season number','type'=>'number','min'=>1]],
            'location'=>[[['param'=>'taxonomy','operator'=>'==','value'=>self::TAXONOMY]]],
        ]);
    }

    public function declare_content(string $type, int $id): string {
        return PodcastSettings::is_podcast_content($id) ? 'PodcastEpisode' : ($type ?: (ContentKind::is_article($id) ? 'Article' : ''));
    }

    public static function season(int $id): ?\WP_Term {
        $terms = wp_get_object_terms($id, self::TAXONOMY);
        return !is_wp_error($terms) && $terms ? $terms[0] : null;
    }

    public static function season_number(int $id): int {
        $term = self::season($id);
        return $term ? (int)get_term_meta($term->term_id, 'season_number', true) : 0;
    }

    public static function ensure_season(int $number, string $description=''): int {
        $term = get_term_by('slug', (string)$number, self::TAXONOMY);
        if ($term) return (int)$term->term_id;
        $result = wp_insert_term('Season '.$number, self::TAXONOMY, ['slug'=>(string)$number,'description'=>$description]);
        if (is_wp_error($result)) throw new \RuntimeException($result->get_error_message());
        $id=(int)$result['term_id']; update_term_meta($id,'season_number',$number); return $id;
    }

    public function default_season($value, $post_id=0, $field=[]): mixed {
        if ($value) return $value;
        $terms=get_terms(['taxonomy'=>self::TAXONOMY,'hide_empty'=>false,'meta_key'=>'season_number','orderby'=>'meta_value_num','order'=>'DESC','number'=>1]);
        return !is_wp_error($terms) && $terms ? $terms[0]->term_id : $value;
    }

    public function saved_fields($id): void { if(is_numeric($id)) $this->saved((int)$id); }
    public function saved(int $id, $post=null, $update=false, $before=null): void {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !PodcastSettings::is_podcast_content($id)) return;
        if (!self::season($id)) {
            $legacy=(string)get_post_meta($id,'season',true);
            $number=preg_match('/(?:season\s*)?(\d+)/i',$legacy,$m)?(int)$m[1]:0;
            $term=$number ? self::ensure_season($number) : (int)$this->default_season(null);
            if($term) wp_set_object_terms($id,[$term],self::TAXONOMY);
        }
        if(get_post_status($id)==='publish' && get_option(self::AUTO,false)) self::assign_numbers();
        self::measure($id);
        \SMP\Podcast\Integrations\PowerPressSync::sync_audio($id);
    }

    /** Deterministic publish-date order, preserving every existing positive number. */
    public static function assign_numbers(): int {
        global $wpdb;
        $lock=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $wpdb->prefix.'smp_episode_numbers'));
        if ((string)$lock !== '1') return 0;
        try {
            $ids=self::episode_ids(); $used=[]; $changed=0;
            foreach($ids as $id){$n=(int)get_post_meta($id,'number',true);if($n>0)$used[$n]=true;}
            $next=1;
            foreach($ids as $id){
                $existing=(int)get_post_meta($id,'number',true);
                if($existing>0){$next=max($next,$existing+1);continue;}
                while(isset($used[$next]))$next++;
                update_post_meta($id,'number',$next);$used[$next++]=true;$changed++;
            }
            return $changed;
        } finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $wpdb->prefix.'smp_episode_numbers'));}
    }

    public static function episode_ids(): array {
        return get_posts(PodcastSettings::scoped_query_args(['post_status'=>'publish','posts_per_page'=>-1,'orderby'=>['date'=>'ASC','ID'=>'ASC'],'fields'=>'ids']));
    }

    /** WordPress getID3 metadata only, never infer duration from a guessed bitrate. */
    public static function measure(int $id): bool {
        $audio=AudioSourceResolver::resolve($id); $url=$audio['download_url']??'';
        $attachment=(int)get_post_meta($id,'audio',true);
        if(!$attachment && $url)$attachment=attachment_url_to_postid($url);
        $file=$attachment ? get_attached_file($attachment) : '';
        if(!$file || !is_file($file)){
            $uploads=wp_get_upload_dir();
            if($url && str_starts_with($url,$uploads['baseurl'].'/')){
                $candidate=$uploads['basedir'].'/'.rawurldecode(substr($url,strlen($uploads['baseurl'])+1));
                $real=realpath($candidate);$base=realpath($uploads['basedir']);
                if($real && $base && str_starts_with($real,$base.DIRECTORY_SEPARATOR))$file=$real;
            }
        }
        if(!$file || !is_file($file)) return false;
        $signature=hash('sha256',$file.':'.filesize($file).':'.filemtime($file));
        if(get_post_meta($id,'_smp_audio_signature',true)===$signature && get_post_meta($id,'duration_seconds',true))return true;
        require_once ABSPATH.'wp-admin/includes/media.php';
        $meta=wp_read_audio_metadata($file);$seconds=(int)round($meta['length']??0);
        if($seconds<=0){update_post_meta($id,'_smp_audio_metadata_error','WordPress could not read audio duration');delete_post_meta($id,'duration_seconds');return false;}
        delete_post_meta($id,'_smp_audio_metadata_error');
        update_post_meta($id,'duration_seconds',$seconds);
        update_post_meta($id,'duration',gmdate($seconds>=3600?'H:i:s':'i:s',$seconds));
        update_post_meta($id,'audio_file_size',(int)filesize($file));
        update_post_meta($id,'_smp_audio_signature',$signature);
        if($attachment && is_array($meta))wp_update_attachment_metadata($attachment,$meta);
        return true;
    }

    public function backfill(array $args, array $assoc): void {
        $measured=0;$missing=[];$seasons=0;
        if(isset($assoc['calendar-start'])){for($y=(int)$assoc['calendar-start'];$y<=(int)wp_date('Y');$y++)self::ensure_season($y-(int)$assoc['calendar-start']+1,(string)$y);}
        foreach(self::episode_ids() as $id){
            if(isset($assoc['calendar-start'])){
                $year=(int)get_post_time('Y',false,$id);$n=$year-(int)$assoc['calendar-start']+1;
                if($n>0){wp_set_object_terms($id,[self::ensure_season($n,(string)$year)],self::TAXONOMY);$seasons++;}
            }elseif(!self::season($id)){
                $legacy=(string)get_post_meta($id,'season',true);
                if(preg_match('/(\d+)/',$legacy,$m))wp_set_object_terms($id,[self::ensure_season((int)$m[1])],self::TAXONOMY);
            }
            if(self::measure($id))$measured++;else $missing[]=$id;
        }
        $numbers=self::assign_numbers();
        foreach(self::episode_ids() as $id)\SMP\Podcast\Integrations\PowerPressSync::sync_audio($id);
        \WP_CLI::log(wp_json_encode(['episodes'=>count(self::episode_ids()),'seasons_assigned'=>$seasons,'numbers_assigned'=>$numbers,'durations_measured'=>$measured,'missing_media'=>$missing]));
    }

    public function required_pages(array $args, array $assoc): void {
        $manager=\SMP\Podcast\Pages\PageTypes::manager();
        $result=$manager->ensure_pages();
        $existing=get_posts(['post_type'=>'elementor_library','post_status'=>['publish','draft'],'meta_key'=>'_smp_podcast_season_placeholder','meta_value'=>'1','fields'=>'ids']);
        if(!$existing && isset($assoc['create-season-template'])){
            $id=wp_insert_post(['post_type'=>'elementor_library','post_status'=>'draft','post_title'=>'Podcast Season Archive (placeholder)'],true);
            if(is_wp_error($id))\WP_CLI::error($id->get_error_message());
            update_post_meta($id,'_elementor_template_type','archive');
            update_post_meta($id,'_elementor_edit_mode','builder');
            update_post_meta($id,'_elementor_data','[]');
            update_post_meta($id,'_elementor_conditions',['include/taxonomy/podcast_season']);
            update_post_meta($id,'_smp_podcast_season_placeholder','1');
            $existing=[$id];
        }
        \WP_CLI::log(wp_json_encode(['pages'=>$result,'season_template'=>$existing]));
    }

    public function settings(): void {
        foreach([self::AUTO,self::SCHEMA] as $key)register_setting('smp_podcast_metadata',$key,['type'=>'boolean','sanitize_callback'=>'rest_sanitize_boolean','default'=>false]);
    }
    public function tab(array $tabs): array {$tabs['metadata']=['label'=>'Seasons & Schema','group'=>'podcast'];return $tabs;}
    public function render_tab($handled, string $tab): bool {
        if($tab!=='metadata')return (bool)$handled;
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('smp_podcast_metadata');echo '<input type="hidden" name="action" value="smp_podcast_metadata_settings">';
        foreach([self::AUTO=>'Auto-assign episode numbers',self::SCHEMA=>'Podcast schema enabled'] as $key=>$label){
            echo '<h3>'.esc_html($label).'</h3><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(get_option($key,false),true,false).'> Enabled</label>';
        }
        submit_button('Save podcast metadata settings');echo '</form><p><a href="'.esc_url(admin_url('edit-tags.php?taxonomy=podcast_season&post_type='.PodcastSettings::content_type())).'">Manage seasons</a></p>';return true;
    }
    public function save_settings(): void {
        if(!current_user_can('manage_options'))wp_die('Permission denied');check_admin_referer('smp_podcast_metadata');
        foreach([self::AUTO,self::SCHEMA] as $key)update_option($key,isset($_POST[$key]));
        wp_safe_redirect(admin_url('options-general.php?page=smp-core-podcast-integration&tab=metadata'));exit;
    }
    public function feed_query($query): void {
        if($query->is_feed('podcast')){
            $query->set('posts_per_rss',PHP_INT_MAX);$query->set('posts_per_page',-1);
            $query->set('post_type',PodcastSettings::content_type());
            $query->set('meta_query',[PodcastSettings::content_scope_clause()]);
        }
    }
    public function feed_limits(string $limits, $query): string {return $query->is_feed('podcast') ? '' : $limits;}
    public function rss_tags(): void {
        static $printed=[];$id=(int)get_the_ID();
        if(isset($printed[$id]) || !PodcastSettings::is_podcast_content($id))return;$printed[$id]=true;
        // PowerPress owns these tags when its serialized enclosure contains them.
        $data=function_exists('powerpress_get_enclosure_data')?powerpress_get_enclosure_data($id):[];
        $season=self::season_number($id);$number=(int)get_post_meta($id,'number',true);
        if($season && empty($data['season']))echo '<itunes:season>'.$season.'</itunes:season>'."\n";
        if($number && empty($data['episode_no']))echo '<itunes:episode>'.$number.'</itunes:episode>'."\n";
    }
}
