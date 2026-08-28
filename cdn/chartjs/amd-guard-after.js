// Give the AMD loader back, and say so loudly if Chart.js still did not load.
//
// Pairs with amd-guard-before.js. The failure this replaces was silent, so the
// replacement is not: an operator looking at an empty dashboard should find the
// reason in the console rather than concluding they have no data.
(function() {
    "use strict";
    window.define = window._solaSavedDefine;
    try {
        delete window._solaSavedDefine;
    } catch (e) {
        window._solaSavedDefine = undefined;
    }
    if (typeof window.Chart === "undefined") {
        window.console.error(
            "SOLA: Chart.js did not install window.Chart, so the analytics charts " +
            "will be empty. The dashboard numbers above them are unaffected."
        );
    }
})();
