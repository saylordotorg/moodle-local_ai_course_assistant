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
 * Analytics dashboard tab loader and chart rendering.
 *
 * @module     local_ai_course_assistant/analytics_dashboard
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Analytics dashboard controller with Chart.js visualization.
 *
 * Handles tab switching, AJAX data loading, Chart.js rendering, and CSV export
 * for the 7-tab analytics dashboard.
 *
 * @module     local_ai_course_assistant/analytics_dashboard
 * @copyright  2026 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/templates', 'core/chartjs'], function(Ajax, Templates, Chart) {

    var config = {};
    var cache = {};
    var charts = {};
    var activeTab = 'overall';

    // Resolve a UI string from the PHP-supplied config (CONTRIB-10574 #79);
    // falls back to the key so a missing string is visible, not blank.
    function s(key) {
        return (config.strings && config.strings[key]) || key;
    }

    // Chart.js color palette.
    var COLORS = [
        '#3b5bdb', '#1098ad', '#37b24d', '#f59f00', '#e8590c',
        '#ae3ec9', '#4263eb', '#0ca678', '#e67700', '#d6336c',
    ];
    var COLORS_ALPHA = COLORS.map(function(c) { return c + '33'; });

    /**
     * Initialize the dashboard.
     */
    function init(cfg) {
        config = cfg || {};
        config.courseid = parseInt(config.courseid, 10) || 0;
        config.since = parseInt(config.since, 10) || 0;

        bindTabEvents();
        bindFilterEvents();
        // Load the default tab.
        loadTab('overall');
    }

    function bindTabEvents() {
        var tabs = document.querySelectorAll('.sola-analytics-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var tabId = this.dataset.tab;
                if (tabId === activeTab) {
                    return;
                }
                // Update active state.
                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                // Hide all panes, show selected.
                document.querySelectorAll('.sola-analytics-pane').forEach(function(p) {
                    p.style.display = 'none';
                });
                var pane = document.getElementById('sola-pane-' + tabId);
                if (pane) {
                    pane.style.display = '';
                }
                activeTab = tabId;
                loadTab(tabId);
            });
        });
    }

    function bindFilterEvents() {
        var rangeSelect = document.getElementById('sola-analytics-range');
        if (rangeSelect) {
            rangeSelect.addEventListener('change', function() {
                var days = parseInt(this.value, 10);
                config.since = days > 0 ? Math.floor(Date.now() / 1000) - (days * 86400) : 0;
                cache = {};
                destroyAllCharts();
                loadTab(activeTab);
            });
        }
        var courseSelect = document.getElementById('sola-analytics-course');
        if (courseSelect) {
            courseSelect.addEventListener('change', function() {
                config.courseid = parseInt(this.value, 10) || 0;
                cache = {};
                destroyAllCharts();
                loadTab(activeTab);
            });
        }
    }

    function destroyAllCharts() {
        Object.keys(charts).forEach(function(key) {
            if (charts[key]) {
                charts[key].destroy();
                charts[key] = null;
            }
        });
    }

    function loadTab(tabId) {
        if (cache[tabId]) {
            renderTab(tabId, cache[tabId]);
            return;
        }
        var pane = document.getElementById('sola-pane-' + tabId);
        if (!pane) { return; }
        showLoading(pane);

        var methodMap = {
            'overall': 'local_ai_course_assistant_get_analytics_overall',
            'bycourse': 'local_ai_course_assistant_get_analytics_by_course',
            'comparison': 'local_ai_course_assistant_get_analytics_comparison',
            'byunit': 'local_ai_course_assistant_get_analytics_by_unit',
            'usagetypes': 'local_ai_course_assistant_get_analytics_usage_types',
            'themes': 'local_ai_course_assistant_get_analytics_themes',
            'feedback': 'local_ai_course_assistant_get_analytics_feedback',
        };

        var method = methodMap[tabId];
        if (!method) { return; }

        Ajax.call([{
            methodname: method,
            args: {courseid: config.courseid, since: config.since},
        }])[0].then(function(response) {
            var data = typeof response.data === 'string' ? JSON.parse(response.data) : response;
            cache[tabId] = data;
            renderTab(tabId, data);
        }).catch(function(err) {
            hideLoading(pane);
            var content = pane.querySelector('.sola-analytics-content');
            // Render the error state from a Mustache template (auto-escaped)
            // rather than an innerHTML string (CONTRIB-10574 #94).
            Templates.render('local_ai_course_assistant/analytics_message', {
                danger: true,
                message: s('error_loading') + ': ' + (err.message || err)
            }).then(function(html, js) {
                Templates.replaceNodeContents(content, html, js);
                return null;
            }).catch(function() {
                content.textContent = s('error_loading') + '.';
            });
        });
    }

    // The spinner is built with createElement rather than rendered from a
    // template because showLoading runs synchronously right before the AJAX
    // call. A template render is a promise, and on a cold template cache it can
    // resolve AFTER the data has come back and the pane has been repainted,
    // which would put the spinner back on top of the finished tab. It is also
    // the "small fragment" case the reviewer said may stay as it is.
    function showLoading(pane) {
        var content = pane.querySelector('.sola-analytics-content');
        if (!content) { return; }
        while (content.firstChild) { content.removeChild(content.firstChild); }
        var wrap = document.createElement('div');
        wrap.className = 'text-center p-4';
        var spinner = document.createElement('div');
        spinner.className = 'spinner-border text-primary';
        spinner.setAttribute('role', 'status');
        var label = document.createElement('p');
        label.className = 'mt-2 text-muted';
        label.textContent = s('loading');
        wrap.appendChild(spinner);
        wrap.appendChild(label);
        content.appendChild(wrap);
    }

    function hideLoading(pane) {
        var spinner = pane.querySelector('.spinner-border');
        if (spinner) { spinner.remove(); }
    }

    /**
     * Render a pane from a Mustache template instead of an innerHTML string
     * (CONTRIB-10574 #278). Chart.js work has to run in the callback: the
     * canvases only exist in the document once the render promise has settled.
     *
     * @param {String} paneid DOM id of the pane
     * @param {String} name Template name, without the component prefix
     * @param {Object} context Template context
     * @param {Function} after Optional callback run after the nodes are in place
     */
    function renderPane(paneid, name, context, after) {
        var pane = document.getElementById(paneid);
        if (!pane) { return; }
        var content = pane.querySelector('.sola-analytics-content');
        if (!content) { return; }
        // Two-argument then(), not then().catch(): the rejection handler must
        // only see a failed template render. Chained as a catch it would also
        // swallow anything thrown by after(), and a Chart.js error would then
        // wipe the tab that had just rendered correctly and replace it with
        // "Error loading data".
        Templates.render('local_ai_course_assistant/' + name, context).then(function(html, js) {
            Templates.replaceNodeContents(content, html, js);
            if (after) { after(); }
            return null;
        }, function() {
            content.textContent = s('error_loading') + '.';
            return null;
        });
    }

    /**
     * Render a muted empty-state note into a pane.
     *
     * @param {String} paneid DOM id of the pane
     * @param {String} message Text to show
     */
    function renderNote(paneid, message) {
        renderPane(paneid, 'analytics_message', {danger: false, message: message});
    }

    /**
     * Build the stat-tile context the tab templates iterate over.
     *
     * @param {Array} pairs Array of [label, value] pairs
     * @returns {Array} Array of {label, value} objects
     */
    function statCards(pairs) {
        return pairs.map(function(pair) {
            return {label: pair[0], value: pair[1]};
        });
    }

    // ────────────────────────────────────────────────────────
    // Tab renderers
    // ────────────────────────────────────────────────────────

    function renderTab(tabId, data) {
        var renderers = {
            'overall': renderOverall,
            'bycourse': renderByCourse,
            'comparison': renderComparison,
            'byunit': renderByUnit,
            'usagetypes': renderUsageTypes,
            'themes': renderThemes,
            'feedback': renderFeedback,
        };
        if (renderers[tabId]) {
            renderers[tabId](data);
        }
    }

    // ── Tab 1: Overall Usage ──

    function renderOverall(data) {
        var enrollment = data.enrollment || {};
        var overview = data.overview || {};
        var sessions = data.sessions || {};
        var returnrate = data.return_rate || {};
        // The server nests these (see get_analytics_overall::execute):
        // enrollment, overview, sessions and return_rate are each their own
        // object. Reading them at the top level meant every one of the six
        // tiles resolved to undefined and rendered 0 -- including TOTAL
        // STUDENTS, which is a plain enrolment count, on courses with a
        // hundred participants. Same shape as the chart wiring fixed in
        // 7.2.3: the payload grew a level and the dashboard did not follow.
        var cards = statCards([
            [s('total_students'), enrollment.total_enrolled || 0],
            [s('active_ai_users'), overview.active_students || 0],
            [s('msgs_per_student'), overview.avg_messages_per_student || 0],
            [s('avg_session'), formatMinutes(sessions.avg_duration_minutes || 0)],
            [s('return_rate'), (returnrate.return_rate_pct || 0) + '%'],
            [s('total_sessions'), sessions.total_sessions || 0],
        ]);

        renderPane('sola-pane-overall', 'analytics_tab_overall', {cards: cards}, function() {
            drawOverallCharts(data);
        });
    }

    /**
     * Draw the three Overall-tab charts. Split out of renderOverall so it can
     * run once the template render has put the canvases in the document.
     *
     * @param {Object} data Service payload for the overall tab
     */
    function drawOverallCharts(data) {
        // Draw charts.
        if (data.daily_usage && data.daily_usage.length) {
            charts['daily'] = createChart('sola-chart-daily', 'line', {
                labels: data.daily_usage.map(function(d) { return d.date; }),
                datasets: [{
                    label: s('messages'),
                    data: data.daily_usage.map(function(d) { return d.count; }),
                    borderColor: COLORS[0],
                    backgroundColor: COLORS_ALPHA[0],
                    fill: true,
                    tension: 0.3,
                }],
            });
        }
        // The server sends these nested under time_distribution (see
        // get_analytics_overall::execute). Reading data.hourly / data.daily at
        // the top level meant both guards were always undefined, so these two
        // canvases were never handed to Chart.js at all -- the tab shipped one
        // chart and two bare headings, which read as "no data" rather than as a
        // wiring fault. data.daily here is day-of-week, not the daily trend;
        // that one is data.daily_usage.
        var dist = data.time_distribution || {};
        if (dist.hourly) {
            var hours = [];
            var hcounts = [];
            for (var h = 0; h < 24; h++) {
                hours.push(h + ':00');
                hcounts.push(dist.hourly[h] || 0);
            }
            charts['hourly'] = createChart('sola-chart-hourly', 'bar', {
                labels: hours,
                datasets: [{label: s('messages'), data: hcounts, backgroundColor: COLORS[1]}],
            });
        }
        if (dist.daily) {
            var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            var dcounts = days.map(function(d) { return dist.daily[d] || 0; });
            charts['dow'] = createChart('sola-chart-dow', 'bar', {
                labels: days,
                datasets: [{label: s('messages'), data: dcounts, backgroundColor: COLORS[2]}],
            });
        }
    }

    // ── Tab 2: By Course ──

    /**
     * Per-course metrics, whichever shape the service sends.
     *
     * get_analytics_by_course returns each course NESTED --
     * {fullname, shortname, overview:{...}, sessions:{...}, return_rate:{...}} --
     * while this table used to read flat keys (c.active_students,
     * c.return_rate_pct, c.coursename). Every read was undefined, so the tab
     * painted a course name from fullname beside five zeros on every row, for
     * every course, and looked exactly like a site with no activity. Other
     * callers in analytics.php do build a flat shape, so both are accepted.
     *
     * @param {Object} c one course row
     * @returns {Object} numeric metrics, zero-filled
     */
    function courseMetrics(c) {
        var ov = c.overview || {};
        var se = c.sessions || {};
        var rr = c.return_rate || {};
        var num = function (v) { return (typeof v === 'number' && isFinite(v)) ? v : (parseFloat(v) || 0); };
        return {
            active_students: num(ov.active_students !== undefined ? ov.active_students : c.active_students),
            total_messages: num(ov.total_messages !== undefined ? ov.total_messages : c.total_messages),
            avg_messages_per_student: num(ov.avg_messages_per_student !== undefined
                ? ov.avg_messages_per_student : c.avg_messages_per_student),
            return_rate_pct: num(rr.return_rate_pct !== undefined ? rr.return_rate_pct : c.return_rate_pct),
            avg_session_minutes: num(se.avg_duration_minutes !== undefined
                ? se.avg_duration_minutes
                : (c.avg_session_minutes !== undefined ? c.avg_session_minutes : se.avg_session_minutes))
        };
    }

    /**
     * Display name for a course row. The service sends fullname/shortname;
     * `coursename` was never one of its keys.
     *
     * @param {Object} c
     * @returns {String}
     */
    function courseLabel(c) {
        return c.fullname || c.shortname || c.coursename || '';
    }

    function renderByCourse(data) {
        var courses = Array.isArray(data) ? data : (data.courses || []);
        if (!courses.length) {
            renderNote('sola-pane-bycourse', s('no_course_data'));
            return;
        }
        var context = {
            chartheight: Math.max(80, courses.length * 25),
            str_course: s('course'),
            str_active: s('active_ai_users'),
            str_messages: s('messages'),
            str_permsg: s('msgs_per_student'),
            str_returnrate: s('return_rate'),
            str_avgsession: s('avg_session'),
            rows: courses.map(function(c) {
                var m = courseMetrics(c);
                return {
                    course: courseLabel(c),
                    active: m.active_students,
                    messages: m.total_messages,
                    permsg: m.avg_messages_per_student,
                    returnrate: m.return_rate_pct + '%',
                    avgsession: formatMinutes(m.avg_session_minutes),
                };
            }),
        };

        renderPane('sola-pane-bycourse', 'analytics_tab_bycourse', context, function() {
            charts['bycourse'] = createChart('sola-chart-bycourse', 'bar', {
                labels: courses.map(function(c) { return c.shortname || courseLabel(c); }),
                datasets: [{
                    label: s('messages'),
                    data: courses.map(function(c) { return courseMetrics(c).total_messages; }),
                    backgroundColor: COLORS[0],
                }],
            }, {indexAxis: 'y'});
        });
    }

    // ── Tab 3: AI vs Non-Users ──

    function renderComparison(data) {
        var ai = data.ai_users || {};
        var non = data.non_users || {};
        var context = {
            cards: statCards([
                [s('ai_users'), ai.count || 0],
                [s('non_users'), non.count || 0],
            ]),
            rows: [
                {metric: 'Avg Grade (%)', aival: fmt(ai.avg_grade), nonval: fmt(non.avg_grade)},
                {
                    metric: 'Completion Rate',
                    aival: fmt(ai.completion_rate) + '%',
                    nonval: fmt(non.completion_rate) + '%',
                },
                {
                    metric: 'Avg Days to Complete',
                    aival: fmt(ai.avg_days_to_completion),
                    nonval: fmt(non.avg_days_to_completion),
                },
            ],
        };

        renderPane('sola-pane-comparison', 'analytics_tab_comparison', context, function() {
            drawComparisonChart(ai, non);
        });
    }

    /**
     * Draw the AI vs non-user bar chart once its canvas is in the document.
     *
     * @param {Object} ai AI-user metrics
     * @param {Object} non Non-user metrics
     */
    function drawComparisonChart(ai, non) {
        charts['comparison'] = createChart('sola-chart-comparison', 'bar', {
            labels: ['Avg Grade (%)', 'Completion Rate (%)', 'Days to Complete'],
            datasets: [
                {label: s('ai_users'), data: [ai.avg_grade || 0, ai.completion_rate || 0, ai.avg_days_to_completion || 0], backgroundColor: COLORS[0]},
                {label: s('non_users'), data: [non.avg_grade || 0, non.completion_rate || 0, non.avg_days_to_completion || 0], backgroundColor: COLORS[4]},
            ],
        });
    }

    // ── Tab 4: By Unit ──

    function renderByUnit(data) {
        var units = Array.isArray(data) ? data : (data.units || []);
        if (!units.length) {
            renderNote('sola-pane-byunit', s('no_unit_data'));
            return;
        }
        var context = {
            chartheight: Math.max(80, units.length * 30),
            str_section: s('section'),
            str_active: s('active_ai_users'),
            str_messages: s('messages'),
            str_permsg: s('msgs_per_student'),
            rows: units.map(function(u) {
                return {
                    section: u.section_name,
                    students: u.student_count,
                    messages: u.message_count,
                    permsg: u.student_count > 0 ? (u.message_count / u.student_count).toFixed(1) : 0,
                };
            }),
        };

        renderPane('sola-pane-byunit', 'analytics_tab_byunit', context, function() {
            charts['byunit'] = createChart('sola-chart-byunit', 'bar', {
                labels: units.map(function(u) { return u.section_name; }),
                datasets: [
                    {
                        label: s('students'),
                        data: units.map(function(u) { return u.student_count; }),
                        backgroundColor: COLORS[0],
                    },
                    {
                        label: s('messages'),
                        data: units.map(function(u) { return u.message_count; }),
                        backgroundColor: COLORS[2],
                    },
                ],
            }, {indexAxis: 'y'});
        });
    }

    // ── Tab 5: Usage Types ──

    function renderUsageTypes(data) {
        var types = Array.isArray(data) ? data : (data.types || []);
        var context = {
            rows: types.map(function(t) {
                return {
                    name: formatTypeName(t.type),
                    count: t.count,
                    pct: (t.pct || 0).toFixed(1) + '%',
                };
            }),
        };

        renderPane('sola-pane-usagetypes', 'analytics_tab_usagetypes', context, function() {
            if (!types.length) { return; }
            charts['usagetypes'] = createChart('sola-chart-usagetypes', 'doughnut', {
                labels: types.map(function(t) { return formatTypeName(t.type); }),
                datasets: [{
                    data: types.map(function(t) { return t.count; }),
                    backgroundColor: COLORS.slice(0, types.length),
                }],
            });
        });
    }

    // ── Tab 6: Themes ──

    function renderThemes(data) {
        var keywords = Array.isArray(data) ? data : (data.keywords || []);
        if (!keywords.length) {
            renderNote('sola-pane-themes', s('no_keyword_data'));
            return;
        }
        var top20 = keywords.slice(0, 20);
        var context = {
            chartheight: Math.max(80, top20.length * 22),
            rows: keywords.map(function(k) {
                return {
                    keyword: k.keyword,
                    frequency: k.frequency,
                    category: k.category,
                    // One of three fixed values, never raw server data, so it
                    // is safe to interpolate into the badge class attribute.
                    badge: k.category === 'concept'
                        ? 'primary'
                        : (k.category === 'navigation' ? 'warning' : 'info'),
                };
            }),
        };

        renderPane('sola-pane-themes', 'analytics_tab_themes', context, function() {
            charts['themes'] = createChart('sola-chart-themes', 'bar', {
                labels: top20.map(function(k) { return k.keyword; }),
                datasets: [{
                    label: s('frequency'),
                    data: top20.map(function(k) { return k.frequency; }),
                    backgroundColor: COLORS[0],
                }],
            }, {indexAxis: 'y'});
        });
    }

    // ── Tab 7: Feedback ──

    function renderFeedback(data) {
        // The server sends rating_summary / survey_summary / messages_to_resolution
        // / negative_feedback (get_analytics_feedback::execute builds $result with
        // those keys). This read the four names below instead, so every lookup was
        // undefined and the tab painted six zero tiles, skipped both charts and
        // never emitted the negative-feedback table. Same defect class as the
        // By Course tab. Old names kept as a fallback.
        var ratings = data.rating_summary || data.ratings || {};
        var survey = data.survey_summary || data.survey || {};
        var resolution = data.messages_to_resolution || data.resolution || {};
        var negatives = data.negative_feedback || data.negatives || [];

        var hasratings = !!(ratings.thumbs_up || ratings.thumbs_down);
        var hasstars = !!survey.rating_distribution;

        var context = {
            cards: statCards([
                [s('thumbs_up'), ratings.thumbs_up || 0],
                [s('thumbs_down'), ratings.thumbs_down || 0],
                [s('hallucination_flags'), ratings.hallucination_flags || 0],
                [s('avg_star_rating'), fmt(survey.avg_star_rating || 0) + '/5'],
                [s('avg_msgs_resolution'), fmt(resolution.avg_messages || 0)],
                [s('survey_respondents'), survey.survey_respondents || 0],
            ]),
            hasratings: hasratings,
            hasstars: hasstars,
            hasnegatives: negatives.length > 0,
            // Both of these carry free text -- an excerpt of the AI reply and
            // the learner's own comment. Mustache escapes them; the string
            // build this replaces depended on esc() being called by hand at
            // every concatenation point.
            negatives: negatives.map(function(n) {
                return {
                    excerpt: n.message_excerpt || '',
                    comment: n.comment || '—',
                    hallucination: n.is_hallucination ? 'Yes' : 'No',
                    date: formatDate(n.timecreated),
                };
            }),
        };

        renderPane('sola-pane-feedback', 'analytics_tab_feedback', context, function() {
            if (hasratings) {
                charts['msgratings'] = createChart('sola-chart-msgratings', 'pie', {
                    labels: ['Thumbs Up', 'Thumbs Down'],
                    datasets: [{
                        data: [ratings.thumbs_up || 0, ratings.thumbs_down || 0],
                        backgroundColor: [COLORS[2], COLORS[4]],
                    }],
                });
            }
            if (hasstars) {
                var dist = survey.rating_distribution;
                charts['stars'] = createChart('sola-chart-stars', 'bar', {
                    labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                    datasets: [{
                        label: s('responses'),
                        data: [dist['1'] || 0, dist['2'] || 0, dist['3'] || 0, dist['4'] || 0, dist['5'] || 0],
                        backgroundColor: COLORS[0],
                    }],
                });
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart.js helpers
    // ────────────────────────────────────────────────────────

    function createChart(canvasId, type, data, extraOpts) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) { return null; }
        var opts = {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {legend: {display: type === 'pie' || type === 'doughnut'}},
        };
        if (extraOpts) {
            Object.keys(extraOpts).forEach(function(k) { opts[k] = extraOpts[k]; });
        }
        return new Chart(canvas, {type: type, data: data, options: opts});
    }

    // ────────────────────────────────────────────────────────
    // Utility helpers
    // ────────────────────────────────────────────────────────

    // statCard(), compRow() and esc() lived here. They existed only to build
    // HTML strings for innerHTML; the tab templates do that markup now and
    // Mustache does the escaping, so all three are gone (CONTRIB-10574 #278).

    function fmt(val) {
        if (val === null || val === undefined) { return '—'; }
        var num = parseFloat(val);
        return isNaN(num) ? val : num.toFixed(1);
    }

    function formatMinutes(mins) {
        var m = parseFloat(mins);
        if (isNaN(m) || m === 0) { return '—'; }
        if (m < 1) { return '<1 min'; }
        if (m >= 60) { return Math.floor(m / 60) + 'h ' + Math.round(m % 60) + 'm'; }
        return Math.round(m) + ' min';
    }

    function formatTypeName(type) {
        var map = {
            'chat': 'Chat',
            'voice': 'Voice',
            'quiz': 'Practice Quiz',
            'practice_conversation': 'Conversation Practice',
            'practice_pronunciation': 'Pronunciation Practice',
        };
        return map[type] || type;
    }

    function formatDate(ts) {
        if (!ts) { return '—'; }
        var d = new Date(ts * 1000);
        return d.toLocaleDateString();
    }

    return {init: init};
});
