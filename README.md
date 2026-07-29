# Scale My Podcast - Core Functionality

WordPress integration for podcast content, ACF field structures, profile relationships, PowerPress metadata, feeds, shortcodes, and release diagnostics.

## Requirements

- WordPress 6.2 or newer
- PHP 8.0 or newer
- Advanced Custom Fields Pro
- PowerPress for enclosure synchronization and the public podcast feed
- SMP Verified Profiles for profile-backed hosts and guests

Only ACF is required for the field layer. Optional integrations never prevent the settings dashboard or unrelated runtime features from loading.

## Architecture

- `smp-core-podcast-integration.php`: canonical WordPress bootstrap and updater configuration
- `initialization.php`: compatibility entrypoint for installations activated under the old basename
- `src/Bootstrap`: Core module registration and registries
- `src/Admin`: Hexa WP Core dashboard and authenticated operations
- `src/Acf`: canonical podcast field groups with stable ACF keys
- `src/Content`: content and default-host behavior
- `src/Frontend`: shortcodes, displays, and feeds
- `src/Integrations`: PowerPress synchronization
- `src/Diagnostics`: shared Hexa integration tests
- `lib/hexa-wordpress-plugin-core`: bundled shared UI, updater, registry, AJAX, and test runtime

The plugin defaults to existing WordPress posts. It does not migrate podcast archives to a new post type. A dedicated `episode` content model can be selected intentionally from the dashboard.

## Compatibility Contracts

- Existing post, option, ACF, and PowerPress metadata remains in place.
- ACF group keys `group_6844c5d5cf57f` and `group_6848b7b0247cc` remain canonical.
- All nine legacy shortcode tags remain registered.
- The old active basename `smp-core-podcast-integration/initialization.php` migrates to the canonical plugin file automatically.
- The public PowerPress feed remains `/feed/podcast/`.
- Optional internal integration feed: `/feed/internal-rss/`.

## Admin

Open **Settings > Scale My Podcast**. The dashboard uses Hexa WP Core for sidebar tabs, collapsible structures, content-type and ACF controls, snippets, shortcodes, update panels, dynamic buttons, and integration tests.

## Testing

Run the repository checks:

```bash
php tests/run.php
```

On an authenticated WordPress installation, use **Tools > Hexa Integration Tests** and filter to `smp-core-podcast-integration`.

## Release History

### 3.0.2

- Fixed the AJAX-rendered ACF settings form so saves return to the canonical Podcast Settings tab instead of `admin-ajax.php`

### 3.0.1

- Replaced six expensive podcast-marker joins with one indexed `meta_key IN (...)` query
- Kept mixed post archives scoped without timing out dashboard, operation, shortcode, or schema checks

### 3.0.0

- Major namespaced and modular rewrite
- Hexa WP Core dashboard, registries, updater, and integration tests
- Existing-post content model preserved by default
- Corrected post-aware host and PowerPress processing
- Safe internal feed without global query mutation
- Consolidated shortcode runtime with legacy wrappers
- Removed duplicate profile/schema ownership and superseded flat dashboard code

### 2.1

- Legacy procedural plugin baseline

## License

Proprietary. Copyright Michael Peres / Hexa Web Systems.
