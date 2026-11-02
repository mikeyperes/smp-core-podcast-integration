# Scale My Podcast - Core Functionality

Hexa WordPress integration for podcast content, ACF field structures, profile relationships, PowerPress metadata, feeds, shortcodes, a persistent audio/video player, Base Tools playback continuity, and release diagnostics.


> Feature base for HWS Skills. Read before building on this plugin; use or
> extend these features instead of rebuilding them.

**Purpose:** Podcast publishing, relationships, playback and delivery. **Admin:** WP Admin → Settings → Scale My Podcast. **Depends on:** PowerPress for feed synchronization; SMP Verified Profiles for profile-backed relationships.

## Design examples and styling

- [Front-end HTML and selector map](docs/frontend.html)
- [Asset loading and screen boundaries](docs/asset-loading.md)
- [Bundled Core library](HEXA_PLUGIN_CORE_LIBRARY.md)

## Features

- Episode presence checks via `[smp_episode_presence field="audio" post_id="0"]`, with `[mpp_episode_presence]` retained as a compatibility alias. Uses episode-owned parsed content, valid profile relationships, usable media and category filtering. No automatic visitor output. [Presence fields](docs/episode-elementor.md#episode-presence).

- Owns episode-to-guest relationships and Elementor host, guest and guest-episode queries; resolves profile context through the full Verified Profiles type list and guest type through Verified Profiles and episode type through podcast settings, including saved Elementor widget sources before query validation.

- Episode data shortcodes and related queries. [Usage and HTML](docs/episode-elementor.md).

### Listen button
- **Does:** Accessible audio trigger with matching video identity.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[smp_listen_button post_id="0" label="Listen" class=""]`; post_id 0 uses the current post.
- **Style:** `smp-listen-button`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::listen_button`.

### Watch button
- **Does:** Crawlable YouTube link with optional inline playback.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[smp_watch_button post_id="0" label="Watch" class="" element="a"]`; post_id 0 uses the current post.
- **Style:** `smp-watch-button`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::watch_button`.

### Podcast platform URL
- **Does:** Owner platform URL with podcast-options fallback.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[podcast_url social=""]`; post_id 0 uses the current post.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::podcast_url`.

### Episode field value
- **Does:** Reads a field or grouped field for an episode.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[episode_fields name="" post_id="0"]`; post_id 0 uses the current post.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::episode_fields`.

### Article guest links
- **Does:** Linked profile names from article relationships.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[article_guests post_id="0"]`; post_id 0 uses the current post.
- **Style:** `shortcode_article_guests`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::article_guests`.

### Episode host links
- **Does:** Linked host names for an episode.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[podcast_hosts post_id="0"]`; post_id 0 uses the current post.
- **Style:** `shortcode_podcast_hosts`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::podcast_hosts`.

### Episode host cards
- **Does:** Host template 20001 with profile-card fallback.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[display_single_episode_hosts post_id="0" must_have_thumbnail="false"]`; post_id 0 uses the current post.
- **Style:** `display_single_episode_hosts`.
- **Extend:** `smp_podcast_host_template_id` selects the host template.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::episode_hosts`.

### Guest directory
- **Does:** WordPress user cards linked to author pages.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[guest_grid]`; post_id 0 uses the current post.
- **Style:** `user-grid, user-card, view-member-button`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::guest_grid`.

### Episode guest profiles
- **Does:** Guest user profiles with legacy repeater fallback.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[display_guest_profile_info post_id="0"]`; post_id 0 uses the current post.
- **Style:** `guest-profile-wrapper, guest-profile`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::guest_profiles`.

### Episode host profiles
- **Does:** Host profile-post cards for an episode.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[display_host_profile_info post_id="0"]`; post_id 0 uses the current post.
- **Style:** `host-profile-wrapper`.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::host_profiles`.

### Default host value
- **Does:** Default host title, biography or platform URL.
- **Switch:** Always registered; empty when required episode/profile data is unavailable.
- **Use:** `[podcast_host id="title"] (also biography and url_<key>)`; post_id 0 uses the current post.
- **Code:** `src/Frontend/ShortcodeCallbacks.php::podcast_host`.

### Podcast content and classification
- **Does:** Podcast metadata on posts or an intentionally selected episode content type; explicit episode/article classification.
- **Switch:** Content settings; editor Content Kind control.
- **Use:** Automatic; `_mpp_content_kind` values `episode` and `article`.
- **Code:** `src/Content/ContentKind.php; src/Bootstrap/Registries.php; src/Settings/PodcastSettings.php`.

### Episode fields
- **Does:** Platform URLs, hosts, audio, numbering, duration and references.
- **Switch:** Custom Fields; `regsiter_acf_post_podcast`.
- **Use:** Automatic; stable group `group_6844c5d5cf57f`.
- **Code:** `src/Acf/EpisodeFieldGroup.php`.

### Podcast option fields
- **Does:** Podcast labels, importer settings, default host and platform URLs.
- **Switch:** Custom Fields; `register_theme_options`.
- **Use:** Podcast Settings; stable group `group_6848b7b0247cc`.
- **Code:** `src/Acf/PodcastOptionsFieldGroup.php`.

### Persistent player and navigation
- **Acceptance:** [Browser evidence and known automated-browser YouTube condition](docs/persistent-player-acceptance.md).
- **Does:** Singleton audio/video player; optional continuity uses HWS Base Tools AJAX Navigation 13.47.0 or newer.
- **Switch:** Persistent Player; `smp_podcast_playback_settings`, disabled by default.
- **Use:** Listen/Watch triggers; see Player and Elementor Contract below.
- **Code:** `src/Frontend/PersistentPlayer.php; src/Settings/PlaybackSettings.php`.

### Homepage interactions
- **Does:** Homepage-only filters, presentation rails and scroll cue.
- **Switch:** Automatic on the existing homepage presentation.
- **Use:** Automatic; Elementor homepage markup.
- **Code:** `src/Frontend/HomeInteractions.php`.

### Default host assignment
- **Does:** Assigns the selected default host without overwriting existing hosts.
- **Switch:** Snippets; `enable_post_functionality`, disabled by default.
- **Use:** Automatic on eligible content saves.
- **Code:** `src/Content/DefaultHost.php`.

### PowerPress synchronization
- **Does:** Synchronizes episode audio, duration, summary, numbering and enclosure.
- **Switch:** Snippets; `enable_powerpress_post_functionality`, disabled by default.
- **Use:** Automatic on eligible content saves.
- **Code:** `src/Integrations/PowerPressSync.php`.

### Internal RSS
- **Does:** Scoped internal podcast feed alongside the public PowerPress feed.
- **Switch:** Snippets; `enable_rss`, disabled by default.
- **Use:** `/feed/internal-rss/`.
- **Code:** `src/Frontend/InternalFeed.php`.

### Update Center
- **Does:** Displays plugin and bundled Core update reports on one tab.
- **Use:** Settings → Scale My Podcast → Update Center (`tab=update-center`); old `tab=updates` redirects.
- **Code:** `src/Admin/Dashboard.php`; `src/Bootstrap/runtime.php`.

### Core shortcode catalog
- **Does:** Lists all 11 distinct existing tags with parameterized examples, test descriptions and live output. The pre-refactor source contains 11 tags, not 22.
- **Use:** Settings → Scale My Podcast → Shortcodes.
- **Code:** `src/Frontend/Shortcodes.php` uses Core `ShortcodeRegistry` and `ShortcodeDefinition`; `src/Admin/Dashboard.php` uses Core `ShortcodeDisplayRenderer`.

### Elementor content-kind display rules
- **Does:** Adds Episodes and Articles under Singular using the plugin-owned `_mpp_content_kind` classification and existing episode compatibility rules.
- **Use:** Theme Builder display conditions `mpp_episode` and `mpp_article`; existing template assignments require no changes.
- **Extend:** Registers at priority 30 after the MPP helper, replacing matching instances without duplicate child entries.
- **Code:** `src/Integrations/Elementor/ContentKindRules.php`; `ContentKindCondition.php`, `EpisodeCondition.php`, `ArticleCondition.php`.

## Internals

- Standalone regression harness models WordPress metadata and initialized ACF for the bundled Core version; `php tests/run.php` and `php tests/content-kind-migration.php`.
- Core registries, updater panels, authenticated operations and integration diagnostics live under `src/Bootstrap`, `src/Admin` and `src/Diagnostics`.

## Requirements

- WordPress 6.2 or newer
- PHP 8.0 or newer
- PowerPress for enclosure synchronization and the public podcast feed
- SMP Verified Profiles for profile-backed hosts and guests

ACF Pro is not required: the field layer runs on Hexa WP Core custom fields, which use ACF when it is active and store the same data natively when it is not. Optional integrations never prevent the settings dashboard or unrelated runtime features from loading.

## Architecture

- `smp-core-podcast-integration.php`: canonical WordPress bootstrap and updater configuration
- `initialization.php`: compatibility entrypoint for installations activated under the old basename
- `src/Bootstrap`: Core module registration and registries
- `src/Admin`: Hexa WP Core dashboard and authenticated operations
- `src/Acf`: canonical podcast field groups with stable ACF keys
- `src/Content`: content and default-host behavior
- `src/Frontend`: shortcodes, audio-source resolution, the persistent player, playback continuity, displays, and feeds
- `src/Integrations`: PowerPress synchronization
- `src/Diagnostics`: shared Hexa integration tests
- `lib/hexa-wordpress-plugin-core`: bundled shared UI, updater, registry, AJAX, and test runtime

The plugin defaults to existing WordPress posts. It does not migrate podcast archives to a new post type. A dedicated `episode` content model can be selected intentionally from the dashboard.

### Mixed-post content-kind contract

ScaleMyPodcast owns the protected `_mpp_content_kind` post-meta contract. Its
only valid values are `episode` and `article`. An explicit `episode` is
authoritative even before incidental podcast fields exist; an explicit
`article` vetoes podcast queries, ACF episode fields, default-host processing,
PowerPress synchronization, and podcast player/shortcode resolution even if
legacy podcast metadata remains. Only posts with no content-kind metadata may
temporarily use the historical podcast-marker fallback. Invalid explicit
values fail closed.

Legacy podcast-marker fallback is a pre-backfill compatibility bridge, not a
permanent classifier. The guarded exact-ID migration disables it in the same
transaction that assigns the reviewed episode corpus, preventing a future
unclassified article with ordinary linked-media `enclosure` metadata from
entering podcast queries or integrations.

Classification is authored through the normal nonce-protected editor meta box.
The protected key is deliberately not REST-writable because first-time episode
classification has lifecycle side effects, including default-host assignment;
allowing a data-only REST write would bypass those guarantees.

The tools-only migration under `tools/` is not loaded by WordPress. It refuses
to infer corpus identity from the 158 published, 6 draft, and 1 private counts:
planning requires a read-only, externally reviewed, checksummed list of the
exact 165 IDs. It must not be run without its documented production gates.

## Compatibility Contracts

- Existing post, option, ACF, and PowerPress metadata remains in place.
- ACF group keys `group_6844c5d5cf57f` and `group_6848b7b0247cc` remain canonical.
- All nine legacy shortcode tags remain registered, plus the canonical `[smp_listen_button]` and `[smp_watch_button]` media triggers.
- The old active basename `smp-core-podcast-integration/initialization.php` migrates to the canonical plugin file automatically.
- The public PowerPress feed remains `/feed/podcast/`.
- Optional internal integration feed: `/feed/internal-rss/`.
- The player uses one audio element and one on-demand privacy-enhanced YouTube iframe. Audio URLs prefer PowerPress, then ACF `audio`, `audio_url`, and the WordPress `enclosure` value; video uses the validated episode `urls_youtube` value.
- No unauthenticated admin AJAX action, public REST route, app shell, hash router, or alternate indexable response is introduced.

## Admin

Open **Settings > Scale My Podcast**. The dashboard uses Hexa WP Core for sidebar tabs, collapsible structures, content-type and ACF controls, snippets, shortcodes, update panels, dynamic buttons, and integration tests.

The **Persistent Player** tab includes an **AJAX player** section. **Keep playing across page clicks** is off by default; an explicitly stored legacy navigation choice migrates to it. The section links to Base Tools AJAX Navigation and warns when its dependency is missing, too old or disabled. With both switches on it shows a green ready note.

## Player and Elementor Contract

Use `[smp_listen_button]` or `[smp_watch_button]` in an Elementor Shortcode widget, WordPress content, or a PHP template. Optional attributes are `post_id`, `label`, and `class`; Watch also accepts `element="button"` for legacy button-only placement while its default output is a real YouTube anchor. The server-rendered controls carry both available media sources so visitors can switch formats without leaving the current episode. A native Elementor Watch button may retain its real dynamic YouTube URL and add the `smp-watch-button` class; the runtime intercepts only an unmodified primary click while inline video is enabled.

When AJAX player and HWS Base Tools AJAX Navigation (13.47.0+) are both on, the player renders on every eligible public page as a permanent aside with id smp-podcast-player. Its audio and YouTube frame remain inside that stable container. Base Tools owns all fetching, assets, document replacement, metadata and history. On hws:page-changed, delegated Listen/Watch controls reflect the current track and body spacing is restored. Later search-result insertions also inherit the current playback state. On hws:page-will-change, a nonpermanent player stops and releases its page handlers. Full reloads stop playback.

## Testing

Run the repository checks:

```bash
php tests/run.php
php tests/content-kind-migration.php
node tests/browser-player-runtime.mjs
```

On an authenticated WordPress installation, use **Tools > Hexa Integration Tests** and filter to `smp-core-podcast-integration`.

## Release History

### 3.4.2
- Record accepted desktop/phone playback evidence and the automated-browser YouTube sign-in condition. No playback behavior change.

### 3.4.1
- Refresh media button state when asynchronously loaded search results add new Listen/Watch controls.

### 3.4.0
- Remove the player-owned navigation, swap, asset and head synchronization engine.
- Add the opt-in AJAX player settings section, dependency warning and ready state.
- Migrate the stored legacy navigation choice; preserve audio/video with Base Tools permanent markup and refresh new page controls.


### 3.3.7

- Keeps source-archive housekeeping in the changelog instead of the Features list.
- Expands docs/frontend.html into separate real-markup and selector references for media controls, profile cards, the guest directory, and the player bar.
- Confirms unused About page runtime is already excluded; purges stale LiteSpeed HTML from 3.3.1 during rollout. No playback or template behavior changes.
- Bundles Hexa WP Core 3.47.0.

### 3.3.6

- Owns Episodes and Articles Elementor display rules under the existing mpp_episode/mpp_article keys and precedence.
- Supersedes matching helper rule instances without editing the helper or changing stored template conditions.
- Bundles Hexa WP Core 3.47.0.

### 3.3.5

- Scopes player loading to homepage, episodes and actual media shortcode output, including Elementor loops and MPP wrappers. Prints late shortcode stylesheet once.
- Loads homepage interactions only on the homepage and removes the footer external-link indicator module/CSS.
- Documents before/after asset inventories. Bundles Hexa WP Core 3.47.0.

### 3.3.4

- Registers all 11 existing shortcode tags through Core ShortcodeRegistry, preserving callback bindings and output.
- Drives the Shortcodes tab from the same definitions, with examples, test descriptions and real output.
- Bundles Hexa WP Core 3.47.0.

### 3.3.3

- Adds front-end HTML, asset documentation and the bundled Core library reference; completes the feature documentation.
- Renames Updates to Update Center with a legacy-address redirect, moves lifecycle/updater functions out of the main file, and stops tracking PHP runtime logs.
- Bundles Hexa WP Core 3.47.0.

### 3.3.2

- Preserves all three stale source copies on verified archive branches and establishes the live installed folder as the sole Git source on main.
- Bundles Hexa WP Core 3.47.0; runtime unchanged.

### 3.3.1

- Updates standalone WordPress stand-ins for Core 3.47.0, including initialized ACF and generic metadata APIs.
- Reads the bundled Core version dynamically; preserves the existing media markup expectations and fixtures.
- Bundles Hexa WP Core 3.47.0 and documents the feature base. Runtime behavior is unchanged.

### 3.3.0

- Removes the ACF Pro requirement: podcast settings, episode fields, row loops, location matching and ACF hooks run on Hexa WP Core 3.4.8 `Fields` (ACF when active, native otherwise). ACF is no longer a required dependency, admin notice or snippet test.
- Bundles Hexa WP Core 3.4.8 (from 1.1.9); tests use Core's shared Fields test support.

### 3.2.3

- Replaced the malformed legacy homepage scroll-cue snippet with a throttled,
  accessible, AJAX-lifecycle-safe runtime owned by the plugin.
- Contained the mobile homepage's horizontal presentation rails without
  changing desktop sizing or the persistent player surface.

### 3.2.2

- Added a protected, explicit `episode|article` content-kind contract for
  mixed WordPress post installations.
- Added fail-closed episode queries and integration boundaries so editorial
  articles cannot enter podcast playback, ACF, host, or PowerPress behavior.
- Added the guarded, exact-ID WP-CLI migration and rollback artifacts for the
  reviewed Michael Peres Podcast episode corpus.

### 3.2.1

- Kept every server-rendered Watch control as a real crawlable YouTube anchor while preserving inline video interception for ordinary primary clicks
- Created episode artwork only after a real track is selected so the idle fixed player cannot emit a broken empty image

### 3.2.0

- Rebuilt the fixed player as a branded persistent audio/video surface with dependency-free SVG controls and landscape episode artwork
- Added validated YouTube episode resolution, native Watch interception, and an Elementor-ready `[smp_watch_button]` trigger
- Added accessible Audio / Video switching with optional timestamp transfer while ensuring only one medium plays at a time
- Kept AJAX navigation fail-closed and eligible only while the selected audio or video medium is actually playing
- Added video, mode-switch, and timestamp-sync controls to the Persistent Player settings tab

### 3.1.7

- Reinitialized every imported Elementor element through its supported ready trigger so AJAX-loaded forms attach their submit lifecycle while active audio remains uninterrupted
- Strengthened the reCAPTCHA browser scenario to require a functional form submit handler, not only loaded markup and scripts

### 3.1.6

- Preserved active playback on an immediate first navigation to a Wordfence-protected destination by accepting only its byte-exact captured human-logger wrapper and exact same-origin 32-hex endpoint, then reconstructing the one-shot logger without executing fetched inline code
- Added browser coverage proving the safe logger runs once, altered Wordfence-like code still forces hard navigation, and audio remains continuous

### 3.1.5

- Reconciled the strict JetEngine initializer and Elementor reCAPTCHA policies into one runtime so both Loop Grid and form destinations preserve active playback
- Added combined browser coverage for trusted dependencies, tampered inline scripts, aborted execution, and a spoofed external reCAPTCHA host

### 3.1.4

- Preserved active playback across JetEngine Loop Grid destinations by allowing only byte-exact captured JetEngine data-store and popup initializers, revalidating and abort-checking them immediately before ordered execution, and retaining hard-navigation fallback for altered or unknown inline scripts
- Preserved active audio when navigating to Elementor form pages by accepting only the exact Elementor reCAPTCHA handle from its validated HTTPS hosts, API path, and explicit render mode

### 3.1.3

- Added strict JSON-only synchronization for JetEngine's exact page-context localization handle so Loop Grid surfaces can replace one another without re-executing fetched JavaScript

### 3.1.2

- Preserved strict JSON-only Elementor configuration synchronization while accepting WordPress's exact trailing `sourceURL` annotation on the already allowlisted Elementor config blocks

### 3.1.1

- Kept active audio playing across Cloudflare-protected pages by recognizing the exact same-origin, self-removing email-decoder asset during AJAX script preflight without executing fetched inline or untrusted scripts

### 3.1.0

- Added an accessible singleton bottom audio player with play/pause, seek, skip, rate, volume, artwork, download, close, Media Session, and remembered-preference controls
- Added PowerPress-first audio resolution with ACF `audio`, `audio_url`, and enclosure fallbacks
- Added the canonical `[smp_listen_button]` shortcode and compatibility with redesign `.ep-listen[data-mp3]` and `#ap-toggle` triggers
- Added playback-only same-origin HTML navigation with lazy history ownership, strict root/script/style/Elementor preflight, hard-navigation fallbacks, common head/schema synchronization, and bounded Elementor reinitialization
- Added a fully AJAX-saved Persistent Player settings tab with granular feature, timing, selector, and exclusion controls
- Preserved direct server-rendered HTTP documents, canonicals, JSON-LD, crawlable links, feeds, and the absence of public mutation endpoints
- Expanded local and live integration contracts for settings, source resolution, accessibility, SEO, and navigation safety

### 3.0.3

- Restored legacy guest-profile rendering when repeater rows exist in post meta but their historical ACF field definition is unavailable

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
