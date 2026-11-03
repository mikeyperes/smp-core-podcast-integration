# Season fields

`[smp_season_field name="number"]` reads the current `podcast_season` archive. Outside a season archive, supply `term="123"` (term ID) or `term="season-slug"`. Numeric selectors resolve term IDs first, then numeric season slugs if no matching taxonomy term exists.

Supported names: `number`, `episode_count`, `first_date`, `last_date`, `date_range`. Dates use WordPress site timezone and `format="M j, Y"` by default. A range joins first and last dates with ` - `, or shows one date when both format to the same value.

The shared SeasonData payload caches the season number, count of published classified episodes, and first/last publish timestamps for one hour. WordPress post/term cache changes, season metadata writes, and content classification changes invalidate it. Drafts and editorial posts do not count.

Missing/invalid terms, unknown fields, missing numbers, and absent date bounds produce empty output. Existing empty seasons return `0` for episode_count. Output is escaped text with no wrapper or assets; Elementor owns its presentation.
