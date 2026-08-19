import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import { readFileSync } from 'fs';
import terser from '@rollup/plugin-terser';
import postcss from 'rollup-plugin-postcss';

const __dirname = dirname(fileURLToPath(import.meta.url));
const amdSrc = resolve(__dirname, '..', 'amd', 'src');

/**
 * The core/* modules the CDN bundle provides via shims. The dependency check
 * (assertDependenciesResolvable) treats these as resolvable. MUST stay in sync
 * with the shims actually registered into _resolved in buildBundle() — the
 * dependency-check regression test asserts that, so a name listed here but not
 * wired (a "missing shim" the check would wrongly trust) fails the test.
 */
export const CDN_SHIMS = ['core/ajax', 'core/str', 'core/config', 'core/notification'];

/**
 * Build a single concatenated bundle that includes a mini AMD loader,
 * all shim modules, and all plugin AMD modules.
 *
 * This avoids trying to convert AMD→ESM (fragile with regex on large files).
 * Instead, we provide a real `define()` function that the AMD modules call,
 * then resolve the dependency graph and execute them.
 */
export default {
    input: resolve(__dirname, 'entry-bundle.js'),
    output: {
        file: resolve(__dirname, 'dist', 'sola.min.js'),
        format: 'iife',
        name: 'SOLA',
        sourcemap: true,
    },
    plugins: [
        // Inline all AMD source files into the bundle entry.
        {
            name: 'bundle-amd',
            load(id) {
                if (id === resolve(__dirname, 'entry-bundle.js')) {
                    return buildBundle();
                }
                return null;
            },
        },

        // Bundle and minify CSS.
        postcss({
            extract: 'sola.min.css',
            minimize: true,
        }),

        // Minify JS.
        terser({
            format: { comments: false },
        }),
    ],
};

export function buildBundle() {
    // Read shim files.
    const ajaxShim = readFileSync(resolve(__dirname, 'shims', 'ajax.js'), 'utf8');
    const strShim = readFileSync(resolve(__dirname, 'shims', 'str.js'), 'utf8');
    const configShim = readFileSync(resolve(__dirname, 'shims', 'config.js'), 'utf8');
    const notificationShim = readFileSync(resolve(__dirname, 'shims', 'notification.js'), 'utf8');

    // Read all AMD source modules. Dependency order: deps first.
    // IMPORTANT: every module that any other bundled module `define()`s a
    // dependency on MUST appear here, or the mini-AMD loader throws
    // "Unknown module" at runtime and the whole widget fails to init. path_map
    // (v5.9.0) is depended on by chat; omitting it broke the CDN drawer on prod.
    const modules = [
        'markdown', 'audio_player', 'sse_client', 'speech', 'realtime',
        'voice', 'repository', 'quiz', 'i18n_strings', 'ui', 'path_map', 'chat',
    ];

    const amdSources = modules.map(name => {
        const code = readFileSync(resolve(amdSrc, name + '.js'), 'utf8');
        return { name, code };
    });

    // Build-time safeguard against the 2026-06-04 CDN drawer outage: a new
    // module (path_map) was depended on by chat without being added to
    // `modules`, and its core/notification dep had no shim. rollup happily
    // concatenated the sources; the only symptom was a runtime "Unknown module"
    // throw that killed the widget on every CDN-mode install (prod), invisible
    // to local/dev which load modules individually via Moodle AMD.
    //
    // Verify every bundled module's declared AMD dependencies are satisfiable
    // here — either another bundled module or a registered shim — using the
    // same name normalization the runtime loader applies. Any gap fails the
    // build (and therefore cdn-deploy) loudly instead of shipping a dead widget.
    assertDependenciesResolvable(amdSources, modules);

    // Second build-time safeguard, same class of bug one layer up: in CDN mode
    // core/str is a shim that resolves only from the window.SOLA_I18N map PHP
    // injects, and falls back to returning the key itself. A JS string key that
    // nobody added to hook_callbacks::get_js_strings() therefore renders to the
    // learner as a raw string id. Invisible on AMD-mode installs (dev), where
    // the real string API serves any key on demand — so, like the 06-04 module
    // outage, it only ever shows up on prod.
    assertJsStringsPreloaded(amdSources);

    // Build the bundle: mini AMD loader + shims + modules + init.
    let bundle = `
// CDN bundle for SOLA - auto-generated, do not edit.
import '../styles.css';

(function() {
    'use strict';

    // ---- Mini AMD loader ----
    var _modules = {};    // name → {deps, factory}
    var _resolved = {};   // name → final exports
    var _resolving = {};  // name → placeholder exports for cyclic deps

    function define(deps, factory) {
        // Called by each AMD module. We capture via _currentModule.
        if (typeof deps === 'function') {
            factory = deps;
            deps = [];
        }
        _modules[_currentModule] = {deps: deps, factory: factory};
    }
    define.amd = {};

    var _currentModule = '';

    function _resolve(name) {
        if (_resolved[name] !== undefined) {
            return _resolved[name];
        }
        if (_resolving[name]) {
            return _resolving[name];
        }
        var mod = _modules[name];
        if (!mod) {
            throw new Error('SOLA CDN: Unknown module "' + name + '"');
        }
        // Mirror AMD cycle handling closely enough for SOLA's modules:
        // expose a placeholder object while the factory is still resolving.
        // Dependent modules keep the same object reference, which we hydrate
        // with the real exports once the factory completes.
        var placeholder = {};
        _resolving[name] = placeholder;
        // Resolve dependencies first.
        var resolvedDeps = mod.deps.map(function(dep) {
            // Normalize module names.
            var normalized = dep.replace('local_ai_course_assistant/', '');
            if (dep === 'core/ajax') normalized = 'core/ajax';
            if (dep === 'core/str') normalized = 'core/str';
            return _resolve(normalized);
        });
        var exports = mod.factory.apply(null, resolvedDeps);
        delete _resolving[name];
        if (exports && typeof exports === 'object') {
            Object.assign(placeholder, exports);
            _resolved[name] = placeholder;
            return placeholder;
        }
        _resolved[name] = exports === undefined ? placeholder : exports;
        return _resolved[name];
    }

    // ---- Register shim modules ----

    // core/ajax shim
    _resolved['core/ajax'] = (function() {
        ${ajaxShim.replace(/export\s+default\s+.*/, '').replace(/export\s*\{[^}]*\}/, '')}
        return {call: call};
    })();

    // core/str shim
    _resolved['core/str'] = (function() {
        ${strShim.replace(/export\s+default\s+.*/, '').replace(/export\s*\{[^}]*\}/, '')}
        return {get_string: get_string, get_strings: get_strings};
    })();

    // core/config shim — exposes M.cfg.sesskey + M.cfg.wwwroot under the
    // same default-export shape as Moodle's real core/config module.
    _resolved['core/config'] = (function() {
        ${configShim.replace(/export\s+default\s+.*/, '').replace(/export\s*\{[^}]*\}/, '')}
        return cfg;
    })();

    // core/notification shim — bundled modules (e.g. path_map) use only
    // Notification.exception(); surface errors to the console in CDN mode.
    _resolved['core/notification'] = (function() {
        ${notificationShim.replace(/export\s+default\s+.*/, '').replace(/export\s*\{[^}]*\}/, '')}
        return {exception: exception};
    })();

    // ---- Register AMD modules ----
`;

    for (const { name, code } of amdSources) {
        bundle += `
    _currentModule = '${name}';
    ${code}
`;
    }

    bundle += `
    // ---- Initialize ----
    var Chat = _resolve('chat');
    if (typeof Chat.init === 'function') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { Chat.init(); });
        } else {
            Chat.init();
        }
    }

})();
`;

    return bundle;
}

/**
 * Fail the build if any bundled module declares an AMD dependency the CDN
 * bundle cannot satisfy. Mirrors the runtime mini-AMD loader: a dependency
 * resolves only if it is another bundled module or a registered shim.
 *
 * Guard for the 2026-06-04 outage class — a new module or a new core/*
 * dependency slipping in without being bundled/shimmed otherwise ships silently
 * and only throws "Unknown module" in the browser on CDN-mode installs (prod).
 *
 * @param {{name: string, code: string}[]} amdSources Bundled module sources.
 * @param {string[]} modules Bundled module short names.
 */
export function assertDependenciesResolvable(amdSources, modules) {
    const available = new Set([...modules, ...CDN_SHIMS]);

    const errors = [];
    for (const { name, code } of amdSources) {
        // Dependency array of the module's first define([...], ...) call.
        const match = code.match(/define\(\s*\[([\s\S]*?)\]/);
        if (!match) {
            continue; // define(function(){...}) — no dependencies.
        }
        const deps = match[1]
            .split(',')
            .map(d => d.trim().replace(/^['"]|['"]$/g, ''))
            .filter(Boolean);
        for (const dep of deps) {
            // Same normalization the runtime loader's _resolve() applies.
            const normalized = dep.replace('local_ai_course_assistant/', '');
            if (!available.has(normalized)) {
                errors.push(`  "${name}" depends on "${dep}" — not a bundled module or a registered shim`);
            }
        }
    }

    if (errors.length) {
        throw new Error(
            '\nSOLA CDN bundle dependency check FAILED. These would throw '
            + '"Unknown module" at runtime and break the widget on every '
            + 'CDN-mode install:\n' + errors.join('\n')
            + '\n\nFix: add the module to `modules` in cdn/rollup.config.mjs, '
            + 'or add a shim in cdn/shims/ and register it in buildBundle().\n'
        );
    }
}

/**
 * Extract the string keys declared in hook_callbacks::get_js_strings().
 *
 * Kept as a parse of the PHP rather than a duplicated list, because a second
 * copy of the keys is exactly the thing that drifts.
 *
 * @param {string} php Source of classes/hook_callbacks.php.
 * @return {Set<string>} Declared keys.
 */
export function parseDeclaredJsStrings(php) {
    const match = php.match(
        /private static function get_js_strings\(\)[\s\S]*?\$keys\s*=\s*\[([\s\S]*?)\n\s*\];/
    );
    if (!match) {
        throw new Error(
            'SOLA CDN: could not find get_js_strings() in classes/hook_callbacks.php. '
            + 'If it was renamed or restructured, update parseDeclaredJsStrings() — do '
            + 'not delete this check, it is the only thing standing between a missing '
            + 'key and a raw string id on a learner\'s screen.'
        );
    }
    // Strip // comments before extracting quoted keys. An apostrophe inside a
    // comment ("the server's scope") otherwise opens a bogus quoted run and
    // injects a garbage key, which is noise at best and misleading at worst.
    const body = match[1].replace(/\/\/[^\n]*/g, '');

    return new Set([...body.matchAll(/'([^']+)'/g)].map(m => m[1]));
}

/**
 * Collect the literal string keys each bundled module asks core/str for.
 *
 * Only literals are collected. Keys assembled at runtime are invisible here by
 * construction, which is why get_js_strings() carries a hand-maintained
 * "dynamic" group.
 *
 * @param {{name: string, code: string}[]} amdSources Bundled module sources.
 * @return {Map<string, string[]>} key → modules requesting it.
 */
export function collectUsedJsStrings(amdSources) {
    const used = new Map();
    const add = (key, name) => {
        if (!used.has(key)) {
            used.set(key, []);
        }
        if (!used.get(key).includes(name)) {
            used.get(key).push(name);
        }
    };

    for (const { name, code } of amdSources) {
        for (const m of code.matchAll(/\bget_string\(\s*'([^']+)'/g)) {
            add(m[1], name);
        }
        for (const m of code.matchAll(/\bgetString\(\s*'([^']+)'/g)) {
            add(m[1], name);
        }
        // get_strings([{key: 'x', ...}, ...])
        for (const blob of code.matchAll(/get_strings\(\s*\[([\s\S]*?)\]\s*\)/g)) {
            for (const m of blob[1].matchAll(/key\s*:\s*'([^']+)'/g)) {
                add(m[1], name);
            }
        }
    }
    return used;
}

/**
 * Fail the build if a bundled module requests a string the CDN map will not
 * carry. See the call site in buildBundle() for why this matters.
 *
 * @param {{name: string, code: string}[]} amdSources Bundled module sources.
 * @param {string=} phpSource Override for the PHP source (tests).
 */
export function assertJsStringsPreloaded(amdSources, phpSource) {
    const php = phpSource !== undefined
        ? phpSource
        : readFileSync(resolve(__dirname, '..', 'classes', 'hook_callbacks.php'), 'utf8');

    const declared = parseDeclaredJsStrings(php);
    const used = collectUsedJsStrings(amdSources);

    const missing = [...used.entries()]
        .filter(([key]) => !declared.has(key))
        .map(([key, mods]) => `  "${key}" — requested by ${mods.join(', ')}`);

    if (missing.length) {
        throw new Error(
            '\nSOLA CDN i18n preload check FAILED. In CDN mode these keys resolve '
            + 'to themselves, so learners would see a raw string id instead of text:\n'
            + missing.sort().join('\n')
            + '\n\nFix: add each key to get_js_strings() in classes/hook_callbacks.php.\n'
        );
    }
}
