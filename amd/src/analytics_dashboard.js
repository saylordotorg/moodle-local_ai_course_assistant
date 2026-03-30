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

define(['core/ajax'], function(Ajax) {

    var config = {};
    var cache = {};
    var charts = {};
    var activeTab = 'overall';

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
            pane.querySelector('.sola-analytics-content').innerHTML =
                '<div class="alert alert-danger">Error loading data: ' + (err.message || err) + '</div>';
        });
    }

    function showLoading(pane) {
        var content = pane.querySelector('.sola-analytics-content');
        if (content) {
            content.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading analytics...</p></div>';
        }
    }

    function hideLoading(pane) {
        var spinner = pane.querySelector('.spinner-border');
        if (spinner) { spinner.remove(); }
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
        var pane = document.getElementById('sola-pane-overall');
        if (!pane) { return; }
        var html = '<div class="sola-stat-cards">' +
            statCard('Total Students', data.total_enrolled || 0, 'users') +
            statCard('Active AI Users', data.active_students || 0, 'chat') +
            statCard('Msgs / Student', data.avg_messages_per_student || 0, 'message') +
            statCard('Avg Session', formatMinutes(data.avg_session_minutes || 0), 'clock') +
            statCard('Return Rate', (data.return_rate_pct || 0) + '%', 'return') +
            statCard('Total Sessions', data.total_sessions || 0, 'sessions') +
            '</div>';
        html += '<div class="row mt-4">';
        html += '<div class="col-md-12 mb-4"><h5>Daily Usage Trend</h5><canvas id="sola-chart-daily" height="80"></canvas></div>';
        html += '</div><div class="row">';
        html += '<div class="col-md-6 mb-4"><h5>Hour of Day</h5><canvas id="sola-chart-hourly" height="120"></canvas></div>';
        html += '<div class="col-md-6 mb-4"><h5>Day of Week</h5><canvas id="sola-chart-dow" height="120"></canvas></div>';
        html += '</div>';

        pane.querySelector('.sola-analytics-content').innerHTML = html;

        // Draw charts.
        if (data.daily_usage && data.daily_usage.length) {
            charts['daily'] = createChart('sola-chart-daily', 'line', {
                labels: data.daily_usage.map(function(d) { return d.date; }),
                datasets: [{
                    label: 'Messages',
                    data: data.daily_usage.map(function(d) { return d.count; }),
                    borderColor: COLORS[0],
                    backgroundColor: COLORS_ALPHA[0],
                    fill: true,
                    tension: 0.3,
                }],
            });
        }
        if (data.hourly) {
            var hours = [];
            var hcounts = [];
            for (var h = 0; h < 24; h++) {
                hours.push(h + ':00');
                hcounts.push(data.hourly[h] || 0);
            }
            charts['hourly'] = createChart('sola-chart-hourly', 'bar', {
                labels: hours,
                datasets: [{label: 'Messages', data: hcounts, backgroundColor: COLORS[1]}],
            });
        }
        if (data.daily) {
            var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            var dcounts = days.map(function(d) { return data.daily[d] || 0; });
            charts['dow'] = createChart('sola-chart-dow', 'bar', {
                labels: days,
                datasets: [{label: 'Messages', data: dcounts, backgroundColor: COLORS[2]}],
            });
        }
    }

    // ── Tab 2: By Course ──

    function renderByCourse(data) {
        var pane = document.getElementById('sola-pane-bycourse');
        if (!pane) { return; }
        var courses = Array.isArray(data) ? data : (data.courses || []);
        if (!courses.length) {
            pane.querySelector('.sola-analytics-content').innerHTML = '<p class="text-muted">No course data available.</p>';
            return;
        }
        var html = '<div class="row mb-4"><div class="col-12"><canvas id="sola-chart-bycourse" height="' + Math.max(80, courses.length * 25) + '"></canvas></div></div>';
        html += '<table class="table table-sm table-striped sola-analytics-table"><thead><tr>' +
            '<th>Course</th><th>Students</th><th>Messages</th><th>Msgs/Student</th><th>Return Rate</th><th>Avg Session</th>' +
            '</tr></thead><tbody>';
        courses.forEach(function(c) {
            html += '<tr><td>' + esc(c.coursename || c.fullname || '') + '</td>' +
                '<td>' + (c.active_students || 0) + '</td>' +
                '<td>' + (c.total_messages || 0) + '</td>' +
                '<td>' + (c.avg_messages_per_student || 0) + '</td>' +
                '<td>' + (c.return_rate_pct || 0) + '%</td>' +
                '<td>' + formatMinutes(c.avg_session_minutes || 0) + '</td></tr>';
        });
        html += '</tbody></table>';
        pane.querySelector('.sola-analytics-content').innerHTML = html;

        charts['bycourse'] = createChart('sola-chart-bycourse', 'bar', {
            labels: courses.map(function(c) { return c.coursename || c.shortname || ''; }),
            datasets: [{label: 'Messages', data: courses.map(function(c) { return c.total_messages || 0; }), backgroundColor: COLORS[0]}],
        }, {indexAxis: 'y'});
    }

    // ── Tab 3: AI vs Non-Users ──

    function renderComparison(data) {
        var pane = document.getElementById('sola-pane-comparison');
        if (!pane) { return; }
        var ai = data.ai_users || {};
        var non = data.non_users || {};
        var html = '<div class="sola-stat-cards">' +
            statCard('AI Users', ai.count || 0, 'users') +
            statCard('Non-Users', non.count || 0, 'users') +
            '</div>';
        html += '<div class="row mt-4"><div class="col-md-8"><canvas id="sola-chart-comparison" height="120"></canvas></div></div>';
        html += '<table class="table table-sm mt-4"><thead><tr><th>Metric</th><th>AI Users</th><th>Non-Users</th></tr></thead><tbody>';
        html += compRow('Avg Grade (%)', fmt(ai.avg_grade), fmt(non.avg_grade));
        html += compRow('Completion Rate', fmt(ai.completion_rate) + '%', fmt(non.completion_rate) + '%');
        html += compRow('Avg Days to Complete', fmt(ai.avg_days_to_completion), fmt(non.avg_days_to_completion));
        html += '</tbody></table>';
        pane.querySelector('.sola-analytics-content').innerHTML = html;

        charts['comparison'] = createChart('sola-chart-comparison', 'bar', {
            labels: ['Avg Grade (%)', 'Completion Rate (%)', 'Days to Complete'],
            datasets: [
                {label: 'AI Users', data: [ai.avg_grade || 0, ai.completion_rate || 0, ai.avg_days_to_completion || 0], backgroundColor: COLORS[0]},
                {label: 'Non-Users', data: [non.avg_grade || 0, non.completion_rate || 0, non.avg_days_to_completion || 0], backgroundColor: COLORS[4]},
            ],
        });
    }

    // ── Tab 4: By Unit ──

    function renderByUnit(data) {
        var pane = document.getElementById('sola-pane-byunit');
        if (!pane) { return; }
        var units = Array.isArray(data) ? data : (data.units || []);
        if (!units.length) {
            pane.querySelector('.sola-analytics-content').innerHTML = '<p class="text-muted">No unit data yet. Unit tracking began when v3.4.0 was installed — data will accumulate as students use SOLA.</p>';
            return;
        }
        var html = '<div class="row mb-4"><div class="col-12"><canvas id="sola-chart-byunit" height="' + Math.max(80, units.length * 30) + '"></canvas></div></div>';
        html += '<table class="table table-sm table-striped"><thead><tr><th>Section</th><th>Students</th><th>Messages</th><th>Msgs/Student</th></tr></thead><tbody>';
        units.forEach(function(u) {
            var avg = u.student_count > 0 ? (u.message_count / u.student_count).toFixed(1) : 0;
            html += '<tr><td>' + esc(u.section_name) + '</td><td>' + u.student_count + '</td><td>' + u.message_count + '</td><td>' + avg + '</td></tr>';
        });
        html += '</tbody></table>';
        pane.querySelector('.sola-analytics-content').innerHTML = html;

        charts['byunit'] = createChart('sola-chart-byunit', 'bar', {
            labels: units.map(function(u) { return u.section_name; }),
            datasets: [
                {label: 'Students', data: units.map(function(u) { return u.student_count; }), backgroundColor: COLORS[0]},
                {label: 'Messages', data: units.map(function(u) { return u.message_count; }), backgroundColor: COLORS[2]},
            ],
        }, {indexAxis: 'y'});
    }

    // ── Tab 5: Usage Types ──

    function renderUsageTypes(data) {
        var pane = document.getElementById('sola-pane-usagetypes');
        if (!pane) { return; }
        var types = Array.isArray(data) ? data : (data.types || []);
        var html = '<div class="row"><div class="col-md-6"><canvas id="sola-chart-usagetypes" height="200"></canvas></div>';
        html += '<div class="col-md-6"><table class="table table-sm mt-3"><thead><tr><th>Type</th><th>Count</th><th>%</th></tr></thead><tbody>';
        types.forEach(function(t) {
            html += '<tr><td>' + esc(formatTypeName(t.type)) + '</td><td>' + t.count + '</td><td>' + (t.pct || 0).toFixed(1) + '%</td></tr>';
        });
        html += '</tbody></table></div></div>';
        pane.querySelector('.sola-analytics-content').innerHTML = html;

        if (types.length) {
            charts['usagetypes'] = createChart('sola-chart-usagetypes', 'doughnut', {
                labels: types.map(function(t) { return formatTypeName(t.type); }),
                datasets: [{data: types.map(function(t) { return t.count; }), backgroundColor: COLORS.slice(0, types.length)}],
            });
        }
    }

    // ── Tab 6: Themes ──

    function renderThemes(data) {
        var pane = document.getElementById('sola-pane-themes');
        if (!pane) { return; }
        var keywords = Array.isArray(data) ? data : (data.keywords || []);
        if (!keywords.length) {
            pane.querySelector('.sola-analytics-content').innerHTML = '<p class="text-muted">No keyword data available for this period.</p>';
            return;
        }
        var top20 = keywords.slice(0, 20);
        var html = '<div class="row mb-4"><div class="col-12"><canvas id="sola-chart-themes" height="' + Math.max(80, top20.length * 22) + '"></canvas></div></div>';
        html += '<table class="table table-sm table-striped"><thead><tr><th>Keyword</th><th>Frequency</th><th>Category</th></tr></thead><tbody>';
        keywords.forEach(function(k) {
            var badge = k.category === 'concept' ? 'primary' : (k.category === 'navigation' ? 'warning' : 'info');
            html += '<tr><td>' + esc(k.keyword) + '</td><td>' + k.frequency + '</td><td><span class="badge badge-' + badge + ' bg-' + badge + '">' + esc(k.category) + '</span></td></tr>';
        });
        html += '</tbody></table>';
        pane.querySelector('.sola-analytics-content').innerHTML = html;

        charts['themes'] = createChart('sola-chart-themes', 'bar', {
            labels: top20.map(function(k) { return k.keyword; }),
            datasets: [{label: 'Frequency', data: top20.map(function(k) { return k.frequency; }), backgroundColor: COLORS[0]}],
        }, {indexAxis: 'y'});
    }

    // ── Tab 7: Feedback ──

    function renderFeedback(data) {
        var pane = document.getElementById('sola-pane-feedback');
        if (!pane) { return; }
        var ratings = data.ratings || {};
        var survey = data.survey || {};
        var resolution = data.resolution || {};
        var negatives = data.negatives || [];

        var html = '<div class="sola-stat-cards">' +
            statCard('Thumbs Up', ratings.thumbs_up || 0, 'up') +
            statCard('Thumbs Down', ratings.thumbs_down || 0, 'down') +
            statCard('Hallucination Flags', ratings.hallucination_flags || 0, 'flag') +
            statCard('Avg Star Rating', fmt(survey.avg_star_rating || 0) + '/5', 'star') +
            statCard('Avg Msgs to Resolution', fmt(resolution.avg_messages || 0), 'resolve') +
            statCard('Survey Respondents', survey.survey_respondents || 0, 'survey') +
            '</div>';

        html += '<div class="row mt-4">';
        if (ratings.thumbs_up || ratings.thumbs_down) {
            html += '<div class="col-md-4"><h5>Message Ratings</h5><canvas id="sola-chart-msgratings" height="200"></canvas></div>';
        }
        if (survey.rating_distribution) {
            html += '<div class="col-md-4"><h5>Star Rating Distribution</h5><canvas id="sola-chart-stars" height="200"></canvas></div>';
        }
        html += '</div>';

        if (negatives.length) {
            html += '<h5 class="mt-4">Recent Negative Feedback</h5>';
            html += '<table class="table table-sm table-striped"><thead><tr><th>Message Excerpt</th><th>Comment</th><th>Hallucination?</th><th>Date</th></tr></thead><tbody>';
            negatives.forEach(function(n) {
                html += '<tr><td>' + esc(n.message_excerpt || '') + '</td><td>' + esc(n.comment || '—') + '</td>' +
                    '<td>' + (n.is_hallucination ? 'Yes' : 'No') + '</td>' +
                    '<td>' + formatDate(n.timecreated) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        pane.querySelector('.sola-analytics-content').innerHTML = html;

        if (ratings.thumbs_up || ratings.thumbs_down) {
            charts['msgratings'] = createChart('sola-chart-msgratings', 'pie', {
                labels: ['Thumbs Up', 'Thumbs Down'],
                datasets: [{data: [ratings.thumbs_up || 0, ratings.thumbs_down || 0], backgroundColor: [COLORS[2], COLORS[4]]}],
            });
        }
        if (survey.rating_distribution) {
            var dist = survey.rating_distribution;
            charts['stars'] = createChart('sola-chart-stars', 'bar', {
                labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
                datasets: [{label: 'Responses', data: [dist['1'] || 0, dist['2'] || 0, dist['3'] || 0, dist['4'] || 0, dist['5'] || 0], backgroundColor: COLORS[0]}],
            });
        }
    }

    // ────────────────────────────────────────────────────────
    // Chart.js helpers
    // ────────────────────────────────────────────────────────

    function createChart(canvasId, type, data, extraOpts) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') { return null; }
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

    function statCard(label, value, icon) {
        return '<div class="sola-stat-card"><div class="sola-stat-value">' + value + '</div><div class="sola-stat-label">' + label + '</div></div>';
    }

    function compRow(label, aiVal, nonVal) {
        return '<tr><td>' + label + '</td><td>' + aiVal + '</td><td>' + nonVal + '</td></tr>';
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

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
