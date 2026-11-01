# Persistent player acceptance and known conditions

Recorded: 2026-11-01 03:07:38 EST

## Accepted configuration
- Podcast Integration 3.4.2 with HWS Base Tools 13.48.2 on podcast.michaelperes.com.
- HWS Base Tools AJAX Navigation and Keep playing across page clicks are both ON.
- Base Tools alone owns navigation. The complete permanent player remains connected during Turbo morphing.

## Browser evidence
The final real Chrome run used 1440x900 and 390x844 viewports. At each size, 13 audio checkpoints passed: Craig playback, Guests, Episodes, About, a guest profile, category, category GET search, Back, Forward, another episode, and switching tracks from a card (including intermediate navigation steps). Audio time advanced, no navigation pause event occurred, the original document and player remained, and Listen buttons matched the current track. Media Session metadata remained available and changed with the selected episode. No console errors were captured.

The video-mode matrix kept the identical iframe element and contentWindow at all 12 checkpoints per viewport, including three successive page clicks, profile/category, GET search, history and another episode. The user explicitly accepted iframe browsing-context identity as the video continuity proof for this run.

Base Tools regressions passed: 68 PHP AJAX unit checks, six WordPress parser checks, and the browser regression covering connected iframe identity, advancing real audio, GET forms, native POST, normal and empty-state history, and Elementor teardown without duplicate history pushes. Podcast tests passed 250 PHP checks and both browser fixtures, including late search-result button state.

## Known condition: YouTube blocks the automated test browser
YouTube displayed: "Sign in to confirm you're not a bot" in the server's automated browser. Playback remained unavailable in that test environment, so actual YouTube video time advancing was not measured. The frame document remained alive across the navigation matrix.

This is recorded as an accepted automated-browser condition, not a player continuity failure or a reason to require visitors to sign in. No YouTube sign-in was performed. The user accepted the iframe-context checks as the agreed video proof and authorized leaving both settings ON.

The Media Session API metadata was checked in the browser; a physical phone lock-screen test was not performed.
