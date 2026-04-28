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
 * Learning Radar admin UI controller.
 *
 * Owns the chat (with optional compare mode), starter and metric chips,
 * export menu (download / email / Slack / Teams / Redash), schedule
 * modal, past-queries history, citation tooltip, share-link, and
 * client-side cost estimate. The host page provides every endpoint URL
 * and the provider list via data-* attributes on #learning-radar.
 *
 * @module     local_ai_course_assistant/learning_radar
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var root, cfg;
    var providers = [];
    var chatHistory = [];
    var lastQuery = '';
    var lastResponse = '';
    var lastMeta = {};

    function $(id) { return document.getElementById(id); }
    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function(k) {
                if (k === 'class') { e.className = attrs[k]; }
                else if (k === 'style') { e.style.cssText = attrs[k]; }
                else { e.setAttribute(k, attrs[k]); }
            });
        }
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }
    function escapeHtml(s) {
        return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function init() {
        root = document.getElementById('learning-radar');
        if (!root) { return; }
        cfg = {
            sseUrl: root.dataset.sseUrl,
            exportUrl: root.dataset.exportUrl,
            scheduleUrl: root.dataset.scheduleUrl,
            citeUrl: root.dataset.citeUrl,
            redashUrl: root.dataset.redashUrl,
            hasRedash: root.dataset.hasRedash === '1',
            sesskey: root.dataset.sesskey,
            courseid: parseInt(root.dataset.courseid, 10) || 0,
            range: parseInt(root.dataset.range, 10) || 30
        };
        try {
            providers = JSON.parse(root.dataset.providers || '[]');
        } catch (e) {
            providers = [];
        }

        populateProviders($('ai-analysis-provider'), 0);
        populateProviders($('ai-analysis-provider-b'), Math.min(1, providers.length - 1));
        populateModels($('ai-analysis-provider'), $('ai-analysis-model'));
        populateModels($('ai-analysis-provider-b'), $('ai-analysis-model-b'));

        wireProviderChange();
        wireScopeDetail();
        wireCompareToggle();
        wireSendButton();
        wireInputEnter();
        wireStarters();
        wireMetricChips();
        wireExportMenu();
        wireScheduleButton();
        wireShareLink();
        wireSchedulePanel();
        wireHistoryPanel();
        wireCostEstimate();
        applyHashQuery();
    }

    /**
     * Populate a provider <select> from cfg.providers.
     */
    function populateProviders(select, defaultIndex) {
        if (!select) { return; }
        select.innerHTML = '';
        providers.forEach(function(p, i) {
            var opt = el('option', { value: p.id }, p.label);
            opt.dataset.models = JSON.stringify(p.models || []);
            select.appendChild(opt);
            if (i === defaultIndex) { opt.selected = true; }
        });
    }

    /**
     * Repopulate a model <select> from the currently-selected provider option.
     */
    function populateModels(providerSelect, modelSelect) {
        if (!providerSelect || !modelSelect) { return; }
        var opt = providerSelect.options[providerSelect.selectedIndex];
        var models = [];
        try { models = JSON.parse(opt.dataset.models || '[]'); } catch (e) { /* ignored */ }
        modelSelect.innerHTML = '';
        if (!models.length) {
            modelSelect.appendChild(el('option', { value: '' }, '(provider default)'));
            return;
        }
        models.forEach(function(m) {
            modelSelect.appendChild(el('option', { value: m }, m));
        });
    }

    function wireProviderChange() {
        var pa = $('ai-analysis-provider'), ma = $('ai-analysis-model');
        var pb = $('ai-analysis-provider-b'), mb = $('ai-analysis-model-b');
        if (pa && ma) { pa.addEventListener('change', function() { populateModels(pa, ma); }); }
        if (pb && mb) { pb.addEventListener('change', function() { populateModels(pb, mb); }); }
    }

    function wireScopeDetail() {
        var scope = $('ai-analysis-scope'), detail = $('ai-analysis-scope-detail'), value = $('ai-analysis-scope-value');
        if (!scope || !detail) { return; }
        scope.addEventListener('change', function() {
            var v = scope.value;
            if (v === 'custom' || v === 'byprovider') {
                detail.style.display = '';
                if (value) {
                    value.placeholder = v === 'custom' ? 'Course IDs (e.g. 2,5,12)' : 'Provider (e.g. openai)';
                }
            } else {
                detail.style.display = 'none';
            }
        });
    }

    function wireCompareToggle() {
        var cb = $('ai-analysis-compare');
        if (!cb) { return; }
        cb.addEventListener('change', function() {
            $('ai-analysis-compare-row').style.display = cb.checked ? '' : 'none';
            $('ai-analysis-messages-b').style.display = cb.checked ? '' : 'none';
        });
    }

    function wireSendButton() {
        var btn = $('ai-analysis-send');
        if (btn) { btn.addEventListener('click', sendQuery); }
    }

    function wireInputEnter() {
        var input = $('ai-analysis-input');
        if (!input) { return; }
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendQuery();
            }
        });
    }

    function wireStarters() {
        document.querySelectorAll('.learning-radar-starter').forEach(function(b) {
            b.addEventListener('click', function() {
                var q = b.dataset.query || '';
                if (!q) { return; }
                $('ai-analysis-input').value = q;
                sendQuery();
            });
        });
    }

    function wireMetricChips() {
        document.querySelectorAll('.learning-radar-chip').forEach(function(b) {
            b.addEventListener('click', function() {
                var q = b.dataset.query || '';
                if (!q) { return; }
                $('ai-analysis-input').value = q;
                sendQuery();
            });
        });
    }

    function wireCostEstimate() {
        var input = $('ai-analysis-input'), out = $('radar-cost-estimate');
        if (!input || !out) { return; }
        function update() {
            var q = input.value.trim();
            if (!q) { out.textContent = ''; return; }
            // Rough estimate: system prompt ~30k chars, transcript ~80k, history.
            var historyChars = chatHistory.reduce(function(sum, m) {
                return sum + (m.content ? m.content.length : 0);
            }, 0);
            var promptChars = 110000 + historyChars + q.length;
            var promptTokens = Math.ceil(promptChars / 4);
            var completionTokens = 800; // expected output ballpark
            var multiplier = $('ai-analysis-compare') && $('ai-analysis-compare').checked ? 2 : 1;
            var costUsd = ((promptTokens / 1000) * 0.0030 + (completionTokens / 1000) * 0.0150) * multiplier;
            out.textContent = '~' + promptTokens.toLocaleString() + ' input tokens · est. $' + costUsd.toFixed(3) + (multiplier === 2 ? ' (×2 for compare)' : '');
        }
        input.addEventListener('input', update);
    }

    /**
     * Build the SSE POST body for a query in the current UI state.
     */
    function buildParams(query, providerSelect, modelSelect) {
        var range = parseInt($('ai-analysis-range-select').value, 10);
        var scope = $('ai-analysis-scope').value;
        var scopeValue = $('ai-analysis-scope-value');
        var params = new URLSearchParams();
        params.append('sesskey', cfg.sesskey);
        params.append('query', query);
        params.append('provider', providerSelect.value);
        params.append('model', modelSelect.value);
        params.append('range', String(range));
        params.append('history', JSON.stringify(chatHistory.slice(0, -1)));
        if (scope === 'current') {
            params.append('courseid', String(cfg.courseid));
        } else if (scope === 'custom') {
            params.append('courseids', scopeValue ? scopeValue.value : '');
        } else if (scope === 'byprovider') {
            params.append('filterprovider', scopeValue ? scopeValue.value : '');
        }
        return { params: params, range: range, scope: scope };
    }

    function sendQuery() {
        var input = $('ai-analysis-input');
        var query = (input.value || '').trim();
        if (!query) { return; }
        chatHistory.push({ role: 'user', content: query });
        appendMessage($('ai-analysis-messages'), 'user', query);
        input.value = '';
        var sendBtn = $('ai-analysis-send');
        sendBtn.disabled = true;

        var compare = $('ai-analysis-compare').checked;
        var pA = $('ai-analysis-provider'), mA = $('ai-analysis-model');
        var doneA = false, doneB = !compare;
        var responseA = appendMessage($('ai-analysis-messages'), 'assistant', '');
        var responseBdiv = null;

        if (compare) {
            // Mirror the user message into the right pane and prepare the assistant slot.
            appendMessage($('ai-analysis-messages-b'), 'user', query);
            responseBdiv = appendMessage($('ai-analysis-messages-b'), 'assistant', '');
        }

        function streamFinish() {
            if (doneA && doneB) {
                sendBtn.disabled = false;
                input.focus();
            }
        }

        streamSse(buildParams(query, pA, mA).params, responseA, function(text) {
            doneA = true;
            lastQuery = query;
            lastResponse = text;
            chatHistory.push({ role: 'assistant', content: text });
            captureMeta(pA.value, mA.value);
            streamFinish();
        });

        if (compare) {
            var pB = $('ai-analysis-provider-b'), mB = $('ai-analysis-model-b');
            streamSse(buildParams(query, pB, mB).params, responseBdiv, function() {
                doneB = true;
                streamFinish();
            });
        }
    }

    /**
     * Stream a single SSE response and call onDone(fullText) when complete.
     */
    function streamSse(params, responseDiv, onDone) {
        var fullText = '';
        fetch(cfg.sseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function(resp) {
            if (!resp.body) { throw new Error('No streaming body'); }
            var reader = resp.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            function read() {
                return reader.read().then(function(r) {
                    if (r.done) {
                        // Strip [SOLA_NEXT] block, render follow-up chips.
                        var nextMatch = fullText.match(/\[SOLA_NEXT\]([\s\S]*?)\[\/SOLA_NEXT\]/);
                        var suggestions = [];
                        if (nextMatch) {
                            fullText = fullText.replace(nextMatch[0], '').trim();
                            renderResponse(responseDiv, fullText);
                            suggestions = nextMatch[1].split('||').map(function(s) { return s.trim(); }).filter(Boolean);
                        }
                        if (suggestions.length) {
                            var row = el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 4px' });
                            suggestions.forEach(function(t) {
                                var chip = el('button', {
                                    type: 'button',
                                    class: 'btn btn-sm btn-outline-primary',
                                    style: 'font-size:12px;padding:4px 10px;border-radius:14px;text-align:left;white-space:normal'
                                }, t);
                                chip.addEventListener('click', function() {
                                    $('ai-analysis-input').value = t;
                                    sendQuery();
                                });
                                row.appendChild(chip);
                            });
                            responseDiv.parentElement.appendChild(row);
                        }
                        onDone(fullText);
                        return;
                    }
                    buffer += decoder.decode(r.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line.indexOf('data: ') !== 0) { continue; }
                        try {
                            var p = JSON.parse(line.substring(6));
                            if (p.token) {
                                fullText += p.token;
                                renderResponse(responseDiv, fullText);
                            } else if (p.message) {
                                renderResponse(responseDiv, 'Error: ' + p.message);
                            } else if (p.full && !fullText) {
                                fullText = p.full;
                                renderResponse(responseDiv, fullText);
                            }
                        } catch (e) { /* tolerate partial JSON */ }
                    }
                    return read();
                });
            }
            return read();
        }).catch(function(err) {
            renderResponse(responseDiv, 'Error: ' + (err.message || 'Network error'));
            onDone('');
        });
    }

    /**
     * Render assistant text with [#NNN] citations turned into clickable badges.
     */
    function renderResponse(div, text) {
        // Strip the SOLA_NEXT block from the visible token stream.
        var clean = text.replace(/\[SOLA_NEXT\][\s\S]*?\[\/SOLA_NEXT\]/g, '');
        // Split on citation tokens, intersperse spans.
        var parts = clean.split(/(\[#\d+\])/g);
        div.innerHTML = '';
        parts.forEach(function(p) {
            if (!p) { return; }
            var m = p.match(/^\[#(\d+)\]$/);
            if (m) {
                var badge = el('button', {
                    type: 'button',
                    class: 'badge bg-info-subtle text-info-emphasis radar-cite',
                    'data-cite-id': m[1],
                    style: 'border:none;cursor:pointer;font-size:11px;margin:0 2px;padding:2px 6px'
                }, '#' + m[1]);
                badge.addEventListener('click', showCitation);
                div.appendChild(badge);
            } else {
                div.appendChild(document.createTextNode(p));
            }
        });
        var pane = div.parentElement;
        if (pane && pane.scrollTop !== undefined) { pane.scrollTop = pane.scrollHeight; }
    }

    function appendMessage(pane, role, text) {
        if (!pane) { return null; }
        var div = el('div', {
            style: 'margin-bottom:10px;padding:8px 12px;border-radius:8px;max-width:90%;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word;'
                + (role === 'user'
                    ? 'background:#023e8a;color:#fff;margin-left:auto;text-align:right;'
                    : 'background:#fff;border:1px solid #dee2e6;')
        }, text || '');
        // Clear placeholder text on first message.
        var placeholder = pane.querySelector('.text-muted');
        if (placeholder) { placeholder.remove(); }
        pane.appendChild(div);
        pane.scrollTop = pane.scrollHeight;
        return div;
    }

    function showCitation(e) {
        var id = e.currentTarget.dataset.citeId;
        if (!id) { return; }
        var rect = e.currentTarget.getBoundingClientRect();
        var pop = document.getElementById('radar-cite-pop') || (function() {
            var p = el('div', {
                id: 'radar-cite-pop',
                role: 'tooltip',
                style: 'position:absolute;z-index:1080;max-width:480px;background:#fff;border:1px solid #ced4da;'
                    + 'border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:10px 12px;font-size:13px;line-height:1.45;display:none'
            });
            document.body.appendChild(p);
            return p;
        })();

        pop.innerHTML = '<div class="text-muted">Loading citation #' + escapeHtml(id) + '&hellip;</div>';
        pop.style.left = (window.scrollX + rect.left) + 'px';
        pop.style.top = (window.scrollY + rect.bottom + 4) + 'px';
        pop.style.display = 'block';

        var url = cfg.citeUrl + '?id=' + encodeURIComponent(id) + '&sesskey=' + encodeURIComponent(cfg.sesskey);
        fetch(url, { credentials: 'same-origin' }).then(function(r) { return r.json(); }).then(function(j) {
            if (!j.ok) {
                pop.innerHTML = '<div class="text-muted">Citation not found.</div>';
                return;
            }
            var msg = j.message;
            if (msg && msg.length > 800) { msg = msg.substring(0, 800) + '… (truncated)'; }
            pop.innerHTML = '<div style="margin-bottom:4px"><strong>' + escapeHtml(j.who) + '</strong> '
                + '<span class="text-muted">— ' + escapeHtml(j.course) + ' · ' + escapeHtml(j.date) + '</span></div>'
                + '<div style="white-space:pre-wrap">' + escapeHtml(msg) + '</div>'
                + '<div class="text-end mt-2"><button type="button" class="btn btn-sm btn-link p-0" id="radar-cite-close">Close</button></div>';
            document.getElementById('radar-cite-close').addEventListener('click', function() {
                pop.style.display = 'none';
            });
        }).catch(function() {
            pop.innerHTML = '<div class="text-muted">Could not load citation.</div>';
        });

        // Dismiss on outside click.
        setTimeout(function() {
            document.addEventListener('click', function dismiss(ev) {
                if (!pop.contains(ev.target) && ev.target.dataset.citeId !== id) {
                    pop.style.display = 'none';
                    document.removeEventListener('click', dismiss);
                }
            });
        }, 0);
    }

    function captureMeta(provider, model) {
        var range = parseInt($('ai-analysis-range-select').value, 10);
        lastMeta = {
            provider: provider || 'primary',
            model: model || 'default',
            range_days: String(range),
            scope: $('ai-analysis-scope').value
        };
    }

    function wireExportMenu() {
        var menu = $('radar-export-menu');
        if (!menu) { return; }
        menu.addEventListener('click', function(e) {
            var t = e.target;
            if (t.dataset.export) {
                e.preventDefault();
                downloadExport(t.dataset.export);
            } else if (t.dataset.send) {
                e.preventDefault();
                openSendDialog(t.dataset.send);
            } else if (t.hasAttribute('data-redash-push')) {
                e.preventDefault();
                pushToRedash();
            } else if (t.hasAttribute('data-redash-setup')) {
                e.preventDefault();
                openRedashSetup();
            }
        });
    }

    function pushToRedash() {
        if (!lastResponse) { alert('Run a query first.'); return; }
        var name = window.prompt('Name for the new Redash query:',
            'SOLA Learning Radar — ' + new Date().toISOString().substring(0, 16));
        if (!name) { return; }
        var body = new URLSearchParams();
        body.append('sesskey', cfg.sesskey);
        body.append('action', 'push_redash');
        body.append('name', name);
        body.append('query', lastQuery);
        body.append('response', lastResponse);
        body.append('meta', JSON.stringify(lastMeta));
        fetch(cfg.exportUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function(r) { return r.json(); }).then(function(j) {
            if (j.ok && j.url) {
                if (window.confirm('Redash query created. Open in a new tab?\n\n' + j.url)) {
                    window.open(j.url, '_blank');
                }
            } else {
                alert('Redash push failed: ' + (j.error || 'unknown error'));
            }
        }).catch(function(err) {
            alert('Redash push error: ' + err.message);
        });
    }

    function openRedashSetup() {
        fetch(cfg.exportUrl + '?action=redash_setup&sesskey=' + encodeURIComponent(cfg.sesskey), {
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function(j) {
            if (!j.ok) { alert('Could not load Redash setup: ' + (j.error || '')); return; }
            renderRedashSetupModal(j);
        });
    }

    function renderRedashSetupModal(info) {
        var overlay = el('div', {
            id: 'radar-redash-overlay',
            style: 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1090;display:flex;align-items:center;justify-content:center'
        });
        var modal = el('div', { class: 'card', style: 'max-width:680px;width:94%;max-height:90vh;overflow:auto' });
        var pullUrl = info.pull_url || '';
        var apiKeyOk = !!info.has_redash_api_key;
        var status = info.configured
            ? '<span class="text-success">Push is configured</span>'
            : '<span class="text-warning">Push not configured (read-only set-up below)</span>';
        var sample1 = '# Pull every Learning Radar query+response\nurl: ' + pullUrl + '\npath: learning_radar_queries\n';
        var sample2 = '# Pull token cost rows\nurl: ' + pullUrl + '\npath: token_costs\n';
        var sample3 = '# Pull feedback rows\nurl: ' + pullUrl + '\npath: feedback\n';

        modal.innerHTML =
            '<div class="card-header d-flex justify-content-between align-items-center">'
            + '<h5 class="mb-0">Set up Redash for Learning Radar</h5>'
            + '<button class="btn btn-sm btn-link" id="radar-redash-close">×</button></div>'
            + '<div class="card-body">'
            + '<p class="text-muted small mb-2">' + status + '</p>'
            + '<h6>1. Make sure SOLA can serve data</h6>'
            + (apiKeyOk
                ? '<p class="small mb-1 text-success">SOLA Redash API key is configured.</p>'
                : '<p class="small mb-1 text-warning">No <code>redash_api_key</code> configured — set one in plugin settings before continuing.</p>')
            + '<p class="small mb-2">SOLA pull URL:</p>'
            + '<div style="display:flex;gap:6px;margin-bottom:12px">'
            + '<input id="radar-redash-pullurl" class="form-control form-control-sm" readonly value="' + escapeHtml(pullUrl) + '">'
            + '<button class="btn btn-sm btn-outline-secondary" id="radar-redash-copyurl">Copy</button>'
            + '</div>'
            + '<h6>2. In Redash, create a JSON data source</h6>'
            + '<ol class="small mb-2">'
            + '<li>Settings &rarr; Data Sources &rarr; New &rarr; <strong>JSON</strong> (the JSON ds plugin must be enabled in Redash).</li>'
            + '<li>Name it <code>SOLA</code>. Save.</li>'
            + '<li>Note the new data source\'s numeric id (visible in the URL after saving). You will need that id below.</li>'
            + '</ol>'
            + '<h6>3. Configure the SOLA push (optional, but recommended)</h6>'
            + '<p class="small mb-2">In SOLA admin settings, fill in <code>redash_base_url</code>, <code>redash_user_api_key</code>, and <code>redash_data_source_id</code>. Once those are set, the "Send to Redash" action in this menu creates a new Redash query in one click.</p>'
            + '<h6>4. Or write Redash queries by hand</h6>'
            + '<p class="small mb-1">Paste any of these into a new Redash query against the SOLA data source:</p>'
            + '<pre class="small bg-light p-2 mb-1" style="white-space:pre-wrap">' + escapeHtml(sample1) + '</pre>'
            + '<pre class="small bg-light p-2 mb-1" style="white-space:pre-wrap">' + escapeHtml(sample2) + '</pre>'
            + '<pre class="small bg-light p-2 mb-2" style="white-space:pre-wrap">' + escapeHtml(sample3) + '</pre>'
            + '</div>'
            + '<div class="card-footer text-end">'
            + '<button class="btn btn-sm btn-outline-secondary" id="radar-redash-done">Done</button>'
            + '</div>';
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        function close() { document.body.removeChild(overlay); }
        document.getElementById('radar-redash-close').addEventListener('click', close);
        document.getElementById('radar-redash-done').addEventListener('click', close);
        document.getElementById('radar-redash-copyurl').addEventListener('click', function() {
            copyToClipboard(pullUrl);
        });
    }

    function downloadExport(format) {
        if (!lastResponse) { alert('Run a query first.'); return; }
        var f = el('form', { method: 'POST', action: cfg.exportUrl, target: '_blank' });
        appendHidden(f, 'sesskey', cfg.sesskey);
        appendHidden(f, 'action', 'download');
        appendHidden(f, 'format', format);
        appendHidden(f, 'query', lastQuery);
        appendHidden(f, 'response', lastResponse);
        appendHidden(f, 'meta', JSON.stringify(lastMeta));
        document.body.appendChild(f);
        f.submit();
        document.body.removeChild(f);
    }

    function appendHidden(form, name, value) {
        var i = el('input', { type: 'hidden', name: name });
        i.value = value;
        form.appendChild(i);
    }

    function openSendDialog(channel) {
        if (!lastResponse) { alert('Run a query first.'); return; }
        var prompt1, prompt2;
        if (channel === 'email') {
            prompt1 = 'Recipient email address:';
            prompt2 = window.prompt(prompt1, '');
            if (!prompt2) { return; }
            postSend({ action: 'email', to: prompt2, format: 'text' });
        } else if (channel === 'slack' || channel === 'teams') {
            prompt1 = (channel === 'slack' ? 'Slack' : 'Teams') + ' incoming webhook URL:';
            prompt2 = window.prompt(prompt1, '');
            if (!prompt2) { return; }
            postSend({ action: channel, webhook: prompt2 });
        }
    }

    function postSend(extra) {
        var body = new URLSearchParams();
        body.append('sesskey', cfg.sesskey);
        body.append('query', lastQuery);
        body.append('response', lastResponse);
        body.append('meta', JSON.stringify(lastMeta));
        Object.keys(extra).forEach(function(k) { body.append(k, extra[k]); });
        fetch(cfg.exportUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function(r) { return r.json(); }).then(function(j) {
            if (j.ok) {
                alert('Sent.');
            } else {
                alert('Send failed: ' + (j.error || 'unknown error'));
            }
        }).catch(function(err) {
            alert('Send error: ' + err.message);
        });
    }

    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
            return;
        }
        var t = el('textarea', { style: 'position:fixed;opacity:0' });
        t.value = text;
        document.body.appendChild(t);
        t.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        document.body.removeChild(t);
    }

    function wireShareLink() {
        var btn = $('radar-share');
        if (!btn) { return; }
        btn.addEventListener('click', function() {
            var q = $('ai-analysis-input').value.trim() || lastQuery;
            if (!q) { alert('Type or run a query first.'); return; }
            var url = window.location.origin + window.location.pathname + window.location.search
                + '#q=' + encodeURIComponent(q);
            copyToClipboard(url);
            alert('Share link copied to clipboard.');
        });
    }

    function applyHashQuery() {
        var m = window.location.hash.match(/[#&]q=([^&]+)/);
        if (!m) { return; }
        var q = decodeURIComponent(m[1]);
        var input = $('ai-analysis-input');
        if (input) { input.value = q; }
    }

    function wireScheduleButton() {
        var btn = $('radar-schedule-this');
        if (btn) { btn.addEventListener('click', function() { openScheduleModal(null, lastQuery || ($('ai-analysis-input').value || '')); }); }
        var btn2 = $('radar-new-schedule');
        if (btn2) { btn2.addEventListener('click', function() { openScheduleModal(null, ''); }); }
    }

    function wireSchedulePanel() {
        document.querySelectorAll('.radar-sched-edit').forEach(function(b) {
            b.addEventListener('click', function() { loadAndEditSchedule(parseInt(b.dataset.id, 10)); });
        });
        document.querySelectorAll('.radar-sched-toggle').forEach(function(b) {
            b.addEventListener('click', function() {
                var body = new URLSearchParams();
                body.append('sesskey', cfg.sesskey);
                body.append('action', 'toggle');
                body.append('id', b.dataset.id);
                body.append('enabled', b.dataset.enabled);
                fetch(cfg.scheduleUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function() {
                    window.location.reload();
                });
            });
        });
        document.querySelectorAll('.radar-sched-delete').forEach(function(b) {
            b.addEventListener('click', function() {
                if (!confirm('Delete this schedule?')) { return; }
                var body = new URLSearchParams();
                body.append('sesskey', cfg.sesskey);
                body.append('action', 'delete');
                body.append('id', b.dataset.id);
                fetch(cfg.scheduleUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function() {
                    window.location.reload();
                });
            });
        });
    }

    function wireHistoryPanel() {
        var search = $('radar-history-search');
        if (search) {
            search.addEventListener('input', function() {
                var q = search.value.toLowerCase();
                document.querySelectorAll('#radar-history-table tbody tr').forEach(function(r) {
                    var text = (r.dataset.query || '').toLowerCase();
                    r.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        }
        document.querySelectorAll('.radar-history-rerun').forEach(function(b) {
            b.addEventListener('click', function() {
                var row = b.closest('tr');
                if (!row) { return; }
                $('ai-analysis-input').value = row.dataset.query || '';
                sendQuery();
            });
        });
    }

    function loadAndEditSchedule(id) {
        var url = cfg.scheduleUrl + '?action=get&id=' + id + '&sesskey=' + encodeURIComponent(cfg.sesskey);
        fetch(url, { credentials: 'same-origin' }).then(function(r) { return r.json(); }).then(function(j) {
            if (!j.ok) { alert('Could not load schedule: ' + (j.error || '')); return; }
            openScheduleModal(j.schedule, j.schedule.query);
        });
    }

    function openScheduleModal(existing, prefillQuery) {
        var overlay = el('div', {
            id: 'radar-schedule-overlay',
            style: 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1090;display:flex;align-items:center;justify-content:center'
        });
        var modal = el('div', {
            class: 'card', style: 'max-width:560px;width:92%;max-height:90vh;overflow:auto'
        });
        var header = '<div class="card-header d-flex justify-content-between align-items-center">'
            + '<h5 class="mb-0">' + (existing ? 'Edit schedule' : 'Schedule a Learning Radar query') + '</h5>'
            + '<button class="btn btn-sm btn-link" id="radar-schedule-close">×</button></div>';
        var providerOptions = providers.map(function(p) {
            return '<option value="' + escapeHtml(p.id) + '">' + escapeHtml(p.label) + '</option>';
        }).join('');
        modal.innerHTML = header
            + '<div class="card-body">'
            + '<div class="mb-2"><label class="form-label small">Name</label>'
            + '<input class="form-control form-control-sm" id="rs-name"></div>'
            + '<div class="mb-2"><label class="form-label small">Query</label>'
            + '<textarea class="form-control form-control-sm" id="rs-query" rows="3"></textarea></div>'
            + '<div class="row g-2">'
            + '<div class="col-6"><label class="form-label small">Frequency</label>'
            + '<select class="form-control form-control-sm" id="rs-frequency">'
            + '<option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option>'
            + '</select></div>'
            + '<div class="col-6"><label class="form-label small">Format</label>'
            + '<select class="form-control form-control-sm" id="rs-format">'
            + '<option value="text">Text in email body</option><option value="csv">CSV attachment</option>'
            + '<option value="json">JSON attachment</option><option value="markdown">Markdown attachment</option>'
            + '</select></div>'
            + '<div class="col-6"><label class="form-label small">Provider</label>'
            + '<select class="form-control form-control-sm" id="rs-provider">' + providerOptions + '</select></div>'
            + '<div class="col-6"><label class="form-label small">Model (optional)</label>'
            + '<input class="form-control form-control-sm" id="rs-model" placeholder="provider default"></div>'
            + '<div class="col-12"><label class="form-label small">Recipient email (optional)</label>'
            + '<input class="form-control form-control-sm" id="rs-email" placeholder="reports@example.org"></div>'
            + '<div class="col-12"><label class="form-label small">Slack incoming webhook (optional)</label>'
            + '<input class="form-control form-control-sm" id="rs-slack" placeholder="https://hooks.slack.com/services/..."></div>'
            + '<div class="col-12"><label class="form-label small">Teams incoming webhook (optional)</label>'
            + '<input class="form-control form-control-sm" id="rs-teams" placeholder="https://outlook.office.com/webhook/..."></div>'
            + '<div class="col-6"><label class="form-label small">Course IDs (optional)</label>'
            + '<input class="form-control form-control-sm" id="rs-courseids" placeholder="2,5,12"></div>'
            + '<div class="col-6"><label class="form-label small">Range (days, optional)</label>'
            + '<input type="number" class="form-control form-control-sm" id="rs-range" placeholder="auto"></div>'
            + '<div class="col-12 form-check mt-2">'
            + '<input class="form-check-input" type="checkbox" id="rs-enabled" checked>'
            + '<label class="form-check-label small" for="rs-enabled">Enabled</label></div>'
            + '</div></div>'
            + '<div class="card-footer text-end">'
            + '<button class="btn btn-sm btn-outline-secondary me-2" id="radar-schedule-cancel">Cancel</button>'
            + '<button class="btn btn-sm btn-primary" id="radar-schedule-save">Save</button>'
            + '</div>';
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        if (prefillQuery) { document.getElementById('rs-query').value = prefillQuery; }
        if (existing) {
            document.getElementById('rs-name').value = existing.name || '';
            document.getElementById('rs-query').value = existing.query || '';
            document.getElementById('rs-frequency').value = existing.frequency || 'weekly';
            document.getElementById('rs-format').value = existing.format || 'text';
            if (existing.provider) { document.getElementById('rs-provider').value = existing.provider; }
            document.getElementById('rs-model').value = existing.model || '';
            document.getElementById('rs-email').value = existing.recipient_email || '';
            document.getElementById('rs-slack').value = existing.slack_webhook || '';
            document.getElementById('rs-teams').value = existing.teams_webhook || '';
            document.getElementById('rs-courseids').value = existing.courseids || '';
            document.getElementById('rs-range').value = existing.range_days || '';
            document.getElementById('rs-enabled').checked = !!existing.enabled;
        } else if (prefillQuery) {
            document.getElementById('rs-name').value = prefillQuery.substring(0, 60);
        }

        function close() { document.body.removeChild(overlay); }
        document.getElementById('radar-schedule-close').addEventListener('click', close);
        document.getElementById('radar-schedule-cancel').addEventListener('click', close);
        document.getElementById('radar-schedule-save').addEventListener('click', function() {
            var body = new URLSearchParams();
            body.append('sesskey', cfg.sesskey);
            body.append('action', 'save');
            if (existing) { body.append('id', existing.id); }
            body.append('name', document.getElementById('rs-name').value);
            body.append('query', document.getElementById('rs-query').value);
            body.append('frequency', document.getElementById('rs-frequency').value);
            body.append('format', document.getElementById('rs-format').value);
            body.append('provider', document.getElementById('rs-provider').value);
            body.append('model', document.getElementById('rs-model').value);
            body.append('recipient_email', document.getElementById('rs-email').value);
            body.append('slack_webhook', document.getElementById('rs-slack').value);
            body.append('teams_webhook', document.getElementById('rs-teams').value);
            body.append('courseids', document.getElementById('rs-courseids').value);
            body.append('range_days', document.getElementById('rs-range').value);
            body.append('enabled', document.getElementById('rs-enabled').checked ? '1' : '0');
            fetch(cfg.scheduleUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (j.ok) { window.location.reload(); }
                    else { alert('Save failed: ' + (j.error || '')); }
                });
        });
    }

    return { init: init };
});
