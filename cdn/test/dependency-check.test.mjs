// Regression tests for the CDN bundle dependency check.
//
// Guards the 2026-06-04/06-08 prod outage class: the CDN frontend bundle is
// concatenated from a hardcoded module list + shims, and a missing module or a
// missing shim only fails at runtime ("Unknown module") on CDN-mode installs
// (prod) — invisible to local/dev which use Moodle AMD. These tests assert the
// build-time guard catches BOTH the missing-module and the missing-shim cases.
//
// Run: npm test  (from cdn/) — uses node's built-in test runner.

import test from 'node:test';
import assert from 'node:assert/strict';
import {
    assertDependenciesResolvable,
    buildBundle,
    CDN_SHIMS,
} from '../rollup.config.mjs';

test('the real CDN bundle config passes the dependency check', () => {
    // buildBundle() runs assertDependenciesResolvable() on the real modules.
    assert.doesNotThrow(() => buildBundle());
});

test('catches a bundled module depending on an unbundled module', () => {
    // The exact 06-04 shape: chat depends on path_map, but path_map isn't bundled.
    const sources = [{
        name: 'chat',
        code: "define(['local_ai_course_assistant/path_map'], function(p){ return {}; });",
    }];
    assert.throws(
        () => assertDependenciesResolvable(sources, ['chat']),
        /dependency check FAILED[\s\S]*path_map/,
    );
});

test('catches a bundled module depending on an unprovided shim (missing-shim case)', () => {
    // core/popup is neither a bundled module nor a registered shim.
    const sources = [{
        name: 'ui',
        code: "define(['core/str', 'core/popup'], function(s, p){ return {}; });",
    }];
    assert.throws(
        () => assertDependenciesResolvable(sources, ['ui']),
        /dependency check FAILED[\s\S]*core\/popup/,
    );
});

test('a real shim dependency (core/notification) is accepted', () => {
    const sources = [{
        name: 'path_map',
        code: "define(['core/str', 'core/notification', 'local_ai_course_assistant/repository'], function(){ return {}; });",
    }];
    assert.doesNotThrow(
        () => assertDependenciesResolvable(sources, ['path_map', 'repository']),
    );
});

test('every shim the check trusts is actually registered in the bundle', () => {
    // The deeper missing-shim hole: CDN_SHIMS could list a core/* name that
    // buildBundle() never wires into _resolved. The check would then "resolve"
    // a dependency that throws Unknown module at runtime. Assert no drift.
    const src = buildBundle();
    for (const shim of CDN_SHIMS) {
        assert.ok(
            src.includes(`_resolved['${shim}']`),
            `shim "${shim}" is trusted by the dependency check but is not registered in buildBundle()`,
        );
    }
});
