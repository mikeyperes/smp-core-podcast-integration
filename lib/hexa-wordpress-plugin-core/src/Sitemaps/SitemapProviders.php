<?php
namespace Hexa\PluginCore\Sitemaps;

/**
 * Each plugin declares the sitemaps it owns. Every row then names its owner
 * and the owner's Sitemaps settings screen, so other screens show it read-only.
 */
final class SitemapProviders {
    public const FILTER = 'hexa_plugin_core_sitemap_providers';

    /**
     * $sitemaps: [['id'=>'post_type_profile','recommended'=>true|callable,'why'=>'...']].
     * A callable recommendation receives the row and returns a boolean.
     */
    public static function register(string $slug, string $plugin, string $settings_url, array $sitemaps): void {
        add_filter(self::FILTER, static function($providers) use ($slug, $plugin, $settings_url, $sitemaps) {
            $providers=(array)$providers;
            $providers[$slug]=['slug'=>$slug,'plugin'=>$plugin,'settings_url'=>$settings_url,'sitemaps'=>$sitemaps];
            return $providers;
        });
    }

    public static function all(): array {
        $out=[];
        foreach ((array)apply_filters(self::FILTER,[]) as $slug=>$p) {
            if (!is_array($p) || empty($p['plugin'])) continue;
            $out[(string)$slug]=['slug'=>(string)$slug,'plugin'=>(string)$p['plugin'],'settings_url'=>(string)($p['settings_url']??''),'sitemaps'=>array_values(array_filter((array)($p['sitemaps']??[]),'is_array'))];
        }
        return $out;
    }

    public static function has(string $slug): bool {
        return isset(self::all()[$slug]);
    }

    /** Owner fields and the owner's recommendation on every declared row that exists here. */
    public static function apply(array $rows): array {
        foreach ($rows as &$row) $row+=['owner'=>'','owner_label'=>'','owner_url'=>''];
        unset($row);
        foreach (self::all() as $slug=>$p) {
            foreach ($p['sitemaps'] as $def) {
                $id=(string)($def['id']??'');
                if (''===$id || !isset($rows[$id])) continue;
                $rec=$def['recommended']??true;
                $rows[$id]['owner']=$slug;
                $rows[$id]['owner_label']=$p['plugin'];
                $rows[$id]['owner_url']=$p['settings_url'];
                $rows[$id]['recommended']=is_callable($rec)?(bool)$rec($rows[$id]):(bool)$rec;
                $rows[$id]['recommended_by']=[$p['plugin']];
                if (!empty($def['why'])) $rows[$id]['why']=(string)$def['why'];
            }
        }
        return $rows;
    }
}
