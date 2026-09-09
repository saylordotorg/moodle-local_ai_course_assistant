<?php
/**
 * Generate amd/src/i18n_help.js from the plugin's own lang files.
 *
 * The widget's language switch is client-side and cannot ask the server for a
 * string in another language: Moodle's PARAM_LANG rejects any language whose
 * pack is not installed, and Saylor's sites install only en/en_us. The plugin
 * ships 46 lang files of its own, so the translations exist -- they just have
 * to travel in the bundle. This generates that table rather than hand-writing
 * 855 strings, so it stays in step with lang/ automatically.
 *
 * Lazily required by chat.js when the help panel first opens, so it costs
 * nothing on a page load that never opens Help.
 *
 * Usage: php scripts/build_help_i18n.php
 */
$root = dirname(__DIR__);
$prefix = 'help:';

/** Parse $string['key'] = '...'; without executing the file (they die() on include). */
$parse = function (string $path) use ($prefix): array {
    if (!is_file($path)) {
        return [];
    }
    $src = file_get_contents($path);
    $out = [];
    $re = '/^\$string\[\s*([\'"])(' . preg_quote($prefix, '/') . '[a-z0-9_]+)\1\s*\]\s*=\s*([\'"])(.*?)\3\s*;\s*$/ms';
    if (preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            // Un-escape the PHP string literal for the quote style used.
            $val = $hit[4];
            $val = str_replace(['\\' . $hit[3], '\\\\'], [$hit[3], '\\'], $val);
            $out[$hit[2]] = $val;
        }
    }
    return $out;
};

$en = $parse($root . '/lang/en/local_ai_course_assistant.php');
ksort($en);
if (!$en) {
    fwrite(STDERR, "No {$prefix}* keys found in lang/en - aborting\n");
    exit(1);
}

$langs = [];
foreach (glob($root . '/lang/*/local_ai_course_assistant.php') as $path) {
    $dir = basename(dirname($path));
    if ($dir === 'en') {
        continue;
    }
    // get() truncates the requested language to two characters, so the table
    // must be keyed the same way: zh_cn -> zh, pt_br -> pt. Keying by the
    // directory name would silently strand both -- the generated table had
    // 'zh_cn' while a lookup for 'zh' fell through to English.
    $code = substr($dir, 0, 2);
    $rows = $parse($path);
    // Only ship what differs from English; the resolver falls back to EN.
    $diff = [];
    foreach ($en as $k => $v) {
        if (isset($rows[$k]) && $rows[$k] !== $v) {
            $diff[$k] = $rows[$k];
        }
    }
    if ($diff) {
        ksort($diff);
        if (isset($langs[$code])) {
            fwrite(STDERR, "Two lang dirs map to '{$code}' (second: {$dir}) - keeping the first\n");
            continue;
        }
        $langs[$code] = $diff;
    }
}
ksort($langs);

$j = function ($v) {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$lines = [];
$lines[] = '// This file is part of Moodle - http://moodle.org/';
$lines[] = '//';
$lines[] = '// Moodle is free software: you can redistribute it and/or modify';
$lines[] = '// it under the terms of the GNU General Public License as published by';
$lines[] = '// the Free Software Foundation, either version 3 of the License, or';
$lines[] = '// (at your option) any later version.';
$lines[] = '//';
$lines[] = '// Moodle is distributed in the hope that it will be useful,';
$lines[] = '// but WITHOUT ANY WARRANTY; without even the implied warranty of';
$lines[] = '// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the';
$lines[] = '// GNU General Public License for more details.';
$lines[] = '//';
$lines[] = '// You should have received a copy of the GNU General Public License';
$lines[] = '// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.';
$lines[] = '';
$lines[] = '/**';
$lines[] = ' * Help-panel translations, GENERATED FILE - do not edit by hand.';
$lines[] = ' *';
$lines[] = ' * Regenerate with: php scripts/build_help_i18n.php';
$lines[] = ' *';
$lines[] = ' * The help panel is rendered server-side in the page language, and the';
$lines[] = ' * in-widget language switch never reloads the page, so the panel used to stay';
$lines[] = ' * in the page language while the rest of the UI switched. It cannot be fixed by';
$lines[] = ' * asking the server for the strings either: PARAM_LANG rejects any language';
$lines[] = ' * whose pack is not installed, and Saylor\'s sites install only en and en_us.';
$lines[] = ' * The translations therefore have to travel in the bundle.';
$lines[] = ' *';
$lines[] = ' * This module is required lazily, the first time the panel is opened, so it';
$lines[] = ' * costs nothing on a page load that never opens Help.';
$lines[] = ' *';
$lines[] = ' * @module     local_ai_course_assistant/i18n_help';
$lines[] = ' * @copyright  2026 Saylor Academy';
$lines[] = ' * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later';
$lines[] = ' */';
$lines[] = 'define([], function() {';
$lines[] = '';
$lines[] = '    const EN = ' . $j($en) . ';';
$lines[] = '';
$lines[] = '    const LANGS = ' . $j($langs) . ';';
$lines[] = '';
$lines[] = '    /**';
$lines[] = '     * One help string, falling back to English.';
$lines[] = '     *';
$lines[] = '     * @param {string|null} lang ISO 639-1 code';
$lines[] = '     * @param {string} key Lang key, e.g. help:title';
$lines[] = '     * @return {string} The string, or \'\' when the key is unknown';
$lines[] = '     */';
$lines[] = '    const get = function(lang, key) {';
$lines[] = '        const l = (lang || \'en\').substring(0, 2).toLowerCase();';
$lines[] = '        if (LANGS[l] && LANGS[l][key]) {';
$lines[] = '            return LANGS[l][key];';
$lines[] = '        }';
$lines[] = '        return EN[key] || \'\';';
$lines[] = '    };';
$lines[] = '';
$lines[] = '    return { get: get, keys: Object.keys(EN) };';
$lines[] = '});';

file_put_contents($root . '/amd/src/i18n_help.js', implode("\n", $lines) . "\n");
printf("generated amd/src/i18n_help.js: %d EN keys, %d languages, %d bytes\n",
    count($en), count($langs), filesize($root . '/amd/src/i18n_help.js'));
