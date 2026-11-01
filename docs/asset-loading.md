# Asset loading

## Front end

- `assets/frontend-player.css` and `assets/frontend-player.js`: PersistentPlayer::enqueue_assets currently runs on eligible public HTML whenever the player is enabled. Player markup is printed in wp_footer. No admin, feed, or embed loading.
- `assets/home-interactions.css` and `assets/home-interactions.js`: HomeInteractions currently loads for every eligible public HTML document, to preserve the existing homepage/AJAX lifecycle. The script is protected from script reordering by its own handle.
- Profile and directory CSS: ShortcodeCallbacks::assets prints once alongside the first shortcode output, rather than globally in wp_head.
- ExternalLinks prints footer indicator CSS only when its snippet option is enabled. This is scheduled for removal in the next asset-focused release.

## Admin

- `assets/admin.css` and `assets/admin.js`: Dashboard::enqueue_assets checks the plugin settings page and matching screen hook. Other dashboard tabs share their navigation and form client.
- Core renderer assets belong to the tab rendering each Core component.

## Styling and markup

[Front-end HTML and selector map](frontend.html). Runtime output remains in `src/Frontend`; samples never replace runtime values or site styling.
