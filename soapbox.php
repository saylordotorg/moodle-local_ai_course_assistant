<?php
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
 * Soapbox — learner speech-practice page. The student (optionally) names their
 * speech, picks a topic and a target time, records a longer audio clip, which
 * is transcribed (server Whisper or free in-browser speech recognition), and
 * gets rubric-based AI feedback. Score history is kept per learner; the audio
 * and transcript are never stored.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\feature_flags;
use local_ai_course_assistant\rubric_manager;
use local_ai_course_assistant\security;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

if (!feature_flags::resolve('soapbox', $courseid)) {
    throw new \moodle_exception('soapbox:disabled', 'local_ai_course_assistant');
}

$pageurl = new moodle_url('/local/ai_course_assistant/soapbox.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('soapbox:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

security::send_security_headers(true);

// server = Whisper via voice_registry (self-hosted free, else hosted); browser =
// in-browser Web Speech API (free, no server, Chrome/Safari).
$sttmode = get_config('local_ai_course_assistant', 'soapbox_stt_mode') ?: 'server';
$transcribeurl = (new moodle_url('/local/ai_course_assistant/soapbox_transcribe.php'))->out(false);
$ajaxurl = (new moodle_url('/lib/ajax/service.php', ['sesskey' => sesskey()]))->out(false);

$history = rubric_manager::get_user_scores($USER->id, $courseid, rubric_manager::TYPE_SPEECH, 20);

echo $OUTPUT->header();
?>
<div style="max-width:880px;margin:0 auto" class="aica-soapbox" data-stt-mode="<?php echo s($sttmode); ?>">
    <h2>📣 <?php echo get_string('soapbox:title', 'local_ai_course_assistant'); ?></h2>
    <p class="text-muted"><?php echo get_string('soapbox:intro', 'local_ai_course_assistant'); ?></p>

    <div class="card mb-3"><div class="card-body">
        <div class="form-group">
            <label for="sb-name"><strong><?php echo get_string('soapbox:name_label', 'local_ai_course_assistant'); ?></strong>
                <span class="text-muted">(<?php echo get_string('soapbox:optional', 'local_ai_course_assistant'); ?>)</span></label>
            <input type="text" id="sb-name" class="form-control" maxlength="120">
        </div>
        <div class="form-group mt-2">
            <label for="sb-topic"><strong><?php echo get_string('soapbox:topic_label', 'local_ai_course_assistant'); ?></strong>
                <span class="text-muted">(<?php echo get_string('soapbox:optional', 'local_ai_course_assistant'); ?>)</span></label>
            <input type="text" id="sb-topic" class="form-control" maxlength="200">
        </div>
        <div class="form-group mt-2">
            <label for="sb-target"><strong><?php echo get_string('soapbox:time_label', 'local_ai_course_assistant'); ?></strong></label>
            <select id="sb-target" class="form-control" style="max-width:220px">
                <option value="0"><?php echo get_string('soapbox:no_target', 'local_ai_course_assistant'); ?></option>
                <option value="60">1 min</option>
                <option value="120">2 min</option>
                <option value="180" selected>3 min</option>
                <option value="300">5 min</option>
                <option value="420">7 min</option>
                <option value="600">10 min</option>
            </select>
        </div>
        <div class="form-group mt-2">
            <label for="sb-mode"><strong><?php echo get_string('soapbox:mode_label', 'local_ai_course_assistant'); ?></strong></label>
            <select id="sb-mode" class="form-control" style="max-width:280px">
                <option value="informative"><?php echo get_string('soapbox:mode_informative', 'local_ai_course_assistant'); ?></option>
                <option value="persuasive"><?php echo get_string('soapbox:mode_persuasive', 'local_ai_course_assistant'); ?></option>
            </select>
        </div>

        <div class="mt-3 d-flex align-items-center" style="gap:12px;flex-wrap:wrap">
            <button type="button" id="sb-record" class="btn btn-primary">
                ● <?php echo get_string('soapbox:record', 'local_ai_course_assistant'); ?>
            </button>
            <span id="sb-timer" style="font-family:monospace;font-size:18px">00:00</span>
            <span id="sb-status" class="text-muted"></span>
        </div>
        <p class="text-muted small mt-2" id="sb-mode-note"></p>
    </div></div>

    <div id="sb-result" style="display:none" class="mb-4"></div>

    <h3><?php echo get_string('soapbox:history_heading', 'local_ai_course_assistant'); ?></h3>
    <div id="sb-history">
    <?php if (empty($history)) { ?>
        <p class="text-muted" id="sb-history-empty"><?php echo get_string('soapbox:history_empty', 'local_ai_course_assistant'); ?></p>
    <?php } else {
        foreach ($history as $h) {
            $meta = json_decode($h->session_meta ?? '', true) ?: [];
            $hname = trim((string) ($meta['name'] ?? '')) ?: get_string('soapbox:untitled', 'local_ai_course_assistant');
            $htopic = trim((string) ($meta['topic'] ?? ''));
            $dur = (int) ($h->session_duration ?? 0);
            $durtxt = sprintf('%d:%02d', intdiv($dur, 60), $dur % 60);
            ?>
        <details class="card mb-2"><summary class="card-header" style="cursor:pointer">
            <strong><?php echo s($hname); ?></strong>
            <?php if ($htopic !== '') { ?><span class="text-muted"> — <?php echo s($htopic); ?></span><?php } ?>
            <span class="badge badge-info ml-2"><?php echo get_string('soapbox:overall_badge', 'local_ai_course_assistant', (int) $h->overall_score); ?></span>
            <span class="text-muted ml-2 small"><?php echo userdate((int) $h->timecreated, get_string('strftimedatetimeshort', 'langconfig')); ?> · <?php echo $durtxt; ?></span>
        </summary><div class="card-body">
            <table class="generaltable" style="width:100%"><tbody>
            <?php foreach ((array) $h->scores as $c) { ?>
                <tr><td style="width:30%"><?php echo s($c['name'] ?? ''); ?></td>
                    <td style="width:70px;font-family:monospace"><?php echo (int) ($c['score'] ?? 0); ?></td>
                    <td><?php echo s($c['feedback'] ?? ''); ?></td></tr>
            <?php } ?>
            </tbody></table>
            <?php if (!empty($h->ai_feedback)) { ?><p><?php echo s($h->ai_feedback); ?></p><?php } ?>
        </div></details>
    <?php } } ?>
    </div>
</div>

<script>
(function() {
    var root = document.querySelector('.aica-soapbox');
    var mode = root ? root.getAttribute('data-stt-mode') : 'server';
    var TRANSCRIBE_URL = <?php echo json_encode($transcribeurl); ?>;
    var AJAX_URL = <?php echo json_encode($ajaxurl); ?>;
    var COURSEID = <?php echo (int) $courseid; ?>;
    var recordBtn = document.getElementById('sb-record');
    var timerEl = document.getElementById('sb-timer');
    var statusEl = document.getElementById('sb-status');
    var resultEl = document.getElementById('sb-result');
    var modeNote = document.getElementById('sb-mode-note');

    var STR = {
        recording: <?php echo json_encode(get_string('soapbox:recording', 'local_ai_course_assistant')); ?>,
        record: <?php echo json_encode('● ' . get_string('soapbox:record', 'local_ai_course_assistant')); ?>,
        stop: <?php echo json_encode('■ ' . get_string('soapbox:stop', 'local_ai_course_assistant')); ?>,
        transcribing: <?php echo json_encode(get_string('soapbox:transcribing', 'local_ai_course_assistant')); ?>,
        scoring: <?php echo json_encode(get_string('soapbox:scoring', 'local_ai_course_assistant')); ?>,
        too_short: <?php echo json_encode(get_string('soapbox:too_short', 'local_ai_course_assistant')); ?>,
        mic_denied: <?php echo json_encode(get_string('soapbox:mic_denied', 'local_ai_course_assistant')); ?>,
        no_browser_stt: <?php echo json_encode(get_string('soapbox:no_browser_stt', 'local_ai_course_assistant')); ?>,
        browser_note: <?php echo json_encode(get_string('soapbox:browser_note', 'local_ai_course_assistant')); ?>,
        server_note: <?php echo json_encode(get_string('soapbox:server_note', 'local_ai_course_assistant')); ?>,
        error: <?php echo json_encode(get_string('soapbox:error', 'local_ai_course_assistant')); ?>,
        err_provider: <?php echo json_encode(get_string('soapbox:err_provider', 'local_ai_course_assistant')); ?>,
        err_parse: <?php echo json_encode(get_string('soapbox:err_parse', 'local_ai_course_assistant')); ?>,
        err_disabled: <?php echo json_encode(get_string('soapbox:err_disabled', 'local_ai_course_assistant')); ?>,
        err_transcribe: <?php echo json_encode(get_string('soapbox:err_transcribe', 'local_ai_course_assistant')); ?>,
        result_heading: <?php echo json_encode(get_string('soapbox:result_heading', 'local_ai_course_assistant')); ?>,
        col_criterion: <?php echo json_encode(get_string('soapbox:col_criterion', 'local_ai_course_assistant')); ?>,
        col_score: <?php echo json_encode(get_string('soapbox:col_score', 'local_ai_course_assistant')); ?>,
        col_feedback: <?php echo json_encode(get_string('soapbox:col_feedback', 'local_ai_course_assistant')); ?>,
        overall_heading: <?php echo json_encode(get_string('soapbox:overall_heading', 'local_ai_course_assistant')); ?>,
        tips_heading: <?php echo json_encode(get_string('soapbox:tips_heading', 'local_ai_course_assistant')); ?>
    };
    var esc = function(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    // Map a server status code to a friendly learner-facing message. Unknown
    // codes fall back to the generic error with the raw code kept for diagnosis.
    var errMsg = function(code) {
        var map = {
            too_short: STR.too_short, provider_error: STR.err_provider,
            parse_error: STR.err_parse, disabled: STR.err_disabled
        };
        return map[code] || (STR.error + ' (' + code + ')');
    };
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (mode === 'browser') { modeNote.textContent = STR.browser_note; } else { modeNote.textContent = STR.server_note; }

    var recording = false, startTs = 0, timerInt = null, mediaRec = null, chunks = [],
        recognizer = null, browserTranscript = '';

    var fmt = function(s) { return ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2); };
    var tick = function() { timerEl.textContent = fmt(Math.floor((Date.now() - startTs) / 1000)); };
    var setBusy = function(txt) { statusEl.textContent = txt || ''; };

    var finishWithTranscript = function(transcript, durationSec) {
        if (!transcript || transcript.trim().length < 40) {
            setBusy(''); resultEl.style.display = 'block';
            resultEl.innerHTML = '<div class="alert alert-warning">' + esc(STR.too_short) + '</div>';
            recordBtn.disabled = false; return;
        }
        setBusy(STR.scoring);
        var payload = JSON.stringify([{
            index: 0, methodname: 'local_ai_course_assistant_score_speech',
            args: {
                courseid: COURSEID, transcript: transcript,
                name: (document.getElementById('sb-name').value || ''),
                topic: (document.getElementById('sb-topic').value || ''),
                targetsec: parseInt(document.getElementById('sb-target').value, 10) || 0,
                durationsec: durationSec || 0,
                mode: (document.getElementById('sb-mode').value || 'informative')
            }
        }]);
        fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: payload })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            recordBtn.disabled = false; setBusy('');
            var res = resp && resp[0] && resp[0].data;
            resultEl.style.display = 'block';
            if (!res || !res.success) {
                resultEl.innerHTML = '<div class="alert alert-warning">' +
                    esc(errMsg(res && res.message ? res.message : 'unknown')) + '</div>';
                return;
            }
            var html = '<div class="card"><div class="card-body"><h3>' + esc(STR.result_heading) + '</h3>';
            html += '<table class="generaltable" style="width:100%"><thead><tr><th>' + esc(STR.col_criterion) +
                '</th><th style="width:80px">' + esc(STR.col_score) + '</th><th>' + esc(STR.col_feedback) + '</th></tr></thead><tbody>';
            res.criteria.forEach(function(c) {
                html += '<tr><td>' + esc(c.name) + '</td><td style="font-family:monospace">' + esc(c.score) +
                    '</td><td>' + esc(c.feedback) + '</td></tr>';
            });
            html += '</tbody></table>';
            if (res.overall) { html += '<h4 class="mt-3">' + esc(STR.overall_heading) + '</h4><p>' + esc(res.overall) + '</p>'; }
            if (res.tips && res.tips.length) {
                html += '<h4 class="mt-2">' + esc(STR.tips_heading) + '</h4><ol>';
                res.tips.forEach(function(t) { html += '<li>' + esc(t) + '</li>'; });
                html += '</ol>';
            }
            html += '</div></div>';
            resultEl.innerHTML = html;
            var empty = document.getElementById('sb-history-empty');
            if (empty) { empty.style.display = 'none'; }
        })
        .catch(function() {
            recordBtn.disabled = false; setBusy('');
            resultEl.style.display = 'block';
            resultEl.innerHTML = '<div class="alert alert-danger">' + esc(STR.error) + '</div>';
        });
    };

    // ---- Server mode: MediaRecorder -> upload blob -> soapbox_transcribe.php ----
    var startServer = function() {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            chunks = [];
            var mime = '';
            ['audio/webm', 'audio/mp4', 'audio/ogg'].some(function(m) {
                if (window.MediaRecorder && MediaRecorder.isTypeSupported(m)) { mime = m; return true; } return false;
            });
            mediaRec = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
            mediaRec.ondataavailable = function(e) { if (e.data && e.data.size) { chunks.push(e.data); } };
            mediaRec.onstop = function() {
                stream.getTracks().forEach(function(t) { t.stop(); });
                var durationSec = Math.floor((Date.now() - startTs) / 1000);
                var blob = new Blob(chunks, { type: mediaRec.mimeType || 'audio/webm' });
                setBusy(STR.transcribing);
                var fd = new FormData();
                fd.append('audio', blob, 'speech.' + ((mediaRec.mimeType || '').indexOf('mp4') !== -1 ? 'mp4' : 'webm'));
                fd.append('sesskey', '<?php echo sesskey(); ?>');
                fd.append('courseid', String(COURSEID));
                fetch(TRANSCRIBE_URL, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
                    .then(function(o) {
                        if (!o.ok || !o.j || !o.j.text) {
                            recordBtn.disabled = false; setBusy('');
                            resultEl.style.display = 'block';
                            var raw = o.j && o.j.error ? o.j.error : 'transcription';
                            resultEl.innerHTML = '<div class="alert alert-warning">' + esc(STR.err_transcribe) +
                                ' <code>' + esc(raw) + '</code></div>';
                            return;
                        }
                        finishWithTranscript(o.j.text, durationSec);
                    })
                    .catch(function() {
                        recordBtn.disabled = false; setBusy('');
                        resultEl.style.display = 'block';
                        resultEl.innerHTML = '<div class="alert alert-danger">' + esc(STR.error) + '</div>';
                    });
            };
            mediaRec.start();
            beginUI();
        }).catch(function() { alert(STR.mic_denied); });
    };
    var stopServer = function() { if (mediaRec && mediaRec.state !== 'inactive') { mediaRec.stop(); } endUI(); };

    // ---- Browser mode: Web Speech API (free, no server) ----
    var startBrowser = function() {
        if (!SR) { alert(STR.no_browser_stt); return; }
        browserTranscript = '';
        recognizer = new SR();
        recognizer.continuous = true; recognizer.interimResults = false;
        recognizer.lang = document.documentElement.lang || 'en-US';
        recognizer.onresult = function(e) {
            for (var i = e.resultIndex; i < e.results.length; i++) {
                if (e.results[i].isFinal) { browserTranscript += e.results[i][0].transcript + ' '; }
            }
        };
        recognizer.onerror = function(ev) { if (ev.error === 'not-allowed') { alert(STR.mic_denied); } };
        recognizer.onend = function() { if (recording) { try { recognizer.start(); } catch (x) {} } }; // keep going on auto-stop
        try { recognizer.start(); } catch (x) {}
        beginUI();
    };
    var stopBrowser = function() {
        var durationSec = Math.floor((Date.now() - startTs) / 1000);
        endUI();
        if (recognizer) { try { recognizer.stop(); } catch (x) {} }
        setTimeout(function() { finishWithTranscript(browserTranscript, durationSec); }, 400);
    };

    var beginUI = function() {
        recording = true; startTs = Date.now(); timerEl.textContent = '00:00';
        timerInt = setInterval(tick, 1000);
        recordBtn.textContent = STR.stop; recordBtn.classList.remove('btn-primary'); recordBtn.classList.add('btn-danger');
        setBusy(STR.recording); resultEl.style.display = 'none';
    };
    var endUI = function() {
        recording = false; clearInterval(timerInt);
        recordBtn.textContent = STR.record; recordBtn.classList.remove('btn-danger'); recordBtn.classList.add('btn-primary');
        recordBtn.disabled = true;
    };

    recordBtn.addEventListener('click', function() {
        if (!recording) {
            if (mode === 'browser') { startBrowser(); } else { startServer(); }
        } else {
            if (mode === 'browser') { stopBrowser(); } else { stopServer(); }
        }
    });
})();
</script>
<?php
echo $OUTPUT->footer();
