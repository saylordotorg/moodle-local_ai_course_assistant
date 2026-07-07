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

namespace local_ai_course_assistant\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\rubric_manager;
use local_ai_course_assistant\feature_flags;

/**
 * Soapbox: score a transcribed speech against the per-course speech rubric and
 * return per-criterion scores + overall feedback, and persist the result to the
 * learner's speech history (no audio or transcript text is stored). Non-
 * streaming; called from soapbox.php after STT produces the transcript.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_speech extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT, 'Course ID'),
            'transcript' => new external_value(PARAM_RAW, 'STT transcript of the speech'),
            'name'       => new external_value(PARAM_TEXT, 'Learner-chosen speech name', VALUE_DEFAULT, ''),
            'topic'      => new external_value(PARAM_TEXT, 'Learner-chosen topic', VALUE_DEFAULT, ''),
            'targetsec'  => new external_value(PARAM_INT, 'Target speech length in seconds (0 = none)', VALUE_DEFAULT, 0),
            'durationsec' => new external_value(PARAM_INT, 'Actual recorded duration in seconds', VALUE_DEFAULT, 0),
            'mode'       => new external_value(PARAM_ALPHA, 'Presentation type: informative | persuasive', VALUE_DEFAULT, 'informative'),
        ]);
    }

    /**
     * @param int $courseid
     * @param string $transcript
     * @param string $name
     * @param string $topic
     * @param int $targetsec
     * @param int $durationsec
     * @param string $mode
     * @return array
     */
    public static function execute(int $courseid, string $transcript, string $name = '',
            string $topic = '', int $targetsec = 0, int $durationsec = 0, string $mode = 'informative'): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'transcript' => $transcript, 'name' => $name,
            'topic' => $topic, 'targetsec' => $targetsec, 'durationsec' => $durationsec, 'mode' => $mode,
        ]);
        $courseid = (int) $params['courseid'];
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        if (!feature_flags::resolve('soapbox', $courseid)) {
            return self::empty_result('disabled');
        }

        $speech = trim($params['transcript']);
        if (mb_strlen($speech) < 40) {
            return self::empty_result('too_short');
        }
        if (mb_strlen($speech) > 40000) {
            $speech = mb_substr($speech, 0, 40000);
        }
        $name = trim($params['name']);
        $topic = trim($params['topic']);
        $targetsec = max(0, (int) $params['targetsec']);
        $durationsec = max(0, (int) $params['durationsec']);

        // Per-course course-type/level (general speech vs ESL beginner/advanced).
        // Drives both the fallback rubric and the coaching register in the prompt.
        $level = (string) (get_config('local_ai_course_assistant', 'soapbox_level_course_' . $courseid)
            ?: rubric_manager::SPEECH_LEVEL_GENERAL);
        $preset = rubric_manager::speech_preset($level);

        // Resolve the per-course speech rubric. An explicit course/global rubric
        // wins; otherwise fall back to the level preset's sample criteria.
        // get_active_rubric() returns the criteria already decoded to an array.
        $rubric = rubric_manager::get_active_rubric($courseid, rubric_manager::TYPE_SPEECH);
        $criteriadefs = ($rubric && is_array($rubric->criteria)) ? $rubric->criteria : $preset['criteria'];
        if (!is_array($criteriadefs) || empty($criteriadefs)) {
            $criteriadefs = $preset['criteria'];
        }
        $maxscore = 5;
        $rubriclines = [];
        foreach ($criteriadefs as $c) {
            $cn = (string) ($c['name'] ?? '');
            $cd = (string) ($c['description'] ?? '');
            $cm = (int) ($c['max_score'] ?? 5);
            if ($cm > 0) {
                $maxscore = $cm;
            }
            $rubriclines[] = "- {$cn}: {$cd} (0-{$cm})";
        }
        $rubrictext = implode("\n", $rubriclines);

        $contextline = (string) ($preset['hint'] ?? 'The learner is practising a spoken presentation.');
        if ($topic !== '') {
            $contextline .= ' Their stated topic is: "' . mb_substr($topic, 0, 300) . '".';
        }
        if ($targetsec > 0) {
            $mins = round($targetsec / 60, 1);
            $contextline .= " Their target length is about {$mins} minute(s)";
            if ($durationsec > 0) {
                $actualmin = round($durationsec / 60, 1);
                $contextline .= "; they actually spoke for about {$actualmin} minute(s)";
            }
            $contextline .= '.';
        }

        // Informative vs persuasive changes what "good" looks like; the mode hint
        // tells the coach which skills to weight (clear explanation and accuracy
        // vs a claim backed by evidence, appeals, and a call to action). Orthogonal
        // to the ESL level and the rubric criteria.
        $mode = strtolower((string) $params['mode']);
        $contextline .= self::mode_hint($mode);

        $sysprompt = "You are a supportive public-speaking coach giving formative feedback on a learner's spoken "
            . "presentation. You are reading a speech-to-text transcript, so ignore transcription artefacts "
            . "(missing punctuation, homophone errors, '[inaudible]') and do not penalise them. {$contextline} "
            . "Score each rubric criterion from 0 (not evident) to {$maxscore} (strong mastery). For each criterion give "
            . "one or two sentences of concrete, encouraging feedback naming a specific strength and the single "
            . "highest-leverage improvement. Then give a short overall comment and three concrete next-time tips ordered "
            . "by impact. Be encouraging; this is practice. Respond with JSON only, in this shape:\n"
            . '{"criteria":[{"name":"...","score":3,"feedback":"..."}], "overall":"...", "tips":["...","...","..."]}'
            . "\n\nRUBRIC:\n{$rubrictext}\n\nTRANSCRIPT:\n{$speech}";

        try {
            $provider = base_provider::create_from_config($courseid);
            $response = $provider->chat_completion(
                $sysprompt,
                [['role' => 'user', 'content' => 'Produce the feedback JSON now.']],
                [
                    'response_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'criteria' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name'     => ['type' => 'string'],
                                        'score'    => ['type' => 'integer'],
                                        'feedback' => ['type' => 'string'],
                                    ],
                                    'required' => ['name', 'score', 'feedback'],
                                ],
                            ],
                            'overall' => ['type' => 'string'],
                            'tips'    => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['criteria', 'overall', 'tips'],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return self::empty_result('provider_error');
        }

        $decoded = json_decode($response, true);
        if (!$decoded || !isset($decoded['criteria'])) {
            if (preg_match('/\{[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }
        if (!$decoded || !isset($decoded['criteria']) || !is_array($decoded['criteria'])) {
            return self::empty_result('parse_error');
        }

        $criteria = [];
        $sum = 0;
        foreach ($decoded['criteria'] as $c) {
            $sc = (int) ($c['score'] ?? 0);
            $sum += $sc;
            $criteria[] = [
                'name'     => (string) ($c['name'] ?? ''),
                'score'    => $sc,
                'feedback' => (string) ($c['feedback'] ?? ''),
            ];
        }
        $overall = (string) ($decoded['overall'] ?? '');
        $tips = array_map('strval', (array) ($decoded['tips'] ?? []));

        // Persist to the learner's speech history. We store the scores, feedback,
        // duration, and a meta blob with the name/topic/target — never the audio
        // or the transcript text.
        $scoreid = 0;
        try {
            $rubricid = $rubric ? (int) $rubric->id : 0;
            $meta = ['name' => $name, 'topic' => $topic, 'target' => $targetsec, 'mode' => $mode, 'tips' => $tips];
            $scoreid = rubric_manager::save_score(
                $rubricid, (int) $USER->id, $courseid, rubric_manager::TYPE_SPEECH,
                $criteria, (int) round($sum / max(1, count($criteria))), $overall, $durationsec, $meta
            );
        } catch (\Throwable $e) {
            // History persistence is best-effort; still return the feedback.
            $scoreid = 0;
        }

        return [
            'success'  => true,
            'message'  => 'ok',
            'criteria' => $criteria,
            'overall'  => $overall,
            'tips'     => $tips,
            'scoreid'  => $scoreid,
        ];
    }

    /**
     * Coaching-register overlay for the learner-chosen presentation type.
     *
     * Pure so the informative/persuasive prompt shaping is unit-testable. Returns
     * a leading-space sentence appended to the coach context, or '' for an
     * unknown mode (falls back to the neutral, level-only coaching).
     *
     * @param string $mode 'informative' | 'persuasive' (case-insensitive).
     * @return string
     */
    public static function mode_hint(string $mode): string {
        switch (strtolower(trim($mode))) {
            case 'persuasive':
                return ' This is a PERSUASIVE presentation: the learner is trying to convince the audience. '
                    . 'Weight your feedback toward a clear position or claim, the strength of evidence and reasoning, '
                    . 'use of rhetorical appeals (credibility, logic, and emotion), acknowledging counter-arguments, '
                    . 'and a clear call to action. Reward a well-supported argument over neutral description.';
            case 'informative':
                return ' This is an INFORMATIVE presentation: the learner is explaining or teaching. '
                    . 'Weight your feedback toward clarity of explanation, accuracy, logical organization, coverage '
                    . 'of the topic, and helping the audience understand. Do not expect a persuasive thesis or a '
                    . 'call to action.';
            default:
                return '';
        }
    }

    /**
     * @param string $code
     * @return array
     */
    private static function empty_result(string $code): array {
        return [
            'success'  => false,
            'message'  => $code,
            'criteria' => [],
            'overall'  => '',
            'tips'     => [],
            'scoreid'  => 0,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'  => new external_value(PARAM_BOOL, 'Whether feedback was produced'),
            'message'  => new external_value(PARAM_ALPHAEXT, 'Status code'),
            'criteria' => new external_multiple_structure(
                new external_single_structure([
                    'name'     => new external_value(PARAM_RAW, 'Criterion name'),
                    'score'    => new external_value(PARAM_INT, 'Score'),
                    'feedback' => new external_value(PARAM_RAW, 'Feedback text'),
                ])
            ),
            'overall'  => new external_value(PARAM_RAW, 'Overall comment'),
            'tips'     => new external_multiple_structure(new external_value(PARAM_RAW, 'Next-time tip')),
            'scoreid'  => new external_value(PARAM_INT, 'Saved history row id (0 if not saved)'),
        ]);
    }
}
