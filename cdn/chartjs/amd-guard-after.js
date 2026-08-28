// Put the AMD marker back, and say so loudly if Chart.js still did not load.
//
// Pairs with amd-guard-before.js. The failure this replaces was silent, so the
// replacement is not: an operator looking at an empty dashboard should find the
// reason in the console rather than concluding they have no data.
(function() {
    "use strict";
    if (typeof window.define === "function") {
        window.define.amd = window._solaSavedAmd;
    }
    try {
        delete window._solaSavedAmd;
    } catch (e) {
        window._solaSavedAmd = undefined;
    }
    if (typeof window.Chart === "undefined") {
        window.console.error(
            "SOLA: Chart.js did not install window.Chart, so the analytics charts " +
            "will be empty. The dashboard numbers above them are unaffected."
        );
    }
})();
