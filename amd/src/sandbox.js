// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Python sandbox page controller.
 *
 * Replaces the three inline <script> blocks sandbox.php used to echo
 * (CONTRIB-10574 #273). What changed with the move:
 *
 * 1. Strings come from core/str. The page used to build JavaScript string
 *    literals from get_string() in PHP, and v7.6.1 had to fix a French
 *    apostrophe that closed one of those literals and killed every script on
 *    the page. With no PHP value interpolated into JavaScript at all, that
 *    class of bug cannot recur.
 * 2. The status colours are CSS modifier classes rather than three colour
 *    values written onto the element by hand.
 * 3. The Pyodide loader is injected here and its onload/onerror events decide
 *    readiness, replacing a 15-second poll for window.loadPyodide. A wrong
 *    runtime URL now reports at once instead of after the poll gives up.
 *
 * The loader itself stays a dynamically injected <script>, not an AMD
 * dependency: it comes from an admin-configured location outside Moodle's
 * module loader, the same documented exemption as the optional CDN bundle in
 * templates/chat_widget.mustache.
 *
 * @module     local_ai_course_assistant/sandbox
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str'], function(Str) {

    var HIDDEN = 'sola-sandbox-hidden';

    /**
     * Set the status box's text and state.
     *
     * @param {HTMLElement} status
     * @param {string} text
     * @param {string} state '' for neutral, 'ready' or 'error'
     */
    function setStatus(status, text, state) {
        status.textContent = text;
        status.classList.remove('sola-sandbox-status--ready', 'sola-sandbox-status--error');
        if (state) {
            status.classList.add('sola-sandbox-status--' + state);
        }
    }

    return {
        /**
         * Wire up the sandbox on the page rendered by templates/sandbox.mustache.
         */
        init: function() {
            var root = document.querySelector('[data-region="sola-sandbox"]');
            if (!root) {
                return;
            }
            var base = root.dataset.pyodideBase;
            var loaderUrl = root.dataset.pyodideLoader;
            var status = document.getElementById('aica-sandbox-status');
            var runBtn = document.getElementById('aica-sandbox-run');
            var clearBtn = document.getElementById('aica-sandbox-clear');
            var runningSpan = document.getElementById('aica-sandbox-running');
            var stdout = document.getElementById('aica-sandbox-stdout');
            var stderr = document.getElementById('aica-sandbox-stderr');
            var codeArea = document.getElementById('aica-sandbox-code');
            var pyodide = null;
            var strings = {ready: '', error: ''};

            var stringsLoaded = Str.get_strings([
                {key: 'sandbox:ready', component: 'local_ai_course_assistant'},
                {key: 'sandbox:load_error', component: 'local_ai_course_assistant'},
            ]).then(function(s) {
                strings.ready = s[0];
                strings.error = s[1];
                return s;
            });

            /**
             * Report a load failure once the strings are available.
             */
            function failed() {
                stringsLoaded.then(function() {
                    setStatus(status, strings.error, 'error');
                    return null;
                }).catch(function() {
                    setStatus(status, 'Python runtime failed to load.', 'error');
                });
            }

            /**
             * Start Pyodide and capture Python's stdout and stderr.
             */
            async function start() {
                if (pyodide) {
                    return;
                }
                if (typeof window.loadPyodide !== 'function') {
                    // The URL answered but did not serve the Pyodide loader.
                    failed();
                    return;
                }
                try {
                    pyodide = await window.loadPyodide({indexURL: base});
                    pyodide.runPython(
                        'import sys, io\n' +
                        '_aica_out = io.StringIO()\n' +
                        '_aica_err = io.StringIO()\n' +
                        'sys.stdout = _aica_out\n' +
                        'sys.stderr = _aica_err\n'
                    );
                    await stringsLoaded;
                    setStatus(status, strings.ready, 'ready');
                    runBtn.disabled = false;
                } catch (e) {
                    pyodide = null;
                    failed();
                }
            }

            /**
             * Read one of the captured Python streams.
             *
             * @param {string} name
             * @return {string}
             */
            function readBuffer(name) {
                return pyodide.runPython(name + '.getvalue()');
            }

            /**
             * Show or clear the stderr pane.
             *
             * @param {string} text
             */
            function showStderr(text) {
                stderr.textContent = text;
                stderr.classList.toggle(HIDDEN, !text);
            }

            runBtn.addEventListener('click', async function() {
                if (!pyodide) {
                    return;
                }
                runBtn.disabled = true;
                runningSpan.classList.remove(HIDDEN);
                stdout.textContent = '';
                showStderr('');
                try {
                    pyodide.runPython(
                        '_aica_out.seek(0); _aica_out.truncate(0)\n' +
                        '_aica_err.seek(0); _aica_err.truncate(0)\n'
                    );
                    await pyodide.runPythonAsync(codeArea.value);
                    stdout.textContent = readBuffer('_aica_out');
                    showStderr(readBuffer('_aica_err'));
                } catch (err) {
                    stdout.textContent = readBuffer('_aica_out');
                    // The traceback is in the captured stderr buffer, not on the
                    // error. Because sys.stderr is redirected to _aica_err above,
                    // Pyodide writes the formatted traceback there and leaves
                    // err.message EMPTY, so the old fallback to String(err) showed
                    // the learner the bare word "PythonError" and never their
                    // actual error. That predates the #273 move (the inline script
                    // had the same line); it was found by running this module in a
                    // browser. Prefer the buffer, fall back to the message.
                    var traceback = readBuffer('_aica_err');
                    showStderr(traceback || ((err && err.message) ? err.message : String(err)));
                } finally {
                    runBtn.disabled = false;
                    runningSpan.classList.add(HIDDEN);
                }
            });

            clearBtn.addEventListener('click', function() {
                stdout.textContent = '';
                showStderr('');
            });

            // Tab inserts four spaces instead of leaving the textarea.
            codeArea.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var s = codeArea.selectionStart;
                    var end = codeArea.selectionEnd;
                    codeArea.value = codeArea.value.substring(0, s) + '    ' + codeArea.value.substring(end);
                    codeArea.selectionStart = codeArea.selectionEnd = s + 4;
                }
            });

            if (typeof window.loadPyodide === 'function') {
                start();
                return;
            }
            var script = document.createElement('script');
            script.src = loaderUrl;
            script.onload = start;
            script.onerror = failed;
            document.head.appendChild(script);
        }
    };
});
