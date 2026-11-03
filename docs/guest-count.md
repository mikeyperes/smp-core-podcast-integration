# Guest count

Place `[smp_guest_count]` in a Shortcode widget or a native heading using Elementor's Shortcode dynamic tag. It returns only an escaped, localized number and loads no CSS or JavaScript.

The count includes each distinct linked guest profile ID across published episodes of the configured podcast content type. An episode without linked profiles contributes its fallback guest name, deduplicated by sanitized name. Linked IDs and fallback names remain separate sets, matching the established counting rule. EpisodeData owns relationship and fallback resolution.

The one-hour transient includes zero and is scoped to the configured episode type. Episode save, relationship or fallback metadata changes, and deletion invalidate it. No site IDs or automatic visitor output.
