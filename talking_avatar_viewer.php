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
 * Static viewer page iframed by the SOLA widget for providers whose
 * streaming flow needs a client-side WebRTC / LiveKit handshake (D-ID,
 * HeyGen). Tavus and Synthesia return iframable URLs of their own and
 * skip this file entirely.
 *
 * Avatar SDK loading is intentionally out-of-scope for this page — the
 * SDK is loaded from the operator's CDN bundle (cdn/entry-bundle.js) when
 * configured, and the page falls back to a "configure CDN bundle" notice
 * otherwise. This keeps the plugin free of bundled vendor JS.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$provider = required_param('provider', PARAM_ALPHA);
$sid = optional_param('sid', '', PARAM_ALPHANUMEXT);
$tok = optional_param('tok', '', PARAM_ALPHANUMEXT);
$lang = optional_param('lang', 'en', PARAM_ALPHA);

// v5.10.x (security finding #39): a session id in the URL is not enough to
// prove the requester owns the session. Verify the avatar session belongs to
// the current user before exposing its stream, so a leaked or guessed
// (sid, tok) pair cannot be replayed by a different logged-in user.
if ($sid !== '' && !\local_ai_course_assistant\talking_avatar_session_manager::user_owns_session(
        (int) $USER->id, $provider, $sid)) {
    throw new \moodle_exception('nopermissions', 'error', '',
        get_string('talking_avatar:viewer_title', 'local_ai_course_assistant'));
}

\local_ai_course_assistant\security::send_security_headers();

$PAGE->set_url(new moodle_url('/local/ai_course_assistant/talking_avatar_viewer.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('talking_avatar:viewer_title', 'local_ai_course_assistant'));

$bundleurl = (string) (get_config('local_ai_course_assistant', 'cdn_bundle_url') ?: '');
$config = [
    'provider' => $provider,
    'sessionId' => $sid,
    'sessionToken' => $tok,
    'lang' => $lang,
    'bundleUrl' => $bundleurl,
];

echo $OUTPUT->header();
?>
<div id="sola-avatar-stage" class="sola-avatar-stage" data-provider="<?php echo s($provider); ?>">
  <video id="sola-avatar-video" autoplay playsinline></video>
  <div id="sola-avatar-fallback" class="sola-avatar-fallback" hidden>
    <p><?php echo get_string('talking_avatar:bundle_required', 'local_ai_course_assistant'); ?></p>
  </div>
</div>
<script>
(function () {
  window.SOLA_AVATAR = <?php echo json_encode($config, JSON_THROW_ON_ERROR); ?>;
  var bundle = <?php echo json_encode($bundleurl); ?>;
  if (!bundle) {
    document.getElementById('sola-avatar-fallback').hidden = false;
    return;
  }
  var s = document.createElement('script');
  s.src = bundle;
  s.async = true;
  s.onerror = function () {
    document.getElementById('sola-avatar-fallback').hidden = false;
  };
  document.head.appendChild(s);
}());
</script>
<?php
echo $OUTPUT->footer();
