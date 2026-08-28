// Hide the AMD loader from the Chart.js UMD bundle that loads next.
//
// chart.umd.min.js prefers AMD when it sees define.amd. Moodle always has
// RequireJS on the page, so the bundle registered an anonymous module instead
// of installing window.Chart. RequireJS then rejected it -- "Mismatched
// anonymous define()" -- because nothing had require()d it. The library never
// loaded, and analytics_dashboard's `typeof Chart === 'undefined'` guard turned
// that into three silently empty canvases that read as missing data.
//
// Restored immediately afterwards by amd-guard-after.js. Classic scripts run in
// document order, so the window is exactly the Chart.js load.
(function() {
    "use strict";
    window._solaSavedDefine = window.define;
    window.define = undefined;
})();
