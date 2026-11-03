<?php
// Dashboard settings. Override with environment variables, e.g. in Apache:
//   SetEnv CSMS_DASHBOARD_SHOW_PENDING_CHARTS 0
// dashboard.show_pending_charts: when true (default), charts whose backend data
// does not exist yet are shown as faint 'unavailable' outlines with the reason;
// when false they are hidden entirely.

return (static function (): array {
    $env = getenv('CSMS_DASHBOARD_SHOW_PENDING_CHARTS');
    return [
        'dashboard.show_pending_charts' => $env === false || $env === ''
            ? true
            : filter_var($env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
    ];
})();
