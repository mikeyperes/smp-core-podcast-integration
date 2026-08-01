# Scale My Podcast - Core Functionality

Hexa WordPress integration for podcast content, ACF field structures, profile relationships, PowerPress metadata, feeds, shortcodes, a persistent audio/video player, playback-preserving navigation, and release diagnostics.

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
- `src/Frontend`: shortcodes, audio-source resolution, the persistent player, playback navigation, displays, and feeds
- `src/Integrations`: PowerPress synchronization
- `src/Diagnostics`: shared Hexa integration tests
- `lib/hexa-wordpress-plugin-core`: bundled shared UI, updater, registry, AJAX, and test runtime

The plugin defaults to existing WordPress posts. It does not migrate podcast archives to a new post type. A dedicated `episode` content model can be selected intentionally from the dashboard.

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

The **Persistent Player** tab saves through an authenticated, nonce-protected AJAX action. The player is opt-in by default and exposes independent switches for playback-preserving navigation, inline video, the Audio / Video switch, timestamp transfer, Media Session, remembered preferences, artwork, skip, rate, volume, download, and close controls. Playback-preserving navigation is eligible only while the selected audio or video medium is actively playing; pause, close, end, and error immediately return links and history to ordinary browser behavior. The tab also controls the bounded content selector, excluded paths, timeout, transition, and skip intervals.

## Player and Elementor Contract

Use `[smp_listen_button]` or `[smp_watch_button]` in an Elementor Shortcode widget, WordPress content, or a PHP template. Optional attributes are `post_id`, `label`, and `class`; Watch also accepts `element="button"` for legacy button-only placement while its default output is a real YouTube anchor. The server-rendered controls carry both available media sources so visitors can switch formats without leaving the current episode. A native Elementor Watch button may retain its real dynamic YouTube URL and add the `smp-watch-button` class; the runtime intercepts only an unmodified primary click while inline video is enabled.

For rebuilt Elementor templates, put `data-smp-ajax-root` on the one content island that should change between pages. Compatible explicit marker values may navigate to each other. Without explicit markers, selector changes are limited to the hardcoded Elementor page, post, single, and archive surface matrix; custom and bounded `main.site-main` or `main#content` selectors must match exactly. Keep the fixed player, persistent header, and persistent footer outside that island. Header, footer, navigation, whole-document, broad `main`, ambiguous selectors, and one-sided or mismatched explicit markers are rejected.

Plugin-owned markup that must live outside the replaceable island uses the inert companion contract. The only currently supported companion is one outside-root `<template data-smp-ajax-companion="smpi-breadcrumbs">`. Its breadcrumb markup is restricted to an explicit tag/attribute allowlist and same-origin HTTP(S) links. The runtime replaces the prior template and rendered breadcrumb before `smp:content-ready`; duplicate, unknown, executable, or unsafe companion content rejects AJAX navigation and falls back to the full server-rendered page.

No history listener, scroll-state mutation, or manual scroll restoration is installed on page load. A navigation session begins only after playback is eligible and the first fetched page passes root, script, style, and Elementor preflight. It fetches the exact same-origin public URL and accepts only an HTTP 200 HTML document. Any timeout, HTTP error, non-HTML response, mismatched root, unsupported executable inline script or localized configuration, missing script asset, unsupported style, unavailable Elementor lifecycle, external URL, account/checkout path, feed, media/download URL, modified click, target link, or excluded path uses ordinary browser navigation.

After safe preflight, the runtime reconciles validated WordPress/Elementor inline styles, loads trusted stylesheets and a narrow handle-and-path allowlist of Elementor/JetEngine dependencies, accepts Elementor's exact reCAPTCHA handle only from the HTTPS Google or reCAPTCHA endpoint with `render=explicit`, executes only byte-exact captured JetEngine initializers after execution-time revalidation and abort checks, and, when no equivalent logger is already active, safely reconstructs Wordfence's byte-exact human logger only for its validated same-origin endpoint. It then replaces only the matched content island, synchronizes the document title/language, common canonical/alternate links, common SEO and social metadata, head JSON-LD, body classes, history, and scroll position, and invokes the supported Elementor ready trigger. It never evaluates unknown fetched inline JavaScript, copies inline event handlers, or loads an unknown script. Direct requests remain complete server-rendered WordPress pages with their original status, metadata, schema, and crawlable links.

## Testing

Run the repository checks:

```bash
php tests/run.php
node tests/browser-player-runtime.mjs
```

On an authenticated WordPress installation, use **Tools > Hexa Integration Tests** and filter to `smp-core-podcast-integration`.

## Release History

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
