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
 * Lightweight markdown to HTML converter.
 *
 * Supports: bold, italic, strikethrough, inline code, code blocks, headings
 * (h1 to h6), lists, links, tables, block quotes and horizontal rules.
 * Every piece of text is HTML-escaped before any markup is added, so the
 * output is a safe HTML subset.
 *
 * @module     local_ai_course_assistant/markdown
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    /**
     * Escape HTML special characters.
     *
     * @param {string} text
     * @returns {string}
     */
    const escapeHtml = function(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return text.replace(/[&<>"']/g, function(c) {
            return map[c];
        });
    };

    /**
     * Process inline markdown (bold, italic, links).
     *
     * @param {string} text
     * @returns {string}
     */
    const processInline = function(text) {
        let result = escapeHtml(text);

        // Bold: **text** or __text__
        result = result.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        result = result.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // Italic: *text* or _text_
        result = result.replace(/\*(.+?)\*/g, '<em>$1</em>');
        result = result.replace(/_(.+?)_/g, '<em>$1</em>');

        // Strikethrough: ~~text~~
        result = result.replace(/~~(.+?)~~/g, '<del>$1</del>');

        // Links: [text](url) — URL scheme is checked against a denylist so that
        // poisoned AI output or RAG content cannot emit javascript:, data:,
        // vbscript:, or file: URIs that would execute when a learner clicks.
        result = result.replace(
            /\[([^\]]+)\]\(([^)]+)\)/g,
            function(match, linkText, url) {
                var trimmed = String(url).trim();
                // SECURITY: allowlist the scheme, and decide it on the string
                // the BROWSER will parse. The WHATWG URL parser removes every
                // ASCII tab, CR and LF before resolving a scheme, so a literal
                // denylist ("javascript:") never matches "java\tscript:" while
                // the browser still navigates to javascript:. Checked against
                // an allowlist after stripping those characters, both forms
                // fail closed, as does anything else exotic.
                var probe = trimmed.replace(/[\t\r\n\0-\x1f]/g, '');
                if (!/^(https?:|mailto:|#|\/|\.\/|\.\.\/)/i.test(probe)) {
                    trimmed = 'about:blank';
                }
                return '<a href="' + trimmed + '" target="_blank" rel="noopener noreferrer">' + linkText + '</a>';
            }
        );

        return result;
    };

    /**
     * Defense in depth sanitizer: strip dangerous constructs from any HTML
     * string before it is assigned to innerHTML. Callers that render AI output
     * pass the markdown.render() result through this. The allowlist of tags
     * that markdown.render() emits is narrow; this function is a safety net for
     * anything that slipped through and for callers that bypass render().
     *
     * @param {string} html
     * @returns {string}
     */
    const sanitize = function(html) {
        if (!html) {
            return '';
        }
        var out = String(html);
        // Remove <script> blocks entirely.
        out = out.replace(/<script[\s\S]*?<\/script>/gi, '');
        // Remove <iframe>, <object>, <embed>, <form>, <style>, <link>, <meta>.
        out = out.replace(/<\/?(iframe|object|embed|form|style|link|meta)[^>]*>/gi, '');
        // NOTE: an event-handler stripper used to run here. It could never
        // protect anything -- render() HTML-escapes its input before this
        // point, so no live attribute survives to be stripped -- but it did
        // delete real text: "you attach a handler with onclick=\"doThing()\""
        // rendered as "you attach a handler with", and its unquoted branch ate
        // across tag boundaries, producing malformed HTML. Any SOLA answer
        // about DOM events lost words. Escaping is the defence; this was not.
        // Strip javascript:, vbscript:, data:text/html URIs inside href and src.
        out = out.replace(/(\s(?:href|src)\s*=\s*["']?)\s*(?:javascript|vbscript|data\s*:\s*text\/html)\s*:[^"'\s>]*/gi, '$1about:blank');
        return out;
    };

    /**
     * Split one markdown table row into its cells.
     *
     * A leading and a trailing pipe are optional, and "\\|" is a literal pipe
     * inside a cell. Pipes inside inline code are already safe here, because
     * render() swaps inline code for placeholders before tables are parsed.
     *
     * @param {string} line
     * @returns {string[]}
     */
    const splitRow = function(line) {
        let row = line.trim();
        if (row.startsWith('|')) {
            row = row.slice(1);
        }
        if (row.endsWith('|') && !row.endsWith('\\|')) {
            row = row.slice(0, -1);
        }
        // A plain loop rather than a lookbehind regex: Safari before 16.4 can't
        // parse lookbehind at all, and a syntax error here would take the whole
        // chat down on older iPhones, not just the table.
        const cells = [];
        let cell = '';
        for (let k = 0; k < row.length; k++) {
            const ch = row.charAt(k);
            if (ch === '\\' && row.charAt(k + 1) === '|') {
                cell += '|';
                k++;
            } else if (ch === '|') {
                cells.push(cell.trim());
                cell = '';
            } else {
                cell += ch;
            }
        }
        cells.push(cell.trim());
        return cells;
    };

    /** @type {RegExp} The line under a table's header row, e.g. "| --- | :---: |". */
    const TABLE_DIVIDER = /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/;

    /**
     * Whether lines[i] starts a table: a row with a pipe, then a divider line
     * with the same number of cells.
     *
     * @param {string[]} lines
     * @param {number} i
     * @returns {boolean}
     */
    const startsTable = function(lines, i) {
        if (i + 1 >= lines.length || lines[i].indexOf('|') === -1) {
            return false;
        }
        const divider = lines[i + 1];
        if (!TABLE_DIVIDER.test(divider) || divider.indexOf('-') === -1) {
            return false;
        }
        // A divider with no pipe is a horizontal rule or a setext underline,
        // unless the header is a single cell written with its own pipes.
        if (divider.indexOf('|') === -1 && splitRow(lines[i]).length !== 1) {
            return false;
        }
        return splitRow(lines[i]).length === splitRow(divider).length;
    };

    /**
     * Render the table that starts at lines[start].
     *
     * Body rows run until a blank line or a line with no pipe. A row with too
     * few cells is padded and one with too many is cut, as GitHub does, so a
     * model's ragged table still lines up.
     *
     * @param {string[]} lines
     * @param {number} start
     * @returns {{html: string, next: number}} The HTML and the index of the first line after the table.
     */
    const renderTable = function(lines, start) {
        const header = splitRow(lines[start]);
        const aligns = splitRow(lines[start + 1]).map(function(cell) {
            const left = cell.startsWith(':');
            const right = cell.endsWith(':');
            if (left && right) {
                return 'center';
            }
            return right ? 'right' : (left ? 'left' : '');
        });
        const cellHtml = function(tag, text, col) {
            const cls = aligns[col] ? ' class="aica-md-align-' + aligns[col] + '"' : '';
            return '<' + tag + cls + '>' + processInline(text) + '</' + tag + '>';
        };
        const out = ['<div class="aica-md-table"><table>', '<thead><tr>'];
        header.forEach(function(text, col) {
            out.push(cellHtml('th', text, col));
        });
        out.push('</tr></thead>');

        let i = start + 2;
        const body = [];
        while (i < lines.length && lines[i].trim() !== '' && lines[i].indexOf('|') !== -1 &&
                !/^\x00CODEBLOCK\d+\x00$/.test(lines[i])) {
            const cells = splitRow(lines[i]);
            const row = ['<tr>'];
            for (let col = 0; col < header.length; col++) {
                row.push(cellHtml('td', cells[col] || '', col));
            }
            row.push('</tr>');
            body.push(row.join(''));
            i++;
        }
        if (body.length) {
            out.push('<tbody>' + body.join('') + '</tbody>');
        }
        out.push('</table></div>');
        return {html: out.join(''), next: i};
    };

    /**
     * Convert markdown text to HTML.
     *
     * @param {string} text Raw markdown text
     * @returns {string} HTML string
     */
    const render = function(text) {
        if (!text) {
            return '';
        }

        // Normalize line endings.
        let md = text.replace(/\r\n/g, '\n');

        // Extract code blocks first to protect them from further processing.
        const codeBlocks = [];
        md = md.replace(/```(\w*)\n([\s\S]*?)```/g, function(match, lang, code) {
            const idx = codeBlocks.length;
            codeBlocks.push('<pre><code' + (lang ? ' class="language-' + escapeHtml(lang) + '"' : '') +
                '>' + escapeHtml(code.replace(/\n$/, '')) + '</code></pre>');
            return '\x00CODEBLOCK' + idx + '\x00';
        });

        // Extract inline code.
        const inlineCodes = [];
        md = md.replace(/`([^`]+)`/g, function(match, code) {
            const idx = inlineCodes.length;
            inlineCodes.push('<code>' + escapeHtml(code) + '</code>');
            return '\x00INLINECODE' + idx + '\x00';
        });

        // Process line by line.
        const lines = md.split('\n');
        const output = [];
        let inList = false;
        let listType = '';
        const closeList = function() {
            if (inList) {
                output.push(listType === 'ul' ? '</ul>' : '</ol>');
                inList = false;
            }
        };

        for (let i = 0; i < lines.length; i++) {
            let line = lines[i];

            // Tables (GitHub style): a header row, a divider row, then body rows.
            if (startsTable(lines, i)) {
                closeList();
                const table = renderTable(lines, i);
                output.push(table.html);
                i = table.next - 1;
                continue;
            }

            // Horizontal rule: ---, *** or ___ alone on a line.
            if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
                closeList();
                output.push('<hr>');
                continue;
            }

            // Block quote: consecutive lines starting with ">". The raw text is
            // still unescaped at this point; processInline escapes each line.
            if (/^\s*>/.test(line)) {
                closeList();
                const quoted = [];
                while (i < lines.length && /^\s*>/.test(lines[i])) {
                    quoted.push(lines[i].replace(/^\s*>\s?/, ''));
                    i++;
                }
                i--;
                const paras = quoted.join('\n').split(/\n\s*\n/).filter(function(para) {
                    return para.trim() !== '';
                }).map(function(para) {
                    return '<p>' + para.split('\n').map(processInline).join('<br>') + '</p>';
                });
                output.push('<blockquote>' + paras.join('') + '</blockquote>');
                continue;
            }

            // Check for code block placeholder.
            const codeBlockMatch = line.match(/^\x00CODEBLOCK(\d+)\x00$/);
            if (codeBlockMatch) {
                if (inList) {
                    output.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = false;
                }
                output.push(codeBlocks[parseInt(codeBlockMatch[1])]);
                continue;
            }

            // Headers.
            const headerMatch = line.match(/^(#{1,6})\s+(.+)$/);
            if (headerMatch) {
                if (inList) {
                    output.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = false;
                }
                const level = headerMatch[1].length;
                output.push('<h' + level + '>' + processInline(headerMatch[2]) + '</h' + level + '>');
                continue;
            }

            // Unordered list items.
            const ulMatch = line.match(/^[\s]*[-*+]\s+(.+)$/);
            if (ulMatch) {
                if (!inList || listType !== 'ul') {
                    if (inList) {
                        output.push(listType === 'ul' ? '</ul>' : '</ol>');
                    }
                    output.push('<ul>');
                    inList = true;
                    listType = 'ul';
                }
                output.push('<li>' + processInline(ulMatch[1]) + '</li>');
                continue;
            }

            // Ordered list items.
            const olMatch = line.match(/^[\s]*\d+\.\s+(.+)$/);
            if (olMatch) {
                if (!inList || listType !== 'ol') {
                    if (inList) {
                        output.push(listType === 'ul' ? '</ul>' : '</ol>');
                    }
                    output.push('<ol>');
                    inList = true;
                    listType = 'ol';
                }
                output.push('<li>' + processInline(olMatch[1]) + '</li>');
                continue;
            }

            // Empty line — peek ahead so blank lines between list items don't break the list.
            // AI models frequently emit blank lines between numbered items, which would otherwise
            // cause each item to start a new <ol> and reset the counter to 1.
            if (line.trim() === '') {
                if (inList) {
                    let j = i + 1;
                    while (j < lines.length && lines[j].trim() === '') { j++; }
                    const peek = j < lines.length ? lines[j] : '';
                    const nextIsOl = !!peek.match(/^[\s]*\d+\.\s+/);
                    const nextIsUl = !!peek.match(/^[\s]*[-*+]\s+/);
                    if ((listType === 'ol' && nextIsOl) || (listType === 'ul' && nextIsUl)) {
                        continue; // Blank line inside a list — stay in list
                    }
                    output.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = false;
                }
                continue;
            }

            // Close list if we hit a non-list, non-empty line.
            if (inList) {
                output.push(listType === 'ul' ? '</ul>' : '</ol>');
                inList = false;
            }

            // Regular paragraph.
            output.push('<p>' + processInline(line) + '</p>');
        }

        // Close any open list.
        if (inList) {
            output.push(listType === 'ul' ? '</ul>' : '</ol>');
        }

        let html = output.join('\n');

        // Restore inline code.
        html = html.replace(/\x00INLINECODE(\d+)\x00/g, function(match, idx) {
            return inlineCodes[parseInt(idx)];
        });

        // Restore code blocks.
        html = html.replace(/\x00CODEBLOCK(\d+)\x00/g, function(match, idx) {
            return codeBlocks[parseInt(idx)];
        });

        return html;
    };

    return {
        render: render,
        sanitize: sanitize
    };
});
