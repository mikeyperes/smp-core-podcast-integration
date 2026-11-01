# Asset loading

## Front end

- `assets/frontend-player.css` and `assets/frontend-player.js`: only with the enabled player on the homepage, singular podcast episodes, or requests that actually render a usable Listen/Watch shortcode. MPP's Listen wrapper delegates to that same shortcode and therefore marks usage too. Profile/category pages retain these assets only when their episode widgets render media controls.
- Media shortcode callbacks mark request-local FeatureUsage and enqueue the runtime during rendering, covering WordPress content, Elementor templates and loops without guessing which template is selected. Footer priority 5 prints a late stylesheet once when it missed wp_head. Footer scripts retain their existing defer/protection contract. Player markup prints only for the same usage boundary.
- `assets/home-interactions.css` and `assets/home-interactions.js`: homepage only, checked by is_front_page(). Exact legacy malformed Custom Code retirement remains global so the retired snippet cannot reappear on other pages.
- Profile and directory CSS prints once with the first shortcode output.
- External-link indicator footer CSS and its optional snippet/module have been removed. The old option is left untouched and unused.

## Release 3.3.5 asset inventory

Before (3.3.4): home, episode, profile and category each load home-interactions.css/js and frontend-player.css/js. Admin Playback loads admin.css/js only.

After: home retains all four; the episode, profile and category lose home-interactions.css/js and retain player.css/js because their actual media controls use it. A page without media shortcodes or episode classification loads neither front-end runtime. Admin Playback retains admin.css/js only.

## Admin

- `assets/admin.css` and `assets/admin.js`: Dashboard::enqueue_assets checks the plugin settings page and matching screen hook. Other dashboard tabs share their navigation and form client.
- Core renderer assets belong to the tab rendering each Core component.

## Styling and markup

[Front-end HTML and selector map](frontend.html). Runtime output remains in `src/Frontend`; samples never replace runtime values or site styling.

## Release 3.3.7: About page cache correction

`/about/` has no media shortcode output, is not the homepage and is not podcast content. An uncached request correctly loads neither player nor homepage assets and emits no player markup. The normal anonymous address was a LiteSpeed cache hit still serving HTML with `ver=3.3.1` references. Purge affected page caches after installing this release, then check the normal anonymous address as well as an uncached URL. A cache-bypass check alone does not prove that existing cached pages were refreshed. No extra path exclusion or playback-code change is needed.

## Release 3.4.0 AJAX player inventory
Before: home loads player and homepage CSS/JS; an article with media controls loads player CSS/JS; About loads neither; Persistent Player admin loads admin CSS/JS.
After, both continuity switches on: home retains all four; articles and About load player CSS/JS and permanent markup. Every public destination needs the same permanent container. Admin remains admin CSS/JS only.
After, either continuity switch off: the existing feature-usage boundary remains; About loads neither, home and media output retain the player.
The player script contains no fetch, swap, head or asset synchronization. Base Tools alone loads its Turbo/navigation assets. The player runtime guards repeated execution and refreshes delegated controls after each completed navigation.

3.4.1 retains the same asset handles and boundaries. A scoped child-list observer refreshes playback state only when new Listen/Watch elements appear, including late search results.
