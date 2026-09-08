// Regression tests for the CDN i18n preload check.
//
// Guards the class of bug that put a literal 'active_learners:line_global' in
// front of learners on learn.saylor.org: in CDN mode core/str is a shim that
// resolves only from the window.SOLA_I18N map PHP injects, and falls back to
// returning the key itself. A JS string key nobody added to
// hook_callbacks::get_js_strings() therefore renders as a raw string id.
//
// Like the module dependency check next door, this is invisible on AMD-mode
// installs (local/dev), where Moodle's real string API serves any key on
// demand — so it only ever surfaces on CDN-mode installs, i.e. production.
//
// Run: npm test  (from cdn/) — uses node's built-in test runner.

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import {
    assertJsStringsPreloaded,
    buildBundle,
    collectUsedJsStrings,
    parseDeclaredJsStrings,
} from '../rollup.config.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const hookCallbacks = readFileSync(
    resolve(__dirname, '..', '..', 'classes', 'hook_callbacks.php'), 'utf8'
);

test('the real bundle passes the i18n preload check', () => {
    // buildBundle() runs assertJsStringsPreloaded() on the real modules.
    assert.doesNotThrow(() => buildBundle());
});

test('catches a bundled module requesting an undeclared string key', () => {
    const sources = [{
        name: 'chat',
        code: "Str.get_string('totally:undeclared', 'local_ai_course_assistant');",
    }];
    assert.throws(
        () => assertJsStringsPreloaded(sources, hookCallbacks),
        /totally:undeclared/,
        'a key absent from get_js_strings() must fail the build'
    );
});

test('the failure message names the module, so the fix is obvious', () => {
    const sources = [{ name: 'quiz', code: "getString('another:missing');" }];
    assert.throws(
        () => assertJsStringsPreloaded(sources, hookCallbacks),
        /requested by quiz/
    );
});

test('detects keys passed via the get_strings([{key: ...}]) form', () => {
    const used = collectUsedJsStrings([{
        name: 'ui',
        code: "Str.get_strings([{key: 'batch:one', component: 'x'}, {key: 'batch:two', component: 'x'}]);",
    }]);
    assert.ok(used.has('batch:one'));
    assert.ok(used.has('batch:two'));
});

test('a declared key does not trip the check', () => {
    const sources = [{ name: 'chat', code: "Str.get_string('chat:error', 'local_ai_course_assistant');" }];
    assert.doesNotThrow(() => assertJsStringsPreloaded(sources, hookCallbacks));
});

test('parsing fails loudly if get_js_strings() is renamed or restructured', () => {
    // A check that silently passes when it can no longer find what it is
    // checking is worse than no check at all — it reads as coverage.
    assert.throws(
        () => parseDeclaredJsStrings('<?php class foo { function bar() { return []; } }'),
        /could not find get_js_strings/
    );
});

test('the keys behind the learn.saylor.org leak are declared', () => {
    // Both are assembled at runtime from the server-reported scope, so the
    // static check above cannot see them: they are only ever protected by
    // being listed by hand. Pin them.
    const declared = parseDeclaredJsStrings(hookCallbacks);
    assert.ok(declared.has('active_learners:line_global'));
    assert.ok(declared.has('active_learners:line'));
});

test('the other runtime-assembled keys are declared', () => {
    const declared = parseDeclaredJsStrings(hookCallbacks);
    assert.ok(declared.has('learner_digest:optin_thanks'));
    assert.ok(declared.has('learner_digest:optin_declined'));
});

test('an apostrophe in a comment does not become a bogus key', () => {
    // The key list is commented, and prose contains apostrophes. A naive
    // quote scan turns "the server's scope" into a spurious declared key.
    const php = `<?php
        private static function get_js_strings(): array {
            $keys = [
                // Picked from the server's reported scope.
                'real:key',
            ];
        }`;
    const declared = parseDeclaredJsStrings(php);
    assert.deepEqual([...declared], ['real:key']);
});
