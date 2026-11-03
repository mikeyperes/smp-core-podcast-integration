# Page surfaces

Explicit shortcodes, no styles or scripts: `smp_episode_count`, `smp_podcast_metric` (name episode_count or guest_count, format number or footer), `smp_podcast_metrics` (optional platform_count and class), `smp_podcast_latest_episode` (class), and `smp_podcast_subject_table` (minimum, class). Labels are translated. Episode type is read from PodcastSettings. Standard fields and existing EpisodeData methods supply media and relationships. Markup classes are configurable for migration without shipping site design.

The optional `smp_podcast_profile_episode_schema` WordPress option defaults off. When enabled, the shared schema source contributes PodcastEpisode/PodcastSeries nodes and subjectOf references on configured guest types. Verified Profiles owns Person. This module adds no second script or Person producer.
