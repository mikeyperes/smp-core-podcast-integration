# Sitemap recommendation engine

The `Sitemaps` namespace is opt-in through `SitemapModule` in a host CoreBootstrap.
Multiple hosts register it safely once. No recommendation read writes Rank Math.

## JSON endpoints (Parts 1 and 2)

All REST routes require `manage_options`. Cookie authentication requires `X-WP-Nonce` (wp_rest); WordPress application passwords also work. No public or nopriv handlers.

- GET `/wp-json/hexa-core/v1/sitemaps?site_type=publication`: no body. Returns `{site,site_type,rank_math_active,generated_at,rows:[{id,label,kind,name,url,setting,recommended,recommended_by,current,available,warnings,condition?,counts?,live_check?}]}`. The empty site_type denotes current site. Named types are passed to host recommendation filters, not treated as another site's live settings. Current Rank Math settings and counts always refer to this site.
- POST `/wp-json/hexa-core/v1/sitemaps/check`: `{id}`. Returns `{id,url,result}` with the actual server response. Host filter `hexa_plugin_core_sitemap_check` can reuse an existing checker. Results persist separately.
- POST `/wp-json/hexa-core/v1/sitemaps/change`: `{id,on:boolean}`. Returns `{ok,id,on,message}`. Writes only that registered Rank Math setting/module, then invalidates Rank Math sitemap storage. Never called automatically. Errors return `{ok:false,message}`.
- POST `/wp-json/hexa-core/v1/sitemaps/conditions`: `{id,more_than:2,min_posts:1,apply:false}`. Returns `{ok,condition}`. Whole taxonomy recommendations require more than `more_than` terms. Counts include all existing terms; would_show counts those meeting min_posts. `apply:true` explicitly enables Rank Math sitemap/entry exclusions; defaults never change output. Author counts are published post authors, and min_posts counts published posts.

AJAX equivalents at admin-ajax.php: `hexa_sitemaps_lookup`, `hexa_sitemaps_check`, `hexa_sitemaps_change`, `hexa_sitemaps_conditions`. Require administrator capability and nonce action `hexa_sitemaps` in field `nonce`. AJAX returns WordPress `{success,data}`. Change uses `on=1|0`. Conditions uses `apply=1` or empty, with the same numeric fields.

WP-CLI JSON: `wp hexa sitemaps list [--site-type=publication]`, `check --id=index`, `change --id=post_type_page --on=1`, `conditions --id=taxonomy_category --more-than=2 --min-posts=3 [--apply]`. CLI uses the trusted operating-system/site account as WordPress CLI normally does.

## Host extension contract

Filter `hexa_plugin_core_sitemap_recommendations` receives the complete associative row map and site_type. Hosts change only recommendation fields for registered types, and can add complete rows for additional providers. `recommended_by` is a list of plugin names. `setting` is the exact Rank Math sitemap option; `kind=module` uses name as module slug. Unwritable external providers use an empty setting. Base Tools bridges its documented `hws_base_tools_sitemap_recommendations` filter into this shared filter.

Snapshots: option `hexa_plugin_core_sitemap_recommendations`, keyed by current/named type. Conditions and live checks use separate options. All options are non-autoloaded. Snapshots refresh per lookup, so plugin activation and new content appear immediately without changing Rank Math.
