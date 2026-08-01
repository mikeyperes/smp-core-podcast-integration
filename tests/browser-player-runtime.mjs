import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const runtime = readFileSync(join(root, 'assets/frontend-player.js'));
const homeInteractionsRuntime = readFileSync(join(root, 'assets/home-interactions.js'));
const homeInteractionsStyles = readFileSync(join(root, 'assets/home-interactions.css'));
const scenarios = [
    'no-playback',
    'disabled',
    'video-switch',
    'active',
    'continuity',
    'divergent-assets',
    'slow-pause',
    'slow-close',
    'slow-ended',
    'slow-error',
    'inactive-history',
    'foreign-history',
    'elementor-ready',
    'elementor-recaptcha',
    'wordfence-fresh',
    'tampered-wordfence',
    'trusted-jet-inline',
    'tampered-jet-inline',
    'tampered-jet-whitespace',
    'aborted-jet-inline',
    'elementor-unready',
    'unsupported-inline',
    'missing-script',
    'spoofed-recaptcha',
    'self-removing-cloudflare',
    'unmatched-root',
    'malicious-style-onload',
    'malicious-script-onerror',
    'malicious-content-onclick',
    'unsafe-inline-style',
    'unknown-inline-style',
    'surface-matrix',
    'explicit-marker-mismatch',
    'custom-root-mismatch',
    'unsafe-companion',
];

const server = createServer((request, response) => {
    const url = new URL(request.url || '/', 'http://127.0.0.1');
    if (url.pathname === '/assets/frontend-player.js') {
        response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(runtime);
        return;
    }
    if (url.pathname === '/assets/home-interactions.js') {
        response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(homeInteractionsRuntime);
        return;
    }
    if (url.pathname === '/assets/home-interactions.css') {
        response.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(homeInteractionsStyles);
        return;
    }
    if (url.pathname === '/media.mp3' || url.pathname === '/legacy.mp3' || url.pathname === '/target-legacy.mp3') {
        const audio = silentWav();
        response.writeHead(200, {
            'Content-Type': 'audio/wav',
            'Content-Length': audio.length,
            'Cache-Control': 'no-store',
            'Accept-Ranges': 'bytes',
        });
        response.end(audio);
        return;
    }
    if (url.pathname === '/target.css') {
        response.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end('[data-smp-ajax-root]{--smp-target-style:1}');
        return;
    }
    if (url.pathname === '/episode.css' || url.pathname === '/home.css') {
        response.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(`[data-smp-ajax-root]{--smp-surface:${url.pathname === '/episode.css' ? 'episode' : 'home'}}`);
        return;
    }
    const dynamicScripts = {
        '/wp-content/plugins/elementor-pro/assets/lib/sticky/jquery.sticky.min.js': 'e-sticky-js',
        '/wp-content/plugins/jet-engine/assets/lib/jet-plugins/jet-plugins.js': 'jet-plugins-js',
        '/wp-content/plugins/jet-engine/assets/js/frontend/modules/data-stores.js': 'jet-engine-data-stores-js',
        '/wp-content/plugins/jet-engine/assets/js/frontend/frontend.js': 'jet-engine-frontend-js',
    };
    if (dynamicScripts[url.pathname]) {
        response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(`window.__dynamicLoads=(window.__dynamicLoads||[]).concat(${JSON.stringify(dynamicScripts[url.pathname])});`);
        return;
    }
    if (url.pathname === '/next' || url.pathname === '/back') {
        const label = url.pathname === '/next' ? 'Next page' : 'Back page';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(targetDocument(label, url.pathname));
        return;
    }
    if (url.pathname === '/elementor-ready') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(elementorTargetDocument());
        return;
    }
    if (url.pathname === '/elementor-recaptcha') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(recaptchaTargetDocument('https://www.google.com/recaptcha/api.js?render=explicit&ver=4.2.1'));
        return;
    }
    if (url.pathname === '/wordfence-fresh' || url.pathname === '/tampered-wordfence') {
        const tampered = url.pathname === '/tampered-wordfence';
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(tampered && directRequest
            ? fallbackDocument('unsupported-inline-script')
            : wordfenceTargetDocument(tampered));
        return;
    }
    if (['/trusted-jet-inline', '/tampered-jet-inline', '/tampered-jet-whitespace', '/aborted-jet-inline'].includes(url.pathname)) {
        const mode = url.pathname.slice(1);
        const tampered = mode === 'tampered-jet-inline' || mode === 'tampered-jet-whitespace';
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(tampered && directRequest
            ? fallbackDocument('unsupported-inline-script')
            : jetInlineTargetDocument(mode));
        return;
    }
    if (url.pathname === '/continuity') {
        setTimeout(() => {
            if (response.writableEnded) return;
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            response.end(continuityTargetDocument());
        }, 180);
        return;
    }
    if (url.pathname === '/divergent-episode') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(divergentDocument('episode'));
        return;
    }
    if (url.pathname === '/divergent-home') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(divergentDocument('home'));
        return;
    }
    if (url.pathname.startsWith('/slow-')) {
        setTimeout(() => {
            if (response.writableEnded) return;
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            response.end(targetDocument('Unexpected slow target', url.pathname));
        }, 420);
        return;
    }
    if (url.pathname === '/inactive-history-target' || url.pathname === '/foreign-history-target') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        if (directRequest) {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            response.end(historyFallbackDocument(url.pathname === '/inactive-history-target' ? 'playback-inactive' : 'foreign-history-state'));
        } else {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            response.end(targetDocument(url.pathname === '/inactive-history-target' ? 'Inactive history target' : 'Foreign history target', url.pathname));
        }
        return;
    }
    if (url.pathname === '/elementor-unready') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.end(directRequest ? fallbackDocument('elementor-not-ready', '', true, 'elementor') : elementorUnreadyDocument());
        return;
    }
    if (url.pathname === '/unsupported-inline') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(fallbackDocument('unsupported-inline-script', '<script>window.__fetchedInlineExecuted = true;</script>'));
        return;
    }
    if (url.pathname === '/missing-script') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(fallbackDocument('missing-script-asset', '<script src="/wp-content/plugins/not-loaded.js"></script>'));
        return;
    }
    if (url.pathname === '/spoofed-recaptcha') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(directRequest
            ? fallbackDocument('missing-script-asset')
            : recaptchaTargetDocument('https://recaptcha.attacker.example/recaptcha/api.js?render=explicit'));
        return;
    }
    if (url.pathname === '/self-removing-cloudflare') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(cloudflareTargetDocument());
        return;
    }
    if (url.pathname === '/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js') {
        response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end('window.__cloudflareEmailDecoderRuns=(window.__cloudflareEmailDecoderRuns||0)+1;if(document.currentScript)document.currentScript.remove();');
        return;
    }
    if (url.pathname === '/wp-content/plugins/not-loaded.js') {
        response.writeHead(404, { 'Content-Type': 'text/javascript; charset=utf-8' });
        response.end('');
        return;
    }
    if (url.pathname === '/unmatched-root') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(fallbackDocument('content-root-mismatch', '', false));
        return;
    }
    if (url.pathname === '/explicit-marker-mismatch') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(directRequest
            ? rootMismatchFallbackDocument('content-root-mismatch', '<main data-smp-ajax-root="different"><h1>Explicit marker mismatch</h1></main>')
            : '<!doctype html><html><head><title>Explicit marker mismatch</title></head><body><main data-smp-ajax-root="different"><h1>Explicit marker mismatch</h1></main></body></html>');
        return;
    }
    if (url.pathname === '/custom-root-mismatch') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(directRequest
            ? rootMismatchFallbackDocument('content-root-mismatch', '<main class="custom-b"><h1>Custom root mismatch</h1></main>')
            : '<!doctype html><html><head><title>Custom root mismatch</title></head><body><main class="custom-b"><h1>Custom root mismatch</h1></main></body></html>');
        return;
    }
    if (url.pathname === '/unsafe-companion') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(directRequest
            ? rootMismatchFallbackDocument('unsafe-companion-fragment', '<main data-smp-ajax-root="content"><h1>Unsafe companion fallback</h1></main>')
            : '<!doctype html><html><head><title>Unsafe companion</title></head><body><main data-smp-ajax-root="content"><h1>Unsafe companion</h1></main><template data-smp-ajax-companion="smpi-breadcrumbs"><iframe src="/next"></iframe></template></body></html>');
        return;
    }
    if (url.pathname.startsWith('/surface/')) {
        const surface = surfaceSequence().find((entry) => entry.path === url.pathname);
        response.writeHead(surface ? 200 : 404, { 'Content-Type': surface ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(surface ? surfaceDocument(surface) : 'Unknown surface');
        return;
    }
    if (url.pathname === '/malicious-style-onload' || url.pathname === '/malicious-script-onerror' || url.pathname === '/malicious-content-onclick') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(directRequest ? fallbackDocument('inline-event-handler') : maliciousDocument(url.pathname));
        return;
    }
    if (url.pathname === '/unsafe-inline-style' || url.pathname === '/unknown-inline-style') {
        const directRequest = request.headers['sec-fetch-dest'] === 'document';
        const reason = url.pathname === '/unsafe-inline-style' ? 'unsafe-inline-style' : 'unknown-inline-style';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(directRequest ? fallbackDocument(reason) : rejectedStyleDocument(url.pathname));
        return;
    }
    if (url.pathname === '/scenario') {
        const mode = url.searchParams.get('mode') || '';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(scenarioDocument(mode));
        return;
    }
    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('Not found');
});

await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
});

const address = server.address();
const origin = `http://127.0.0.1:${address.port}`;
let failures = 0;
let browser = null;

try {
    browser = await optionalPuppeteerBrowser();
    for (const scenario of scenarios) {
        const result = browser
            ? await runPuppeteer(browser, `${origin}/scenario?mode=${encodeURIComponent(scenario)}`)
            : await runChrome(`${origin}/scenario?mode=${encodeURIComponent(scenario)}`);
        const passed = result.code === 0 && /data-test-status="pass"/.test(result.stdout);
        if (passed) {
            process.stdout.write(`PASS browser runtime: ${scenario}\n`);
            continue;
        }
        failures += 1;
        const detail = extractResult(result.stdout) || result.stderr.trim().split('\n').slice(-4).join(' | ') || `Chrome exit ${result.code}`;
        process.stderr.write(`FAIL browser runtime: ${scenario} - ${detail}\n`);
    }
} finally {
    if (browser) await browser.close();
    await new Promise((resolve) => server.close(resolve));
}

if (failures) process.exit(1);

function silentWav() {
    const samples = 8000;
    const buffer = Buffer.alloc(44 + samples * 2);
    buffer.write('RIFF', 0);
    buffer.writeUInt32LE(36 + samples * 2, 4);
    buffer.write('WAVEfmt ', 8);
    buffer.writeUInt32LE(16, 16);
    buffer.writeUInt16LE(1, 20);
    buffer.writeUInt16LE(1, 22);
    buffer.writeUInt32LE(8000, 24);
    buffer.writeUInt32LE(16000, 28);
    buffer.writeUInt16LE(2, 32);
    buffer.writeUInt16LE(16, 34);
    buffer.write('data', 36);
    buffer.writeUInt32LE(samples * 2, 40);
    return buffer;
}

function scenarioDocument(mode) {
    if (mode === 'surface-matrix') return surfaceMatrixInitialDocument();
    if (mode === 'custom-root-mismatch') return customRootMismatchInitialDocument();

    const enabledAjax = mode !== 'disabled';
    const endpoint = {
        active: '/next',
        continuity: '/continuity',
        'divergent-assets': '/divergent-episode',
        'slow-pause': '/slow-pause',
        'slow-close': '/slow-close',
        'slow-ended': '/slow-ended',
        'slow-error': '/slow-error',
        'inactive-history': '/inactive-history-target',
        'foreign-history': '/foreign-history-target',
        'elementor-ready': '/elementor-ready',
        'elementor-recaptcha': '/elementor-recaptcha',
        'wordfence-fresh': '/wordfence-fresh',
        'tampered-wordfence': '/tampered-wordfence',
        'trusted-jet-inline': '/trusted-jet-inline',
        'tampered-jet-inline': '/tampered-jet-inline',
        'tampered-jet-whitespace': '/tampered-jet-whitespace',
        'aborted-jet-inline': '/aborted-jet-inline',
        'elementor-unready': '/elementor-unready',
        'unsupported-inline': '/unsupported-inline',
        'missing-script': '/missing-script',
        'spoofed-recaptcha': '/spoofed-recaptcha',
        'self-removing-cloudflare': '/self-removing-cloudflare',
        'unmatched-root': '/unmatched-root',
        'malicious-style-onload': '/malicious-style-onload',
        'malicious-script-onerror': '/malicious-script-onerror',
        'malicious-content-onclick': '/malicious-content-onclick',
        'unsafe-inline-style': '/unsafe-inline-style',
        'unknown-inline-style': '/unknown-inline-style',
        'explicit-marker-mismatch': '/explicit-marker-mismatch',
        'unsafe-companion': '/unsafe-companion',
    }[mode] || '/next';
    const config = {
        enabled: true,
        ajaxNavigation: enabledAjax,
        contentSelector: '[data-smp-ajax-root]',
        rootFallbacks: [],
        excludedPaths: [],
        timeoutMs: 2000,
        transitionMs: 0,
        skipBack: 15,
        skipForward: 30,
        showCover: true,
        videoEnabled: true,
        showModeSwitch: true,
        syncMediaPosition: true,
        mediaSession: false,
        rememberPreferences: false,
        strings: {},
    };

    return `<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>Initial page</title>
<link rel="canonical" href="/scenario?mode=${escapeHtml(mode)}">
<meta name="description" content="Initial description"><meta name="robots" content="index,follow">
${initialAssetMarkup(mode)}
<script>${instrumentationScript()}</script>
${mode === 'elementor-ready' || mode === 'elementor-recaptcha' || mode === 'divergent-assets' ? elementorTestBootstrap() : ''}
${['trusted-jet-inline', 'tampered-jet-inline', 'tampered-jet-whitespace', 'aborted-jet-inline'].includes(mode) ? jetInlineTestBootstrap(mode === 'aborted-jet-inline') : ''}
<script>window.smpPodcastPlayerConfig=${JSON.stringify(config)};</script>
<script src="/assets/frontend-player.js"></script>
</head><body>
<header><a href="/">Home</a></header>
<main data-smp-ajax-root="content">
<h1>Initial content</h1>
<button id="listen" type="button" data-smp-player-trigger data-smp-audio-src="${escapeHtml(origin)}/media.mp3" data-smp-video-id="yzMcrZCYh5Y" data-smp-video-url="https://www.youtube.com/watch?v=yzMcrZCYh5Y" data-smp-title="Test episode">Listen</button>
${mode === 'video-switch' ? '<a id="watch" class="smp-watch-button" href="https://www.youtube.com/watch?v=yzMcrZCYh5Y" target="_blank">Watch</a>' : ''}
<a id="navigate" href="${escapeHtml(endpoint)}">Navigate</a>
<div><button id="ap-toggle" type="button">Legacy listen</button><audio id="ap-audio" src="${escapeHtml(origin)}/legacy.mp3"></audio></div>
</main>
${playerMarkup()}
<pre id="test-result">pending</pre>
<script>${clientTestScript(mode)}</script>
</body></html>`;
}

function surfaceMatrixInitialDocument() {
    const config = playerConfig({
        contentSelector: '[data-smp-ajax-root]',
        rootFallbacks: trustedSurfaceSelectors(),
    });

    return `<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>Podcast home</title>
<link rel="canonical" href="/scenario?mode=surface-matrix">
<link rel="stylesheet" href="/assets/home-interactions.css">
<script>${instrumentationScript()}</script>
${elementorTestBootstrap()}
<script>${companionRuntimeBootstrap()}</script>
<script type="text/javascript">${wordfenceHumanLoggerSource('A'.repeat(32))}</script>
<script>window.smpPodcastPlayerConfig=${JSON.stringify(config)};</script>
<script src="/assets/home-interactions.js"></script>
<script src="/assets/frontend-player.js"></script>
</head><body class="home-body">
<header><a href="/">Home</a></header>
${homeSurfaceMarkup('/surface/episode', true)}
${breadcrumbCompanionTemplate('Podcast home breadcrumb')}
${playerMarkup()}
<pre id="test-result">pending</pre>
<script>${clientTestScript('surface-matrix')}</script>
</body></html>`;
}

function customRootMismatchInitialDocument() {
    const config = playerConfig({ contentSelector: '.custom-a', rootFallbacks: ['.custom-a', '.custom-b'] });
    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Custom root initial</title>
<script>${instrumentationScript()}</script>
<script>window.smpPodcastPlayerConfig=${JSON.stringify(config)};</script>
<script src="/assets/frontend-player.js"></script>
</head><body><main class="custom-a"><h1>Custom root initial</h1>
<button id="listen" type="button" data-smp-player-trigger data-smp-audio-src="${escapeHtml(origin)}/media.mp3" data-smp-title="Test episode">Listen</button>
<a id="navigate" href="/custom-root-mismatch">Navigate</a></main>
${playerMarkup()}<pre id="test-result">pending</pre><script>${clientTestScript('custom-root-mismatch')}</script></body></html>`;
}

function playerConfig(overrides = {}) {
    return {
        enabled: true,
        ajaxNavigation: true,
        contentSelector: '[data-smp-ajax-root]',
        rootFallbacks: [],
        excludedPaths: [],
        timeoutMs: 2000,
        transitionMs: 0,
        skipBack: 15,
        skipForward: 30,
        showCover: true,
        videoEnabled: true,
        showModeSwitch: true,
        syncMediaPosition: true,
        mediaSession: false,
        rememberPreferences: false,
        strings: {},
        ...overrides,
    };
}

function trustedSurfaceSelectors() {
    return [
        '[data-elementor-type="wp-page"]',
        '[data-elementor-type="wp-post"]',
        '[data-elementor-type="single-post"]',
        '[data-elementor-type="single"]',
        '[data-elementor-type="archive"]',
        '.elementor-location-single',
        '.elementor-location-archive',
    ];
}

function surfaceSequence() {
    return [
        { path: '/surface/episode', label: 'Episode surface', selector: '[data-elementor-type="single-post"]', next: '/surface/profile', id: 23096 },
        { path: '/surface/profile', label: 'Profile surface', selector: '[data-elementor-type="wp-post"]', next: '/surface/category', id: 23097 },
        { path: '/surface/category', label: 'Category surface', selector: '[data-elementor-type="archive"]', next: '/surface/tag', id: 23098 },
        { path: '/surface/tag', label: 'Tag surface', selector: '.elementor-location-archive', next: '/surface/page', id: 23099 },
        { path: '/surface/page', label: 'Page surface', selector: '.elementor-location-single', next: '/surface/single', id: 23100 },
        { path: '/surface/single', label: 'Generic single surface', selector: '[data-elementor-type="single"]', next: '/surface/home', id: 23101 },
        { path: '/surface/home', label: 'Podcast home restored', selector: '[data-elementor-type="wp-page"]', next: '/surface/episode', id: 23095 },
    ];
}

function surfaceDocument(surface) {
    const root = surface.path === '/surface/home'
        ? homeSurfaceMarkup(surface.next, false)
        : surfaceRootMarkup(surface.selector, surface.id, `<h1>${escapeHtml(surface.label)}</h1><a id="surface-next" href="${escapeHtml(surface.next)}">Continue</a>`);
    return `<!doctype html><html lang="en"><head><title>${escapeHtml(surface.label)}</title>
<link rel="canonical" href="${escapeHtml(surface.path)}">
<link rel="stylesheet" href="/assets/home-interactions.css">
<meta name="description" content="${escapeHtml(surface.label)} description">
<script id="elementor-frontend-js-before">var elementorFrontendConfig={"post":{"id":${surface.id},"title":${JSON.stringify(surface.label)}}};</script>
<script type="text/javascript">${wordfenceHumanLoggerSource(surface.id.toString(16).padStart(32, '0'))}</script>
<script src="/assets/home-interactions.js"></script>
<script src="/assets/frontend-player.js"></script>
</head><body class="surface-body">${root}${breadcrumbCompanionTemplate(surface.label + ' breadcrumb')}</body></html>`;
}

function surfaceRootMarkup(selector, elementorId, content) {
    if (selector.startsWith('[data-elementor-type=')) {
        const type = selector.match(/"([^"]+)"/)?.[1] || '';
        return `<main class="elementor" data-elementor-type="${escapeHtml(type)}" data-elementor-id="${elementorId}">${content}</main>`;
    }
    return `<main class="elementor ${escapeHtml(selector.slice(1))}" data-elementor-id="${elementorId}">${content}</main>`;
}

function homeSurfaceMarkup(destination, includePlayerTrigger) {
    return `<main class="elementor" data-elementor-type="wp-page" data-elementor-id="23095">
<h1>${includePlayerTrigger ? 'Podcast home' : 'Podcast home restored'}</h1>
${includePlayerTrigger ? `<button id="listen" type="button" data-smp-player-trigger data-smp-audio-src="${escapeHtml(origin)}/media.mp3" data-smp-title="Test episode">Listen</button><a id="navigate" href="${escapeHtml(destination)}">Navigate</a>` : `<a id="surface-next" href="${escapeHtml(destination)}">Continue</a>`}
<div class="mpp-topic-chip"><button class="elementor-button" data-topic="all">All</button></div>
<div class="mpp-topic-chip"><button class="elementor-button" data-topic="technology">Technology</button></div>
<div class="mpp-episode-search"><form><input type="text" value=""></form></div>
<div class="mpp-episode-status"><span class="elementor-heading-title"></span></div>
<div data-id="c04f006"><div class="e-loop-item"><span class="mpp-episode-number">Episode 158</span><span class="mpp-episode-guest"><a href="/profile/ada"><span class="mpp-guest-n">Ada Guest</span></a></span></div><div class="e-loop-item"><span class="mpp-episode-number">Episode 154</span><span class="mpp-episode-guest"><a href="/profile/mara"><span class="mpp-guest-n">Mara Founder</span></a></span></div></div>
</main>`;
}

function breadcrumbCompanionTemplate(label) {
    return `<template data-smp-ajax-companion="smpi-breadcrumbs"><div class="smpi-breadcrumbs-band" data-smpi-breadcrumbs-band><nav class="smpi-breadcrumbs" data-smpi-breadcrumbs aria-label="breadcrumbs"><p><a href="/">Home</a><span aria-current="page">${escapeHtml(label)}</span></p></nav></div></template>`;
}

function companionRuntimeBootstrap() {
    return `(function(){
function render(){
    document.querySelectorAll('[data-smp-ajax-companion-rendered="smpi-breadcrumbs"]').forEach(function(node){node.remove();});
    var source=document.querySelector('template[data-smp-ajax-companion="smpi-breadcrumbs"]');
    if(!source||!source.content.firstElementChild)return;
    var rendered=source.content.firstElementChild.cloneNode(true);
    rendered.setAttribute('data-smp-ajax-companion-rendered','smpi-breadcrumbs');
    var header=document.querySelector('header');
    if(header)header.insertAdjacentElement('afterend',rendered);else document.body.insertBefore(rendered,document.body.firstChild);
}
document.addEventListener('smp:content-ready',render);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render,{once:true});else render();
})();`;
}

function targetDocument(label, path) {
    const headMetadata = path === '/next'
        ? `<link rel="canonical" href="${escapeHtml(path)}">
<link rel="stylesheet" href="target.css">
<meta name="description" content="${escapeHtml(label)} description">
<meta property="og:title" content="${escapeHtml(label)}">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"${escapeHtml(label)}"}</script>`
        : '';
    return `<!doctype html><html lang="en-GB"><head>
<title>${escapeHtml(label)}</title>
${headMetadata}
</head><body class="target-page"><header><a href="/">Home</a></header>
<main data-smp-ajax-root="content"><h1>${escapeHtml(label)}</h1><a href="/next">Continue</a><button id="ap-toggle" type="button">Legacy</button><audio id="ap-audio" src="/target-legacy.mp3"></audio></main>
</body></html>`;
}

function initialAssetMarkup(mode) {
    if (mode === 'self-removing-cloudflare') {
        return '<script src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script>';
    }
    if (mode !== 'divergent-assets') return '';
    const wordfence = wordfenceHumanLoggerSource('A'.repeat(32));
    return `<link id="home-surface-css" rel="stylesheet" href="/home.css">
<style id="elementor-frontend-inline-css">:root{--elementor-page:home}</style>
<style id="elementor-post-1">.elementor-post-1{color:#111}</style>
<style id="loop-10">.loop-10{display:grid}</style>
<style>.shared-anonymous{box-sizing:border-box}</style>
<script type="text/javascript">${wordfence}</script>`;
}

function cloudflareTargetDocument() {
    return `<!doctype html><html lang="en"><head><title>Cloudflare target</title>
<link rel="canonical" href="/self-removing-cloudflare"><meta name="description" content="Cloudflare target description">
</head><body class="cloudflare-target"><main data-smp-ajax-root="content"><h1>Cloudflare target</h1><p>Known self-removing decoder remains inert.</p></main>
<script src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script></body></html>`;
}

function continuityTargetDocument() {
    return `<!doctype html><html lang="en"><head><title>Continuity target</title>
<link rel="canonical" href="/continuity"><link rel="stylesheet" href="/target.css">
<meta name="description" content="Continuity description"><meta property="og:title" content="Continuity target">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"PodcastEpisode","name":"Continuity target"}</script>
</head><body><main data-smp-ajax-root="content"><h1>Continuity target</h1><p>Audio remains outside this root.</p></main></body></html>`;
}

function divergentDocument(kind) {
    const episode = kind === 'episode';
    const title = episode ? 'Episode surface' : 'Home surface';
    const managedStyles = episode
        ? `<style id="wp-block-library-inline-css">.wp-block{max-width:100%}</style>
<style id="elementor-frontend-inline-css">:root{--elementor-page:episode}</style>
<style id="elementor-post-2">.elementor-post-2{color:#222}</style>
<style id="loop-20">.loop-20{display:grid}</style>`
        : `<style id="elementor-frontend-inline-css">:root{--elementor-page:home}</style>
<style id="elementor-post-1">.elementor-post-1{color:#111}</style>
<style id="loop-10">.loop-10{display:grid}</style>`;
    const dynamicAssets = episode
        ? '<script id="e-sticky-js" src="/wp-content/plugins/elementor-pro/assets/lib/sticky/jquery.sticky.min.js?ver=4.2.1"></script>'
        : `<script id="jet-plugins-js" src="/wp-content/plugins/jet-engine/assets/lib/jet-plugins/jet-plugins.js?ver=1.1.0"></script>
<script id="jet-engine-data-stores-js" src="/wp-content/plugins/jet-engine/assets/js/frontend/modules/data-stores.js?ver=3.8.13.2"></script>
<script id="jet-engine-frontend-js" src="/wp-content/plugins/jet-engine/assets/js/frontend/frontend.js?ver=3.8.13.2"></script>`;
    const destination = episode ? '/divergent-home' : '/divergent-episode';
    return `<!doctype html><html lang="en"><head><title>${title}</title>
<link rel="canonical" href="/${kind}"><link id="${kind}-surface-css" rel="stylesheet" href="/${kind}.css">
<meta name="description" content="${title} description">
${managedStyles}
<style>.shared-anonymous{box-sizing:border-box}</style>
<script id="elementor-frontend-js-before">var elementorFrontendConfig={"post":{"id":${episode ? 2 : 1},"title":"${title}"}};</script>
<script id="elementor-pro-frontend-js-before">var ElementorProFrontendConfig={"version":"${kind}"};</script>
<script type="text/javascript">${wordfenceHumanLoggerSource((episode ? 'B' : 'C').repeat(32))}</script>
${dynamicAssets}
</head><body class="${kind}-body"><main data-smp-ajax-root="content" class="elementor"><h1>${title}</h1><div class="elementor-element" data-element_type="widget" data-widget_type="heading.default">${title} widget</div><a id="surface-next" href="${destination}">Switch surface</a></main></body></html>`;
}

function historyFallbackDocument(expectedReason) {
    return `<!doctype html><html><head><title>History fallback proof</title></head><body><pre id="test-result">pending</pre>
<script>
(function(){
    var reason=sessionStorage.getItem('smp-test-rejection') || '';
    var proof=sessionStorage.getItem('smp-history-proof') || '';
    var passed=reason===${JSON.stringify(expectedReason)} && proof==='pass';
    document.body.setAttribute('data-test-status',passed?'pass':'fail');
    document.getElementById('test-result').textContent=passed?'PASS parked/foreign history used a hard reload':'Expected ${escapeJs(expectedReason)} with parked proof; received '+reason+' / '+proof;
})();
</script></body></html>`;
}

function maliciousDocument(path) {
    let head = '';
    let rootAttribute = '';
    if (path === '/malicious-style-onload') head = '<link rel="stylesheet" href="/target.css" onload="window.__maliciousOnload=true">';
    if (path === '/malicious-script-onerror') head = '<script src="/missing-malicious.js" onerror="window.__maliciousOnerror=true"></script>';
    if (path === '/malicious-content-onclick') rootAttribute = ' onclick="window.__maliciousOnclick=true"';
    return `<!doctype html><html><head><title>Rejected event attribute</title>${head}</head><body><main data-smp-ajax-root="content"${rootAttribute}><h1>Rejected event attribute</h1></main></body></html>`;
}

function rejectedStyleDocument(path) {
    const style = path === '/unsafe-inline-style'
        ? '<style id="elementor-post-99">.unsafe{width:expression(alert(1))}</style>'
        : '<style>.target-only-anonymous{color:#123456}</style>';
    return `<!doctype html><html><head><title>Rejected style</title>${style}</head><body><main data-smp-ajax-root="content"><h1>Rejected style</h1></main></body></html>`;
}

function elementorTargetDocument() {
    return `<!doctype html><html lang="en"><head><title>Elementor target</title>
<link rel="canonical" href="/elementor-ready"><meta name="description" content="Elementor target description">
<script id="elementor-frontend-js-before">var elementorFrontendConfig={"post":{"id":2,"title":"Elementor target"}};
//# sourceURL=elementor-frontend-js-before</script>
<script id="elementor-pro-frontend-js-before">var ElementorProFrontendConfig={"version":"test-target"};
//# sourceURL=elementor-pro-frontend-js-before</script>
<script id="jet-engine-frontend-js-extra">var JetEngineSettings={"post_id":"2","queried_object_class":"WP_Post"};
//# sourceURL=jet-engine-frontend-js-extra</script>
</head><body><main data-smp-ajax-root="content" class="elementor"><h1>Elementor target</h1><div class="elementor-element" data-element_type="widget" data-widget_type="heading.default">Widget</div></main></body></html>`;
}

function recaptchaTargetDocument(source) {
    return `<!doctype html><html lang="en"><head><title>Elementor reCAPTCHA target</title>
<link rel="canonical" href="/elementor-recaptcha"><meta name="description" content="Elementor reCAPTCHA target description">
<script id="elementor-recaptcha_v3-api-js" src="${escapeHtml(source)}"></script>
</head><body><main data-smp-ajax-root="content" class="elementor"><h1>Elementor reCAPTCHA target</h1><div class="elementor-element elementor-widget-form" data-element_type="widget" data-widget_type="form.default"><form class="elementor-form"><input name="email" type="email"></form></div></main></body></html>`;
}

function wordfenceTargetDocument(tampered = false) {
    const endpoint = `${origin}/?wordfence_lh=1&hid=ABCDEF0123456789ABCDEF0123456789`;
    let source = wordfenceHumanLoggerFixture().replace('__WORDFENCE_URL__', endpoint);
    if (tampered) source = source.replace('window.wfLogHumanRan = true;', 'window.wfLogHumanRan = true; window.__tamperedWordfence = true;');
    return `<!doctype html><html lang="en"><head><title>${tampered ? 'Tampered' : 'Fresh'} Wordfence target</title>
<link rel="canonical" href="/${tampered ? 'tampered-wordfence' : 'wordfence-fresh'}"><meta name="description" content="Wordfence target description">
<script type="text/javascript">${source}</script>
</head><body><main data-smp-ajax-root="content"><h1>${tampered ? 'Tampered' : 'Fresh'} Wordfence target</h1></main></body></html>`;
}

function wordfenceHumanLoggerFixture() {
    const match = /parseWordfenceHumanLogger\.template = ("(?:[^"\\]|\\.)*");/.exec(runtime.toString('utf8'));
    if (!match) throw new Error('Missing captured Wordfence human-logger fixture');
    const decoded = JSON.parse(match[1]);
    const digest = createHash('sha256').update(decoded).digest('hex');
    if (digest !== '801dba788af0499ac120a1090dab46ce5a43d7ab8056cb278df180babc756291') throw new Error('Wordfence human-logger fixture hash mismatch');
    return decoded;
}

function wordfenceHumanLoggerSource(hid) {
    return wordfenceHumanLoggerFixture().replace('__WORDFENCE_URL__', `${origin}/?wordfence_lh=1&hid=${hid}`);
}

function jetInlineTargetDocument(mode = 'trusted-jet-inline') {
    const scripts = jetBeforeScriptSources();
    let dataStoreSource = scripts.dataStores;
    if (mode === 'tampered-jet-inline') {
        dataStoreSource += '\nwindow.__tamperedJetInline = true;';
    } else if (mode === 'tampered-jet-whitespace') {
        dataStoreSource = dataStoreSource.replace('return store.length;', 'return\nstore.length;');
    }
    const label = mode === 'aborted-jet-inline' ? 'Aborted' : (mode === 'trusted-jet-inline' ? 'Trusted' : 'Tampered');
    return `<!doctype html><html lang="en"><head><title>${label} JetEngine target</title>
<link rel="canonical" href="/${escapeHtml(mode)}"><meta name="description" content="JetEngine target description">
<script id="jet-engine-data-stores-js-before">${dataStoreSource}
//# sourceURL=jet-engine-data-stores-js-before</script>
<script id="jet-engine-data-stores-js" src="/wp-content/plugins/jet-engine/assets/js/frontend/modules/data-stores.js?ver=3.8.13.2"></script>
<script id="jet-engine-frontend-js-before">${scripts.frontend}
//# sourceURL=jet-engine-frontend-js-before</script>
<script id="jet-engine-frontend-js" src="/wp-content/plugins/jet-engine/assets/js/frontend/frontend.js?ver=3.8.13.2"></script>
</head><body><main data-smp-ajax-root="content"><h1>${label} JetEngine target</h1></main></body></html>`;
}

function jetBeforeScriptSources() {
    const runtimeSource = runtime.toString('utf8');
    const fixtures = {
        dataStores: ['jet-engine-data-stores-js-before', 'b5a3263bf555f71f8c6de220981a3b317c8608a078b2e021cbbb91726ffde4dc'],
        frontend: ['jet-engine-frontend-js-before', '343ca28c23c2d9d7e87c13c6581b8e27ee97a5a1921fd50df31d7b2aff472e94']
    };
    return Object.fromEntries(Object.entries(fixtures).map(([name, fixture]) => {
        const pattern = new RegExp("'" + fixture[0] + "': (\\\"(?:[^\\\"\\\\]|\\\\.)*\\\")");
        const match = pattern.exec(runtimeSource);
        if (!match) throw new Error(`Missing captured JetEngine fixture: ${fixture[0]}`);
        const decoded = JSON.parse(match[1]);
        const digest = createHash('sha256').update(decoded).digest('hex');
        if (digest !== fixture[1]) throw new Error(`JetEngine fixture hash mismatch: ${fixture[0]}`);
        return [name, decoded];
    }));
}

function jetInlineTestBootstrap(abortBeforeInline = false) {
    return `<script>
window.__jetBeforeEvents=[];
window.__jetFilters=[];
window.jQuery=function(){return {on:function(name){window.__jetBeforeEvents.push(name);}};};
window.JetPlugins={hooks:{addFilter:function(){window.__jetFilters.push(Array.prototype.slice.call(arguments,0,2).join(':'));}}};
${abortBeforeInline ? `window.__nativeStringTrim=String.prototype.trim;
window.__jetDataStoreTrimCalls=0;
window.__jetAbortRevalidationReached=false;
String.prototype.trim=function(){
    var value=String(this);
    if(value.indexOf('window.JetEngineStores = window.JetEngineStores')!==-1){
        window.__jetDataStoreTrimCalls+=1;
        if(window.__jetDataStoreTrimCalls===4){
            window.__jetAbortRevalidationReached=true;
            String.prototype.trim=window.__nativeStringTrim;
            document.querySelector('[data-smp-audio]').pause();
        }
    }
    return window.__nativeStringTrim.call(this);
};` : ''}
</script>`;
}

function elementorUnreadyDocument() {
    return '<!doctype html><html><head><title>Elementor unavailable</title></head><body><main data-smp-ajax-root="content" class="elementor"><h1>Elementor unavailable</h1></main></body></html>';
}

function fallbackDocument(expectedReason, extraHead = '', withRoot = true, rootClass = '') {
    const rootMarkup = withRoot
        ? `<main data-smp-ajax-root="content" class="${escapeHtml(rootClass)}"><h1>Direct fallback</h1></main>`
        : '<main><h1>Direct fallback without a matching root</h1></main>';
    return `<!doctype html><html><head><title>Direct fallback</title>${extraHead}</head><body>
${rootMarkup}<pre id="test-result">pending</pre>
<script>
(function(){
    var actual=sessionStorage.getItem('smp-test-rejection') || '';
    var result=document.getElementById('test-result');
    if(actual===${JSON.stringify(expectedReason)}){
        document.body.setAttribute('data-test-status','pass');
        result.textContent='PASS hard fallback: '+actual;
    }else{
        document.body.setAttribute('data-test-status','fail');
        result.textContent='Expected ${escapeJs(expectedReason)}, received '+actual;
    }
})();
</script></body></html>`;
}

function rootMismatchFallbackDocument(expectedReason, rootMarkup) {
    return `<!doctype html><html><head><title>Root mismatch fallback</title></head><body>${rootMarkup}<pre id="test-result">pending</pre>
<script>
(function(){
    var actual=sessionStorage.getItem('smp-test-rejection')||'';
    var passed=actual===${JSON.stringify(expectedReason)};
    document.body.setAttribute('data-test-status',passed?'pass':'fail');
    document.getElementById('test-result').textContent=passed?'PASS hard fallback: '+actual:'Expected ${escapeJs(expectedReason)}, received '+actual;
})();
</script></body></html>`;
}

function instrumentationScript() {
    return `(function(){
window.__smpTest={popstateBindings:0,scrollBindings:0,scrollRemovals:0,pushes:0,replaces:0,fetches:0,cancellations:0,lastCancellation:'',homeBindings:{click:0,keydown:0,input:0,submit:0,contentReady:0,domReady:0}};
window.__dynamicLoads=[];
window.__dynamicScriptUrls=[];
var nativeHeadAppend=document.head.appendChild.bind(document.head);
document.head.appendChild=function(node){
    if(node&&node.tagName==='SCRIPT'&&node.id==='smp-wordfence-human-logger'){
        window.__dynamicLoads.push(node.id);
        window.__dynamicScriptUrls.push(node.src);
        setTimeout(function(){node.dispatchEvent(new Event('load'));},0);
        return node;
    }
    if(node&&node.tagName==='SCRIPT'&&node.id==='elementor-recaptcha_v3-api-js'){
        window.__dynamicLoads.push(node.id);
        window.__dynamicScriptUrls.push(node.src);
        setTimeout(function(){node.dispatchEvent(new Event('load'));},0);
        return node;
    }
    return nativeHeadAppend(node);
};
var nativeAdd=window.addEventListener.bind(window);
var nativeRemove=window.removeEventListener.bind(window);
window.addEventListener=function(type,listener,options){
    if(type==='popstate') window.__smpTest.popstateBindings+=1;
    if(type==='scroll') window.__smpTest.scrollBindings+=1;
    return nativeAdd(type,listener,options);
};
window.removeEventListener=function(type,listener,options){
    if(type==='scroll') window.__smpTest.scrollRemovals+=1;
    return nativeRemove(type,listener,options);
};
var nativeDocumentAdd=document.addEventListener.bind(document);
document.addEventListener=function(type,listener,options){
    var name=listener&&listener.name?listener.name:'';
    if(type==='click'&&name==='handleClick')window.__smpTest.homeBindings.click+=1;
    if(type==='keydown'&&name==='handleKeydown')window.__smpTest.homeBindings.keydown+=1;
    if(type==='input'&&name==='handleInput')window.__smpTest.homeBindings.input+=1;
    if(type==='submit'&&name==='handleSubmit')window.__smpTest.homeBindings.submit+=1;
    if(type==='smp:content-ready'&&name==='refresh')window.__smpTest.homeBindings.contentReady+=1;
    if(type==='DOMContentLoaded'&&name==='refresh')window.__smpTest.homeBindings.domReady+=1;
    return nativeDocumentAdd(type,listener,options);
};
var nativePush=history.pushState.bind(history);
var nativeReplace=history.replaceState.bind(history);
history.pushState=function(){window.__smpTest.pushes+=1;return nativePush.apply(history,arguments);};
history.replaceState=function(){window.__smpTest.replaces+=1;return nativeReplace.apply(history,arguments);};
var nativeFetch=window.fetch.bind(window);
window.fetch=function(){window.__smpTest.fetches+=1;return nativeFetch.apply(window,arguments);};
function mediaTime(media){
    var base=Number(media.__smpBaseTime)||0;
    return media.__smpPlaying ? base+Math.max(0,(performance.now()-(media.__smpStarted||performance.now()))/1000) : base;
}
Object.defineProperties(HTMLMediaElement.prototype,{
    paused:{configurable:true,get:function(){return !this.__smpPlaying;}},
    ended:{configurable:true,get:function(){return !!this.__smpEnded;}},
    currentSrc:{configurable:true,get:function(){return this.src || this.getAttribute('src') || '';}},
    currentTime:{configurable:true,get:function(){return mediaTime(this);},set:function(value){this.__smpBaseTime=Math.max(0,Number(value)||0);this.__smpStarted=performance.now();}},
    duration:{configurable:true,get:function(){return 120;}},
    readyState:{configurable:true,get:function(){return this.__smpPlaying ? 4 : 0;}}
});
HTMLMediaElement.prototype.play=function(){this.__smpBaseTime=mediaTime(this);this.__smpStarted=performance.now();this.__smpEnded=false;this.__smpPlaying=true;this.dispatchEvent(new Event('play'));return Promise.resolve();};
HTMLMediaElement.prototype.pause=function(){if(!this.__smpPlaying)return;this.__smpBaseTime=mediaTime(this);this.__smpPlaying=false;this.dispatchEvent(new Event('pause'));};
HTMLMediaElement.prototype.load=function(){};
window.__smpMedia={
    end:function(media){media.__smpBaseTime=media.duration;media.__smpPlaying=false;media.__smpEnded=true;media.dispatchEvent(new Event('ended'));},
    error:function(media){media.__smpBaseTime=mediaTime(media);media.__smpPlaying=false;media.dispatchEvent(new Event('error'));}
};
document.addEventListener('smp:navigation-rejected',function(event){sessionStorage.setItem('smp-test-rejection',event.detail.reason);});
document.addEventListener('smp:navigation-cancelled',function(event){window.__smpTest.cancellations+=1;window.__smpTest.lastCancellation=event.detail.reason||'';});
})();`;
}

function elementorTestBootstrap() {
    return `<script id="elementor-frontend-js-before">
window.elementorFrontendConfig={post:{id:1,title:'Initial'}};
window.ElementorProFrontendConfig={version:'test-initial'};
window.__elementorReadyCalls=0;
window.__elementorReadyTypes=[];
window.__elementorFormSubmits=0;
window.jQuery=function(node){return {0:node,length:1};};
window.elementorFrontend={config:window.elementorFrontendConfig,elementsHandler:{runReadyTrigger:function(scope){
    var node=scope&&scope[0]?scope[0]:scope;
    if(!node||!node.getAttribute('data-element_type'))return;
    window.__elementorReadyCalls+=1;
    window.__elementorReadyTypes.push(node.getAttribute('data-widget_type')||node.getAttribute('data-element_type'));
    if(node.getAttribute('data-widget_type')==='form.default'){
        var form=node.querySelector('form');
        form.setAttribute('data-elementor-handler-ready','1');
        form.addEventListener('submit',function(event){event.preventDefault();window.__elementorFormSubmits+=1;});
    }
}}};
window.elementorProFrontend={config:window.ElementorProFrontendConfig};
</script>`;
}

function clientTestScript(mode) {
    return `(function(){
var mode=${JSON.stringify(mode)};
function fail(message){document.body.setAttribute('data-test-status','fail');document.getElementById('test-result').textContent=message;}
function pass(message){document.body.setAttribute('data-test-status','pass');document.getElementById('test-result').textContent=message;}
function assert(value,message){if(!value)throw new Error(message);}
function waitFor(name){return new Promise(function(resolve,reject){
    var timer=setTimeout(function(){reject(new Error('Timed out waiting for '+name));},3200);
    document.addEventListener(name,function(event){clearTimeout(timer);resolve(event);},{once:true});
});}
function delay(milliseconds){return new Promise(function(resolve){setTimeout(resolve,milliseconds);});}
function observedClick(node){
    var observed=null;
    var watcher=function(event){observed=event.defaultPrevented;event.preventDefault();};
    document.addEventListener('click',watcher,{capture:true,once:true});
    node.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,button:0}));
    return observed;
}
function click(node){node.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,button:0}));}
document.addEventListener('DOMContentLoaded',function(){setTimeout(async function(){try{
    var counters=window.__smpTest;
    assert(counters.popstateBindings===0,'popstate bound before playback');
    assert(counters.scrollBindings===0,'scroll bound before playback');
    assert(counters.replaces===0 && counters.pushes===0,'history changed before playback');
    assert(history.scrollRestoration!=='manual','manual scroll restoration enabled before playback');
    assert(document.querySelectorAll('[data-smp-player] audio').length===1,'persistent player audio missing');
    assert(document.querySelectorAll('#ap-audio').length===0,'legacy audio still coexists');

    if(mode==='no-playback'){
        assert(observedClick(document.getElementById('navigate'))===false,'inactive navigation was intercepted');
        assert(counters.fetches===0 && counters.replaces===0 && counters.popstateBindings===0,'inactive navigation changed AJAX/history state');
        pass('PASS inactive playback leaves native navigation untouched');
        return;
    }

    click(document.getElementById('listen'));
    await Promise.resolve();
    assert(counters.replaces===0 && counters.popstateBindings===0,'track selection changed history');
    var playerAudio=document.querySelector('[data-smp-audio]');

    if(mode==='video-switch'){
        assert(!playerAudio.paused,'audio did not begin before the format switch');
        var watch=document.getElementById('watch');
        assert(observedClick(watch)===true,'native Watch link was not intercepted');
        var player=document.querySelector('[data-smp-player]');
        var frame=player.querySelector('[data-smp-video]');
        assert(player.getAttribute('data-smp-mode')==='video','Watch did not select video mode');
        assert(playerAudio.paused,'audio continued while video mode was selected');
        assert(frame.src.indexOf('/embed/yzMcrZCYh5Y')!==-1,'real episode video was not loaded into the persistent iframe');
        window.dispatchEvent(new MessageEvent('message',{origin:'https://www.youtube-nocookie.com',source:frame.contentWindow,data:JSON.stringify({event:'onReady'})}));
        window.dispatchEvent(new MessageEvent('message',{origin:'https://www.youtube-nocookie.com',source:frame.contentWindow,data:JSON.stringify({event:'onStateChange',info:1})}));
        window.dispatchEvent(new MessageEvent('message',{origin:'https://www.youtube-nocookie.com',source:frame.contentWindow,data:JSON.stringify({event:'infoDelivery',info:{playerState:1,currentTime:14,duration:120,muted:false}})}));
        await delay(40);
        assert(document.body.classList.contains('smp-podcast-player-video-visible'),'video mode did not expose its responsive surface');
        var videoReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'active video did not enable safe AJAX navigation');
        await videoReady;
        assert(document.querySelector('[data-smp-video]')===frame&&player.getAttribute('data-smp-mode')==='video','video surface was replaced during AJAX navigation');
        click(player.querySelector('[data-smp-mode-button="audio"]'));
        await Promise.resolve();
        assert(player.getAttribute('data-smp-mode')==='audio'&&!document.body.classList.contains('smp-podcast-player-video-visible'),'Audio switch did not restore the compact player');
        assert(!playerAudio.paused&&playerAudio.currentTime>=13.5,'video timestamp did not transfer back to audio');
        assert(player.querySelector('[data-smp-video-shell]').hidden,'video remained visible after switching to audio');
        pass('PASS persistent video, AJAX navigation, and audio/video switching share one dynamic episode');
        return;
    }

    if(mode==='surface-matrix'){
        var homeApi=window.__mppHomeInteractions23128;
        assert(homeApi&&homeApi.version==='3.1.0'&&homeApi.isActive(),'owned homepage initializer did not activate');
        assert(document.querySelector('[data-smp-ajax-companion-rendered="smpi-breadcrumbs"] [aria-current="page"]').textContent==='Podcast home breadcrumb','initial inert breadcrumb companion did not render');
        assert(document.getElementById('ep-158')&&document.getElementById('ep-154'),'initial homepage cards were not hydrated');
        assert(document.querySelector('#ep-158 .mpp-episode-guest a').getAttribute('aria-label').indexOf('Ada Guest')!==-1,'initial card accessibility metadata was not hydrated');

        click(document.querySelector('[data-topic="technology"]'));
        assert(!document.getElementById('ep-158').hidden&&document.getElementById('ep-154').hidden,'topic filtering did not apply the owned topic map');
        click(document.querySelector('[data-topic="all"]'));
        var search=document.querySelector('.mpp-episode-search input');
        search.value='Mara';
        search.dispatchEvent(new Event('input',{bubbles:true}));
        assert(document.getElementById('ep-158').hidden&&!document.getElementById('ep-154').hidden,'homepage search did not filter cards');
        search.value='';
        search.dispatchEvent(new Event('input',{bubbles:true}));

        var inserted=document.createElement('div');
        inserted.className='e-loop-item';
        inserted.innerHTML='<span class="mpp-episode-number">Episode 153</span><span class="mpp-episode-guest"><a href="/profile/new"><span class="mpp-guest-n">New Guest</span></a></span>';
        document.querySelector('[data-id="c04f006"]').appendChild(inserted);
        await delay(100);
        assert(inserted.id==='ep-153'&&inserted.dataset.topics.indexOf('science')!==-1,'MutationObserver did not hydrate an inserted episode card');

        var detachedHome=document.querySelector('[data-elementor-id="23095"]');
        var detachedList=detachedHome.querySelector('[data-id="c04f006"]');
        await delay(70);
        var playbackBefore=playerAudio.currentTime;
        var expectedSurfaces=['Episode surface','Profile surface','Category surface','Tag surface','Page surface','Generic single surface','Podcast home restored'];
        for(var surfaceIndex=0;surfaceIndex<expectedSurfaces.length;surfaceIndex+=1){
            var surfaceReady=waitFor('smp:after-navigate');
            var surfaceLink=surfaceIndex===0?document.getElementById('navigate'):document.getElementById('surface-next');
            assert(surfaceLink&&observedClick(surfaceLink)===true,'trusted surface '+expectedSurfaces[surfaceIndex]+' was not intercepted');
            await surfaceReady;
            assert(document.querySelector('main h1').textContent===expectedSurfaces[surfaceIndex],'trusted surface '+expectedSurfaces[surfaceIndex]+' did not swap');
            assert(document.querySelectorAll('template[data-smp-ajax-companion="smpi-breadcrumbs"]').length===1&&document.querySelectorAll('[data-smp-ajax-companion-rendered="smpi-breadcrumbs"]').length===1,'breadcrumb companion was duplicated or dropped on '+expectedSurfaces[surfaceIndex]);
            assert(document.querySelector('[data-smp-ajax-companion-rendered="smpi-breadcrumbs"] [aria-current="page"]').textContent===expectedSurfaces[surfaceIndex]+' breadcrumb','breadcrumb companion remained stale on '+expectedSurfaces[surfaceIndex]);
            if(surfaceIndex===0){
                assert(!homeApi.isActive(),'homepage observer remained active after its root was replaced');
                var detachedCard=document.createElement('div');
                detachedCard.className='e-loop-item';
                detachedCard.innerHTML='<span class="mpp-episode-number">Episode 156</span>';
                detachedList.appendChild(detachedCard);
                await delay(100);
                assert(!detachedCard.id,'detached homepage root was still observed after navigation');
            }
        }

        assert(homeApi.isActive(),'homepage initializer did not reactivate after returning from another surface');
        assert(document.getElementById('ep-158')&&document.getElementById('ep-154'),'restored homepage cards were not rehydrated');
        assert(counters.homeBindings.click===1&&counters.homeBindings.keydown===1&&counters.homeBindings.input===1&&counters.homeBindings.submit===1&&counters.homeBindings.contentReady===1&&counters.homeBindings.domReady===1,'homepage delegated listeners were registered more than once');
        assert(window.__fetchedInlineExecuted!==true,'fetched inline executable code ran during surface navigation');
        assert(document.querySelector('[data-smp-audio]')===playerAudio&&!playerAudio.paused&&playerAudio.currentTime>playbackBefore+0.1,'audio continuity was lost across the trusted surface matrix');
        assert(counters.pushes===expectedSurfaces.length&&counters.popstateBindings===1&&counters.scrollBindings===1,'surface navigation duplicated or skipped history ownership');
        pass('PASS homepage lifecycle and trusted Elementor surface matrix preserve active audio');
        return;
    }

    if(mode==='disabled'){
        assert(observedClick(document.getElementById('navigate'))===false,'disabled AJAX navigation was intercepted');
        assert(counters.fetches===0 && counters.replaces===0 && counters.popstateBindings===0,'disabled AJAX changed navigation state');
        pass('PASS disabled AJAX leaves native navigation untouched');
        return;
    }

    if(mode==='self-removing-cloudflare'){
        assert(window.__cloudflareEmailDecoderRuns===1,'initial Cloudflare decoder did not run exactly once');
        assert(!document.querySelector('script[src*="/cloudflare-static/email-decode"]'),'initial Cloudflare decoder did not remove itself');
        var cloudflareBefore=playerAudio.currentTime;
        var cloudflareReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'Cloudflare target navigation was not intercepted');
        await cloudflareReady;
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Cloudflare target','Cloudflare target content was not swapped');
        assert(window.__cloudflareEmailDecoderRuns===1,'fetched Cloudflare decoder was executed again');
        assert(!document.querySelector('script[src*="/cloudflare-static/email-decode"]'),'fetched Cloudflare decoder was imported into the live document');
        assert(document.querySelector('[data-smp-audio]')===playerAudio&&!playerAudio.paused&&playerAudio.currentTime>cloudflareBefore,'audio continuity was lost on the Cloudflare-script surface');
        assert(counters.pushes===1&&counters.popstateBindings===1,'Cloudflare-script surface did not use one AJAX history transition');
        pass('PASS known same-origin Cloudflare email decoder is ignored without execution');
        return;
    }

    if(mode==='trusted-jet-inline'){
        var jetBefore=playerAudio.currentTime;
        var jetReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'trusted JetEngine target navigation was not intercepted');
        await jetReady;
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Trusted JetEngine target','trusted JetEngine target did not swap');
        assert(window.JetEngineStores&&typeof window.JetEngineStores['local-storage'].getStore==='function','validated JetEngine data-store initializer did not execute');
        assert(window.__jetBeforeEvents.join(',')==='jet-engine/frontend/loaded','validated JetEngine frontend initializer did not register in source order');
        assert(window.__dynamicLoads.join(',')==='jet-engine-data-stores-js,jet-engine-frontend-js','JetEngine external assets did not load in source order');
        assert(document.querySelectorAll('#jet-engine-data-stores-js-before').length===1&&document.querySelectorAll('#jet-engine-frontend-js-before').length===1,'validated JetEngine initializers were duplicated or omitted');
        assert(window.__tamperedJetInline!==true,'tampered JetEngine code executed');
        assert(document.querySelector('[data-smp-audio]')===playerAudio&&!playerAudio.paused&&playerAudio.currentTime>jetBefore,'audio continuity was lost while loading validated JetEngine initializers');
        assert(counters.pushes===1&&counters.popstateBindings===1,'trusted JetEngine navigation did not own one AJAX transition');
        pass('PASS exact JetEngine before-initializers load in order without interrupting audio');
        return;
    }

    if(mode==='aborted-jet-inline'){
        assert(observedClick(document.getElementById('navigate'))===true,'aborted JetEngine target navigation was not intercepted');
        await delay(520);
        assert(window.__jetAbortRevalidationReached===true&&window.__jetDataStoreTrimCalls===4,'JetEngine abort fixture did not reach execution-time revalidation');
        assert(playerAudio.paused,'JetEngine abort fixture did not pause active playback');
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Initial content','aborted JetEngine navigation swapped content');
        assert(!window.JetEngineStores,'aborted JetEngine inline initializer executed');
        assert(window.__jetBeforeEvents.length===0,'aborted JetEngine frontend initializer registered');
        assert(window.__dynamicLoads.length===0,'aborted JetEngine navigation loaded external scripts');
        assert(counters.pushes===0&&counters.replaces===0,'aborted JetEngine navigation changed history');
        assert(counters.cancellations>=1,'aborted JetEngine navigation was not explicitly cancelled');
        assert(!document.body.classList.contains('smp-podcast-ajax-loading'),'aborted JetEngine navigation left loading state behind');
        pass('PASS aborted navigation cannot execute validated JetEngine inline initializers');
        return;
    }

    if(mode.indexOf('slow-')===0){
        assert(observedClick(document.getElementById('navigate'))===true,'slow navigation was not intercepted');
        await delay(75);
        if(mode==='slow-pause')playerAudio.pause();
        if(mode==='slow-close')click(document.querySelector('[data-smp-close]'));
        if(mode==='slow-ended')window.__smpMedia.end(playerAudio);
        if(mode==='slow-error')window.__smpMedia.error(playerAudio);
        await delay(520);
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Initial content','cancelled slow request still swapped content');
        assert(counters.pushes===0 && counters.replaces===0,'cancelled slow request changed history');
        assert(counters.popstateBindings===0 && counters.scrollBindings===0,'cancelled slow request started a history session');
        assert(counters.cancellations>=1,'slow request was not explicitly cancelled');
        assert(!document.body.classList.contains('smp-podcast-ajax-loading'),'cancelled slow request left loading state behind');
        assert(!document.querySelector('[data-smp-ajax-root]').hasAttribute('aria-busy'),'cancelled slow request left aria-busy behind');
        assert(history.scrollRestoration!=='manual','cancelled slow request retained manual scroll restoration');
        if(mode==='slow-close')assert(document.querySelector('[data-smp-player]').hidden,'close did not hide the player');
        if(mode==='slow-ended')assert(playerAudio.ended,'ended test did not enter ended state');
        pass('PASS '+mode+' aborts without session, swap, or hard fallback');
        return;
    }

    if(mode==='continuity'){
        await delay(70);
        var continuityBefore=playerAudio.currentTime;
        var continuityReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'continuity navigation was not intercepted');
        await continuityReady;
        assert(document.querySelector('[data-smp-audio]')===playerAudio,'successful navigation replaced the audio element');
        assert(!playerAudio.paused && playerAudio.currentTime>continuityBefore+0.1,'audio did not advance uninterrupted during navigation');
        assert(document.title==='Continuity target','continuity title was not synchronized');
        assert(document.querySelector('meta[name="description"]').content==='Continuity description','continuity description was not synchronized');
        assert(new URL(document.querySelector('link[rel="canonical"]').href).pathname==='/continuity','continuity canonical was not synchronized');
        var continuitySchema=JSON.parse(document.head.querySelector('script[type="application/ld+json"]').textContent);
        assert(continuitySchema['@type']==='PodcastEpisode' && continuitySchema.name==='Continuity target','continuity schema was not synchronized');
        assert(counters.popstateBindings===1 && history.scrollRestoration==='manual','continuity navigation did not activate bounded history ownership');
        pass('PASS audio advances uninterrupted while head and schema update');
        return;
    }

    if(mode==='elementor-recaptcha'){
        await delay(40);
        var recaptchaBefore=playerAudio.currentTime;
        var recaptchaReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'Elementor reCAPTCHA navigation was not intercepted');
        await recaptchaReady;
        var loadedRecaptcha=new URL(window.__dynamicScriptUrls[0]);
        assert(window.__dynamicLoads.join(',')==='elementor-recaptcha_v3-api-js','Elementor reCAPTCHA dependency was not loaded exactly once');
        assert(loadedRecaptcha.protocol==='https:'&&loadedRecaptcha.host==='www.google.com'&&loadedRecaptcha.pathname==='/recaptcha/api.js'&&loadedRecaptcha.searchParams.get('render')==='explicit','loaded reCAPTCHA dependency did not retain the exact trusted endpoint');
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Elementor reCAPTCHA target','Elementor reCAPTCHA content was not swapped');
        assert(window.__elementorReadyCalls===1,'Elementor lifecycle did not initialize the reCAPTCHA form surface');
        var initializedForm=document.querySelector('form.elementor-form');
        assert(initializedForm.getAttribute('data-elementor-handler-ready')==='1'&&window.__elementorReadyTypes.join(',')==='form.default','Elementor form widget handler was not attached');
        initializedForm.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
        assert(window.__elementorFormSubmits===1,'initialized Elementor form did not handle submit');
        assert(document.querySelector('[data-smp-audio]')===playerAudio&&!playerAudio.paused&&playerAudio.currentTime>recaptchaBefore,'audio continuity was lost on the Elementor reCAPTCHA surface');
        pass('PASS exact Elementor reCAPTCHA dependency loads without interrupting audio');
        return;
    }

    if(mode==='wordfence-fresh'){
        var wordfenceBefore=playerAudio.currentTime;
        var wordfenceReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'fresh Wordfence navigation was not intercepted');
        await wordfenceReady;
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Fresh Wordfence target','fresh Wordfence content was not swapped');
        assert(window.wfLogHumanRan!==true&&window.__dynamicLoads.length===0,'fetched Wordfence inline script executed instead of the safe logger');
        assert(window.__tamperedWordfence!==true,'tampered Wordfence code executed');
        document.dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));
        await delay(30);
        var wordfenceUrl=new URL(window.__dynamicScriptUrls[0]);
        assert(window.__dynamicLoads.join(',')==='smp-wordfence-human-logger','safe Wordfence logger did not load exactly once');
        assert(wordfenceUrl.origin===location.origin&&wordfenceUrl.pathname==='/'&&wordfenceUrl.searchParams.get('wordfence_lh')==='1'&&/^[a-f0-9]{32}$/i.test(wordfenceUrl.searchParams.get('hid')||'')&&wordfenceUrl.searchParams.has('r'),'safe Wordfence logger did not preserve the validated same-origin endpoint');
        document.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
        await delay(20);
        assert(window.__dynamicLoads.length===1,'safe Wordfence logger did not remove its listeners after the first human event');
        assert(document.querySelector('[data-smp-audio]')===playerAudio&&!playerAudio.paused&&playerAudio.currentTime>wordfenceBefore,'audio continuity was lost on the fresh Wordfence surface');
        pass('PASS exact fresh Wordfence logger is safely reconstructed without interrupting audio');
        return;
    }

    if(mode==='divergent-assets'){
        await delay(40);
        var divergentBefore=playerAudio.currentTime;
        var episodeReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'episode-like navigation was not intercepted');
        await episodeReady;
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Episode surface','episode-like content was not swapped');
        assert(document.getElementById('elementor-frontend-inline-css').textContent.indexOf('episode')!==-1,'divergent Elementor inline CSS was not replaced');
        assert(!!document.getElementById('wp-block-library-inline-css'),'target-only managed WordPress CSS was not added');
        assert(!!document.getElementById('elementor-post-2') && !document.getElementById('elementor-post-1'),'page-specific Elementor CSS was not reconciled');
        assert(!!document.querySelector('link[href$="/episode.css"]') && !document.querySelector('link[href$="/home.css"]'),'episode stylesheet set was not reconciled');
        assert(window.__dynamicLoads.join(',')==='e-sticky-js','allowlisted sticky dependency was not loaded exactly once');
        assert(window.__elementorReadyCalls===1 && window.elementorFrontendConfig.post.id===2,'episode Elementor lifecycle/config was not updated');

        var homeReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('surface-next'))===true,'home-like return navigation was not intercepted');
        await homeReady;
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Home surface','home-like content was not swapped');
        assert(document.getElementById('elementor-frontend-inline-css').textContent.indexOf('home')!==-1,'home Elementor inline CSS was not restored');
        assert(!document.getElementById('wp-block-library-inline-css'),'stale episode-only WordPress CSS was not removed');
        assert(!!document.getElementById('elementor-post-1') && !document.getElementById('elementor-post-2'),'home page-specific Elementor CSS was not reconciled');
        assert(!!document.querySelector('link[href$="/home.css"]') && !document.querySelector('link[href$="/episode.css"]'),'home stylesheet set was not reconciled');
        assert(window.__dynamicLoads.join(',')==='e-sticky-js,jet-plugins-js,jet-engine-data-stores-js,jet-engine-frontend-js','allowlisted dynamic scripts did not load sequentially');
        assert(document.querySelectorAll('style:not([id])').length===1,'identical anonymous style was duplicated or removed');
        assert(document.querySelector('[data-smp-audio]')===playerAudio && !playerAudio.paused && playerAudio.currentTime>divergentBefore,'audio was interrupted across divergent surfaces');
        assert(counters.popstateBindings===1 && counters.scrollBindings===1 && counters.pushes===2,'divergent navigation duplicated or skipped history ownership');
        assert(window.__elementorReadyCalls===2 && window.elementorFrontendConfig.post.id===1,'home Elementor lifecycle/config was not updated');
        pass('PASS home and episode-like Elementor CSS/assets reconcile in both directions');
        return;
    }

    if(mode==='inactive-history' || mode==='foreign-history'){
        var historyReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'history test navigation was not intercepted');
        await historyReady;
        assert(counters.popstateBindings===1 && counters.scrollBindings===1 && history.scrollRestoration==='manual','history session was not activated');
        if(mode==='inactive-history'){
            var replacementsBeforePark=counters.replaces;
            playerAudio.pause();
            assert(counters.scrollRemovals===1,'parking did not remove the scroll listener');
            assert(history.scrollRestoration!=='manual','parking did not restore native scroll restoration');
            window.dispatchEvent(new Event('scroll'));
            await delay(180);
            assert(counters.replaces===replacementsBeforePark,'parked scroll still replaced history state');
            assert(history.state && history.state.smpPodcastAjax,'successful session state lost its ownership marker');
            sessionStorage.setItem('smp-history-proof','pass');
            window.dispatchEvent(new PopStateEvent('popstate',{state:history.state}));
            return;
        }
        assert(!playerAudio.paused,'foreign history test unexpectedly paused playback');
        history.replaceState({foreign:true},'',window.location.href);
        sessionStorage.setItem('smp-history-proof','pass');
        window.dispatchEvent(new PopStateEvent('popstate',{state:{foreign:true}}));
        return;
    }

    if(mode==='active' || mode==='elementor-ready'){
        var firstReady=waitFor('smp:after-navigate');
        assert(observedClick(document.getElementById('navigate'))===true,'active navigation was not intercepted');
        await firstReady;
        var expectedHeading=mode==='elementor-ready' ? 'Elementor target' : 'Next page';
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent===expectedHeading,'content root was not swapped');
        assert(document.title===expectedHeading,'document title was not synchronized');
        assert(document.documentElement.lang===(mode==='elementor-ready' ? 'en' : 'en-GB'),'document language was not synchronized');
        assert(document.querySelector('meta[name="description"]').content===expectedHeading+' description','description was not synchronized');
        assert(!document.querySelector('meta[name="robots"]'),'missing target robots tag left stale metadata');
        assert(new URL(document.querySelector('link[rel="canonical"]').href).pathname===(mode==='elementor-ready' ? '/elementor-ready' : '/next'),'canonical was not synchronized');
        if(mode==='active')assert(!!document.querySelector('link[rel~="stylesheet"][href$="/target.css"]'),'missing same-origin target stylesheet was not loaded');
        assert(counters.pushes===1 && counters.replaces>=1,'successful navigation did not own history once');
        assert(counters.popstateBindings===1 && counters.scrollBindings===1,'history listeners were not lazily installed');
        assert(history.scrollRestoration==='manual','scroll restoration was not enabled for the active session');
        assert(document.querySelectorAll('audio').length===1 && document.querySelectorAll('#ap-audio').length===0,'audio singleton was not preserved');

        if(mode==='elementor-ready'){
            assert(window.__elementorReadyCalls===1,'Elementor ready lifecycle did not run exactly once');
            assert(window.elementorFrontendConfig.post.id===2 && window.elementorFrontend.config.post.id===2,'validated Elementor config was not synchronized');
            assert(window.ElementorProFrontendConfig.version==='test-target' && window.elementorProFrontend.config.version==='test-target','validated Elementor Pro config was not synchronized');
            assert(window.JetEngineSettings.post_id==='2' && window.JetEngineSettings.queried_object_class==='WP_Post','validated JetEngine JSON config was not synchronized');
            pass('PASS validated Elementor config and lifecycle behavior');
            return;
        }

        var popState={smpPodcastAjax:true,smpScrollX:0,smpScrollY:0};
        history.replaceState(popState,'','/back');
        var popReady=waitFor('smp:after-navigate');
        window.dispatchEvent(new PopStateEvent('popstate',{state:popState}));
        await popReady;
        assert(document.querySelector('[data-smp-ajax-root] h1').textContent==='Back page','popstate content was not restored');
        assert(counters.fetches===2 && counters.pushes===1,'popstate used the wrong navigation mode');
        assert(!document.querySelector('link[rel="canonical"]') && !document.querySelector('meta[name="description"]') && !document.querySelector('meta[property^="og:"]'),'missing target SEO tags left stale metadata');
        assert(!document.head.querySelector('script[type="application/ld+json"]'),'missing target schema left stale JSON-LD');
        assert(!document.querySelector('link[rel~="stylesheet"][href$="/target.css"]'),'stale target-only stylesheet was not removed');
        pass('PASS active playback, history, head sync, and singleton behavior');
        return;
    }

    click(document.getElementById('navigate'));
}catch(error){fail(error && error.message ? error.message : String(error));}},40);},{once:true});
})();`;
}

function playerMarkup() {
    return `<aside id="smp-podcast-player" data-smp-player hidden>
<audio data-smp-audio></audio>
<div data-smp-stage><img data-smp-cover hidden><div data-smp-video-shell hidden><iframe data-smp-video></iframe></div></div>
<span data-smp-kind></span><div data-smp-modes><button type="button" data-smp-mode-button="audio">Audio</button><button type="button" data-smp-mode-button="video">Video</button></div>
<button type="button" data-smp-toggle><span data-smp-play-icon>play</span><span data-smp-pause-icon hidden>pause</span></button>
<button type="button" data-smp-back>back</button><button type="button" data-smp-forward>forward</button>
<input data-smp-seek type="range" min="0" max="1" value="0"><span data-smp-elapsed></span><span data-smp-duration></span>
<a data-smp-title></a><a data-smp-download></a>
<select data-smp-rate><option value="1">1</option></select><input data-smp-volume type="range" min="0" max="1" value="1">
<button type="button" data-smp-mute></button><button type="button" data-smp-close></button><span data-smp-status></span>
</aside>`;
}

async function runChrome(url) {
    const candidates = [
        process.env.CHROME_BIN,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Google Chrome Beta.app/Contents/MacOS/Google Chrome Beta',
        '/Applications/Google Chrome Dev.app/Contents/MacOS/Google Chrome Dev',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ].filter(Boolean);
    const executable = candidates.find(existsSync);
    if (!executable) return { code: 127, stdout: '', stderr: 'Chrome/Chromium executable not found' };

    const profile = mkdtempSync(join(root, '.tmp-smp-player-browser-'));
    const args = [
        '--headless=new',
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--disable-background-networking',
        '--disable-component-update',
        '--disable-default-apps',
        '--disable-extensions',
        '--disable-sync',
        '--metrics-recording-only',
        '--mute-audio',
        '--no-first-run',
        '--no-default-browser-check',
        `--user-data-dir=${profile}`,
        '--virtual-time-budget=3500',
        '--dump-dom',
        url,
    ];

    try {
        return await new Promise((resolve) => {
            const child = spawn(executable, args, { stdio: ['ignore', 'pipe', 'pipe'] });
            let stdout = '';
            let stderr = '';
            child.stdout.on('data', (chunk) => { stdout += chunk; });
            child.stderr.on('data', (chunk) => { stderr += chunk; });
            const timeout = setTimeout(() => child.kill('SIGKILL'), 12000);
            child.on('close', (code) => {
                clearTimeout(timeout);
                resolve({ code: code ?? 1, stdout, stderr });
            });
        });
    } finally {
        rmSync(profile, { recursive: true, force: true });
    }
}

async function optionalPuppeteerBrowser() {
    const packagePath = process.env.SMP_PUPPETEER_PATH;
    if (!packagePath) return null;
    try {
        const require = createRequire(import.meta.url);
        const puppeteer = require(packagePath);
        return await puppeteer.launch({
            executablePath: chromeExecutable(),
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--disable-background-networking',
                '--disable-component-update',
                '--disable-sync',
                '--metrics-recording-only',
                '--mute-audio',
            ],
        });
    } catch (error) {
        process.stderr.write(`Unable to start Puppeteer: ${error && error.message ? error.message : String(error)}\n`);
        return null;
    }
}

async function runPuppeteer(activeBrowser, url) {
    const page = await activeBrowser.newPage();
    try {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 8000 });
        await page.waitForFunction(() => document.body && document.body.dataset.testStatus, { timeout: 6000 });
        return { code: 0, stdout: await page.content(), stderr: '' };
    } catch (error) {
        return { code: 1, stdout: await page.content(), stderr: error && error.stack ? error.stack : String(error) };
    } finally {
        await page.close();
    }
}

function chromeExecutable() {
    const candidates = [
        process.env.CHROME_BIN,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Google Chrome Beta.app/Contents/MacOS/Google Chrome Beta',
        '/Applications/Google Chrome Dev.app/Contents/MacOS/Google Chrome Dev',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ].filter(Boolean);
    const executable = candidates.find(existsSync);
    if (!executable) throw new Error('Chrome/Chromium executable not found');
    return executable;
}

function extractResult(html) {
    const match = html.match(/<pre id="test-result">([^<]*)<\/pre>/);
    return match ? match[1] : '';
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
}

function escapeJs(value) {
    return String(value).replace(/[\\']/g, '\\$&');
}
