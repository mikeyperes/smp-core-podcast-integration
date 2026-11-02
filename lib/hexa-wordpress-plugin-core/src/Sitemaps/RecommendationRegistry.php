<?php
namespace Hexa\PluginCore\Sitemaps;

/** Shared recommendation engine. Reading never changes Rank Math. */
final class RecommendationRegistry {
    public const FILTER = 'hexa_plugin_core_sitemap_recommendations';
    public const OPTION = 'hexa_plugin_core_sitemap_recommendations';
    public const CONDITIONS = 'hexa_plugin_core_sitemap_conditions';

    public static function condition(string $key): array {
        $all = (array) get_option(self::CONDITIONS, []);
        return array_merge(['more_than' => 2, 'min_posts' => 1, 'apply' => false], (array) ($all[$key] ?? []));
    }

    /** Build and persist the complete, site-type-specific snapshot in one lookup. */
    public static function lookup(string $site_type = ''): array {
        $settings = (array) get_option('rank-math-options-sitemap', []);
        $modules = (array) get_option('rank_math_modules', []);
        $active = defined('RANK_MATH_VERSION');
        $enabled = $active && in_array('sitemap', $modules, true);
        $rows = [];
        $add = static function(string $id, string $label, string $kind, string $name, string $setting, string $path, bool $default) use (&$rows, $settings, $enabled) {
            $rows[$id] = ['id'=>$id,'label'=>$label,'kind'=>$kind,'name'=>$name,'url'=>home_url($path), 'setting'=>$setting,'recommended'=>$default,'recommended_by'=>['Site content'], 'current'=>$enabled && 'on' === ($settings[$setting] ?? ('attachment' === $name || ('taxonomy' === $kind && 'category' !== $name) ? 'off':'on')), 'available'=>true,'warnings'=>[]];
        };
        $add('index','Sitemap index','index','','','/sitemap_index.xml',true);
        $rows['index']['current']=$enabled;
        $types = get_post_types(['public'=>true], 'objects');
        foreach (['attachment','elementor_library','e-landing-page'] as $name) {
            if ($object=get_post_type_object($name)) $types[$name]=$object;
        }
        foreach ($types as $name=>$object) {
            $add('post_type_'.$name, $object->labels->name, 'post_type', $name, 'pt_'.$name.'_sitemap', '/'.$name.'-sitemap.xml', !in_array($name,['attachment','elementor_library','e-landing-page'],true));
        }
        foreach (get_taxonomies(['public'=>true], 'objects') as $name=>$object) {
            $id='taxonomy_'.$name;
            $add($id,$object->labels->name,'taxonomy',$name,'tax_'.$name.'_sitemap','/'.$name.'-sitemap.xml',!in_array($name,['post_format','product_tag'],true));
            $condition=self::condition($id);
            $counts=get_terms(['taxonomy'=>$name,'hide_empty'=>false,'fields'=>'count']);
            $total=is_wp_error($counts)?0:(int)$counts;
            $terms=get_terms(['taxonomy'=>$name,'hide_empty'=>false]);
            $shown=0;
            if (!is_wp_error($terms)) foreach ($terms as $term) if ($term->count >= $condition['min_posts']) ++$shown;
            $rows[$id]['condition']=$condition;
            $rows[$id]['counts']=['would_show'=>$shown,'total'=>$total];
            $rows[$id]['recommended']=$rows[$id]['recommended'] && $total > $condition['more_than'];
        }
        $add('authors','Authors','author','','authors_sitemap','/author-sitemap.xml',true);
        $condition=self::condition('authors');
        global $wpdb;
        $counts=$wpdb->get_col("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' GROUP BY post_author");
        $rows['authors']['condition']=$condition;
        $rows['authors']['counts']=['would_show'=>count(array_filter($counts, static fn($n)=>(int)$n >= $condition['min_posts'])),'total'=>count($counts)];
        foreach (['news'=>false,'video'=>true,'local'=>false] as $name=>$on) {
            $module='local'===$name?'local-seo':$name.'-sitemap';
            $add($name,ucfirst($name).' sitemap','module',$module,'','/'.$name.'-sitemap.xml',$on);
            $rows[$name]['current']=$active && in_array($module,$modules,true);
            $rows[$name]['available']=defined('RANK_MATH_PRO_VERSION');
        }
        $rows['local']['url']=home_url('/locations.kml');
        $rows=(array) apply_filters(self::FILTER,$rows,$site_type);
        foreach ($rows as $id=>&$row) {
            $row['id']=$id;
            $row['warnings']=[];
            $checks=(array)get_option('hexa_plugin_core_sitemap_checks',[]);
            $row['live_check']=$checks[$id]??null;
            if (!empty($row['recommended']) && isset($checks[$id]['response']['code']) && 200 !== $checks[$id]['response']['code']) $row['warnings'][]='Required sitemap did not answer HTTP 200.';
            if (!empty($row['recommended']) && empty($row['current'])) $row['warnings'][]='Required sitemap is off in Rank Math.';
            if (empty($row['recommended']) && !empty($row['current'])) $row['warnings'][]='A sitemap that is not recommended is on.';
            if (!empty($row['recommended']) && empty($row['available'])) $row['warnings'][]='Required sitemap provider is unavailable.';
        }
        unset($row);
        $result=['site'=>home_url('/'),'site_type'=>$site_type,'rank_math_active'=>$active,'rows'=>array_values($rows),'generated_at'=>gmdate('c')];
        $stored=(array)get_option(self::OPTION,[]);
        $stored[$site_type ?: 'current']=$result;
        update_option(self::OPTION,$stored,false);
        return $result;
    }

    /** Exact explicit administrator action only; never called from recommendations. */
    public static function change(string $id, bool $on): array {
        if (!defined('RANK_MATH_VERSION')) return ['ok'=>false,'message'=>'Rank Math is inactive.'];
        $rows=array_column(self::lookup()['rows'],null,'id');
        if (!isset($rows[$id])) return ['ok'=>false,'message'=>'Unknown sitemap.'];
        $row=$rows[$id];
        if ('module'===$row['kind'] || 'index'===$row['kind']) {
            if ($on && empty($row['available'])) return ['ok'=>false,'message'=>'Rank Math Pro is required.'];
            $module='index'===$row['kind']?'sitemap':$row['name'];
            $before=(array)get_option('rank_math_modules',[]);
            $after=array_values(array_diff($before,[$module]));
            if ($on) $after[]=$module;
            update_option('rank_math_modules',$after);
        } else {
            if (empty($row['setting'])) return ['ok'=>false,'message'=>'This provider has no writable Rank Math setting.'];
            $before=(array)get_option('rank-math-options-sitemap',[]);
            $after=$before;
            $after[$row['setting']]=$on?'on':'off';
            update_option('rank-math-options-sitemap',$after);
        }
        self::clear_cache();
        return ['ok'=>true,'id'=>$id,'on'=>$on,'message'=>'Rank Math updated and sitemap cache cleared.'];
    }
    public static function clear_cache(): void {
        if (class_exists('RankMath\\Sitemap\\Cache')) \RankMath\Sitemap\Cache::invalidate_storage();
    }
    public static function save_condition(string $id,array $input): array {
        $rows=array_column(self::lookup()['rows'],null,'id');
        if (!isset($rows[$id]) || !in_array($rows[$id]['kind'],['taxonomy','author'],true)) return ['ok'=>false,'message'=>'Conditions require a taxonomy or author sitemap.'];
        $all=(array)get_option(self::CONDITIONS,[]);
        $all[$id]=['more_than'=>max(0,(int)($input['more_than']??2)),'min_posts'=>max(0,(int)($input['min_posts']??1)),'apply'=>!empty($input['apply'])];
        update_option(self::CONDITIONS,$all,false);
        self::clear_cache();
        return ['ok'=>true,'condition'=>$all[$id]];
    }
    /** Only explicitly enabled conditions affect visitor output. */
    public static function entry($url,string $type,$object) {
        if ('term'===$type && $object instanceof \WP_Term) {
            $c=self::condition('taxonomy_'.$object->taxonomy);
            if ($c['apply'] && $object->count < $c['min_posts']) return false;
        }
        if ('user'===$type && $object instanceof \WP_User) {
            $c=self::condition('authors');
            if ($c['apply'] && count_user_posts($object->ID,'post',true) < $c['min_posts']) return false;
        }
        return $url;
    }
}
