<?php
// UI profile switch. A page renders EITHER the legacy layout OR the Bootstrap
// layout, never both stylesheets. Default is 'legacy'; no existing page opts in.
//
// Resolution order: page-level $UI_PROFILE, then CSMS_UI_PROFILE constant, then the
// CSMS_UI_PROFILE environment variable. Anything other than 'bootstrap' is legacy.

if (!function_exists('ui_profile')) {
    function ui_profile(): string
    {
        $candidate = $GLOBALS['UI_PROFILE']
            ?? (defined('CSMS_UI_PROFILE') ? CSMS_UI_PROFILE : null)
            ?? (getenv('CSMS_UI_PROFILE') ?: null);
        return $candidate === 'bootstrap' ? 'bootstrap' : 'legacy';
    }
}

if (!function_exists('ui_is_bootstrap')) {
    function ui_is_bootstrap(): bool { return ui_profile() === 'bootstrap'; }
}

if (!function_exists('ui_e')) {
    function ui_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ui_asset')) {
    // Versioned URL for a file under assets/ui/dist (cache-busts on rebuild).
    function ui_asset(string $appRoot, string $relative): string
    {
        $file = __DIR__ . '/../../assets/ui/dist/' . $relative;
        $version = is_file($file) ? (string)filemtime($file) : '1';
        return ui_e($appRoot . 'assets/ui/dist/' . $relative . '?v=' . $version);
    }
}

if (!function_exists('ui_head_assets')) {
    // <link> tags for the Bootstrap profile only. Returns '' for legacy, so pages
    // keep their own stylesheet links untouched. Self-hosted: no CDN or web fonts.
    function ui_head_assets(string $appRoot): string
    {
        if (!ui_is_bootstrap()) return '';
        return '<link rel="stylesheet" href="' . ui_asset($appRoot, 'css/csms-bootstrap.css') . '">' . "\n";
    }
}

if (!function_exists('ui_foot_assets')) {
    function ui_foot_assets(string $appRoot): string
    {
        if (!ui_is_bootstrap()) return '';
        return '<script src="' . ui_asset($appRoot, 'js/bootstrap.bundle.min.js') . '"></script>' . "\n"
            . '<script src="' . ui_asset($appRoot, 'js/csms-ui.js') . '"></script>' . "\n";
    }
}
