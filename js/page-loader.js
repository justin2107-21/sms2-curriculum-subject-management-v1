// ============================================================
//  PAGE-LOADER.JS
//  Shows a full-screen loading overlay on every page load.
//  The overlay disappears once the page is fully ready.
//  Include this script in every page's <head> as the FIRST script.
// ============================================================

(function () {
    // ── Detect the logo path relative to the current page ───
    // We store the logo path in a <meta> tag per page so this
    // script doesn't need to know the folder depth.
    // Falls back to a relative path if the meta isn't present.
    const meta     = document.querySelector('meta[name="loader-logo"]');
    const logoSrc  = meta ? meta.content : '../images/BCP_LOGO.png';

    // ── Build the overlay ────────────────────────────────────
    const loader = document.createElement('div');
    loader.id    = 'page-loader';
    loader.innerHTML = `
        <img class="pl-logo" src="${logoSrc}" alt="BCP Logo"/>
        <p  class="pl-text">Magandang Buhay BCPian!</p>
        <p  class="pl-sub">
            Please wait
            <span class="pl-dots">
                <span></span><span></span><span></span>
            </span>
        </p>`;

    // Insert before anything else so it covers the page immediately
    document.addEventListener('DOMContentLoaded', () => {
        document.body.prepend(loader);
    });

    // ── Hide when fully loaded ───────────────────────────────
    // ↓↓ CHANGE THIS NUMBER to adjust how long the loader stays visible ↓↓
    // Examples: 500 = 0.5s  |  800 = 0.8s  |  1500 = 1.5s  |  2500 = 2.5s
    const MIN_MS  = 1000;
    const started = Date.now();

    window.addEventListener('load', () => {
        const elapsed   = Date.now() - started;
        const remaining = Math.max(0, MIN_MS - elapsed);

        setTimeout(() => {
            loader.classList.add('hide');
            // Remove from DOM after fade completes
            setTimeout(() => loader.remove(), 380);
        }, remaining);
    });
})();
