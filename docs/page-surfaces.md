# Page surfaces

Explicit shortcodes, no styles or scripts: `smp_episode_count`, `smp_podcast_metric` (name episode_count or guest_count, format number or footer), `smp_podcast_metrics` (optional platform_count and class), `smp_podcast_latest_episode` (class), and `smp_podcast_subject_table` (minimum, class). Labels are translated. Episode type is read from PodcastSettings. Standard fields and existing EpisodeData methods supply media and relationships. Markup classes are configurable for migration without shipping site design.

The optional `smp_podcast_profile_episode_schema` WordPress option defaults off. When enabled, the shared schema source contributes PodcastEpisode/PodcastSeries nodes and subjectOf references on configured guest types. Verified Profiles owns Person. This module adds no second script or Person producer.

## Primary host and publisher
The Primary Host picker retains the `default_host` storage key and existing selection. `PodcastSettings::primary_host_id()` returns only a published profile whose Verified Profiles schema type is Person, or zero. The legacy default_host_id method aliases it. Episode hosts remain explicit relationships; the primary host seeds new episodes only when empty. The canonical website entity identifies the publisher, not the host. PodcastSeries actor references the primary host profile Person ID; Verified Profiles owns that entity.
