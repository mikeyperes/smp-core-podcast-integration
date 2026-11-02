# Episode data shortcodes and related queries

Only explicitly configured shortcodes and Elementor query IDs produce output. No styles or scripts are added. Existing site CSS classes remain unchanged for migration compatibility.

- `smp_episode_field`: migrated episode field, retaining existing attributes and empty-data behavior.
- `smp_episode_audio_url`: migrated episode audio url, retaining existing attributes and empty-data behavior.
- `smp_episode_video_url`: migrated episode video url, retaining existing attributes and empty-data behavior.
- `smp_episode_platform_url`: migrated episode platform url, retaining existing attributes and empty-data behavior.
- `smp_episode_platforms`: migrated episode platforms, retaining existing attributes and empty-data behavior.
- `smp_episode_takeaways`: migrated episode takeaways, retaining existing attributes and empty-data behavior.
- `smp_episode_primary_category`: migrated episode primary category, retaining existing attributes and empty-data behavior.
- `smp_episode_label`: migrated episode label, retaining existing attributes and empty-data behavior.
- `smp_episode_details`: migrated episode details, retaining existing attributes and empty-data behavior.
- `smp_episode_archive_url`: migrated episode archive url, retaining existing attributes and empty-data behavior.
- `smp_episode_host_links`: migrated episode host links, retaining existing attributes and empty-data behavior.
- `smp_episode_guest_fallback`: migrated episode guest fallback, retaining existing attributes and empty-data behavior.

Use existing `smp_listen_button` with `label="Listen to the episode" class="mpp-episode-listen"`. The existing media resolvers and `podcast_url` provide player and platform destinations. `episode_fields` remains the raw stored-field shortcode; `smp_episode_field` provides parsed deck/conversation and relationship counts.

`smp_episode_related` ranks category overlap, date and ID, fills to three, excludes the current episode, and preserves the re-entry guard and outer found rows.

HTML: takeaways use ul/li, platforms nav/a, details dl/div/dt/dd, host links a, primary category a (or text with linked="no"), fallback uses the existing figure and caption structure. All text is escaped; no plugin styling is injected. See EpisodeData.php for complete attributes and markup.

## Attributes

All data tags accept `post_id` (default 0 resolves current episode, then queried object, then Elementor preview). `field` accepts `name`: deck, conversation, guest_count, host_count, video_url, audio_url, guest_name. `label` accepts `name`: takeaways, record_heading, related_eyebrow, related_heading, view_all. `platform_url` accepts `service`: spotify, apple, youtube, amazon, audible, tunein, soundcloud, rss. `primary_category` accepts `linked` (default yes). Takeaways and guest fallback retain their existing wrapper and empty-output rules.

Platform links use the existing `podcast_url` show destination first, then saved episode URL, with the podcast RSS route as fallback. Host links and platform/detail presentation differ from the older raw relationship/field tags, so they have explicit presentation tags. The listening button uses the existing player tag rather than a duplicate implementation. No content, host defaults, or relationship storage is rewritten.

## Episode presence

`[smp_episode_presence field="audio" post_id="0"]` returns `1` or an empty string. The legacy `mpp_episode_presence` name is an alias owned here, independent of the site helper. Fields: deck, conversation, takeaways, hosts, guest_profiles, guest_fallback, guests, people, people_separator, category, video, audio, player, record, episode. All resolve the same current-loop, singular or preview context as episode output.

These checks require logic, not raw metadata presence: content sections are parsed; relationships must resolve valid profiles; category excludes the default and uncategorized terms; media uses valid URLs and existing fallbacks; audio also requires the listening-button registration. Plain custom-field conditions should be used only where their raw value is the actual display contract.
