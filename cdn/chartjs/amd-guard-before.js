// Hide the AMD *marker* from the Chart.js UMD bundle that loads next.
//
// chart.umd.min.js prefers AMD when it sees define.amd. Moodle always has
// RequireJS on the page, so the bundle registered an anonymous module instead
// of installing window.Chart. RequireJS then rejected it -- "Mismatched
// anonymous define()" -- because nothing had require()d it. The library never
// loaded, and analytics_dashboard's `typeof Chart === 'undefined'` guard turned
// that into three silently empty canvases that read as missing data.
//
// Only define.amd is cleared, not define itself. UMD tests
// `typeof define === 'function' && define.amd`, so clearing the marker is
// enough to close the AMD path -- and it leaves define callable. RequireJS
// fetches modules as async script tags, and an already-downloaded module can
// execute between two classic scripts; if its define() landed while define was
// undefined it would throw and that module would silently fail to load.
//
// Restored immediately afterwards by amd-guard-after.js.
(function() {
    "use strict";
    window._solaSavedAmd = (typeof window.define === "function") ? window.define.amd : undefined;
    if (typeof window.define === "function") {
        window.define.amd = undefined;
    }
})();
