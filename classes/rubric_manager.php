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

namespace local_ai_course_assistant;

/**
 * Rubric manager — handles practice scoring rubric CRUD and score storage.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_manager {
    /** @var string Table name for rubrics. */
    private const TABLE_RUBRICS = 'local_ai_course_assistant_rubrics';

    /** @var string Table name for practice scores. */
    private const TABLE_SCORES = 'local_ai_course_assistant_practice_scores';

    /** @var string Rubric/session type for Soapbox speech practice. */
    const TYPE_SPEECH = 'speech';

    /**
     * Rubric type for a Soapbox VIDEO presentation: the spoken criteria plus the
     * two visual ones.
     *
     * This is a rubric TYPE key and has nothing to do with soapbox_config::MODES,
     * which is the recording mode ('video' or 'audio'). They share a word and
     * mean different things: an attempt recorded in video mode is scored against
     * a rubric of type video, but a video-mode attempt whose camera produced
     * nothing usable is still scored against that rubric with the visual criteria
     * dropped. The rubrics table's `type` column is char(20), so this fits with
     * no schema change.
     */
    const TYPE_VIDEO = 'video';

    /** @var string Soapbox course-type/level presets (drive both the default rubric and the coaching register). */
    const SPEECH_LEVEL_GENERAL = 'general';
    const SPEECH_LEVEL_ESL_BEGINNER = 'esl_beginner';
    const SPEECH_LEVEL_ESL_INTERMEDIATE = 'esl_intermediate';
    const SPEECH_LEVEL_ESL_ADVANCED = 'esl_advanced';

    /** @var array Default Soapbox speech rubric criteria (general speech / presentation course). */
    const DEFAULT_SPEECH_CRITERIA = [
        ['name' => 'Delivery & Fluency', 'description' => 'Pace, clarity, confidence, and smoothness of speaking.', 'max_score' => 5],
        ['name' => 'Structure & Organization', 'description' => 'Clear opening, logical flow of ideas, and a strong close.', 'max_score' => 5],
        ['name' => 'Content & Relevance', 'description' => 'Ideas stay on topic and are well supported and developed.', 'max_score' => 5],
        ['name' => 'Language & Vocabulary', 'description' => 'Word choice, grammar, and varied, precise language.', 'max_score' => 5],
        ['name' => 'Time Management', 'description' => 'Fits the target length without rushing or running long.', 'max_score' => 5],
    ];

    /**
     * The two visual criteria, appended to the spoken rubric only when the
     * attempt actually produced usable video.
     *
     * The 'visual' flag decides whether a criterion is put in the PROMPT at all.
     * It deliberately does NOT decide what is allowed into the score: that is
     * governed by an allowlist built from the rubric text actually sent, in
     * score_speech. The flag lives in hand-editable JSON and can simply be
     * absent from a criterion an admin added on rubric_admin.php, so trusting it
     * for the score would let an audio-only attempt be marked on body language
     * inferred from a transcript.
     *
     * Criterion text is English seed data, not lang strings, matching every
     * other DEFAULT_* array here: admins edit this wording on rubric_admin.php.
     */
    const VISUAL_CRITERIA = [
        [
            'name' => 'Body Language & Gestures',
            'description' => 'Gestures, posture and movement that SUPPORT what you are saying: '
                . 'open hands that mark structure or add emphasis, a steady stance, and weight '
                . 'that stays settled. No repeated habits that DISTRACT from the presentation '
                . '(fidgeting, rocking or pacing, hands in pockets or folded, playing with an '
                . 'object, hair or clothing).',
            'max_score' => 5,
            'visual' => true,
        ],
        [
            'name' => 'Eye Contact & Camera Presence',
            'description' => 'Looking at the camera lens as if it were your audience, rather than '
                . 'reading from notes or watching a second screen. Steady head-and-shoulders '
                . 'framing that keeps your hands in view, a face lit well enough to read, and '
                . 'facial expression that matches what you are saying.',
            'max_score' => 5,
            'visual' => true,
        ],
    ];

    /**
     * The full default video rubric: the five spoken criteria then the two visual ones.
     *
     * A method rather than a constant because PHP does not allow a function call
     * in a const initializer, and spelling the seven entries out again would
     * duplicate the speech five and drift from them.
     *
     * @return array
     */
    public static function default_video_criteria(): array {
        return array_merge(self::DEFAULT_SPEECH_CRITERIA, self::VISUAL_CRITERIA);
    }

    /** @var array Default conversation practice rubric criteria. */
    const DEFAULT_CONVERSATION_CRITERIA = [
        [
            'name' => 'Fluency & Coherence',
            'description' => 'How smoothly and logically did the student express ideas?',
            'max_score' => 5,
        ],
        [
            'name' => 'Grammar & Sentence Structure',
            'description' => 'How accurately did the student use grammar?',
            'max_score' => 5,
        ],
        [
            'name' => 'Vocabulary Range',
            'description' => 'How varied and appropriate was the student\'s word choice?',
            'max_score' => 5,
        ],
        [
            'name' => 'Comprehension',
            'description' => 'How well did the student understand and respond to prompts?',
            'max_score' => 5,
        ],
        [
            'name' => 'Engagement & Initiative',
            'description' => 'How actively did the student participate and ask questions?',
            'max_score' => 5,
        ],
    ];

    /** @var array Default pronunciation practice rubric criteria. */
    const DEFAULT_PRONUNCIATION_CRITERIA = [
        [
            'name' => 'Sound Accuracy',
            'description' => 'How correctly did the student produce individual sounds?',
            'max_score' => 5,
        ],
        [
            'name' => 'Stress & Rhythm',
            'description' => 'How well did the student use word and sentence stress?',
            'max_score' => 5,
        ],
        [
            'name' => 'Intonation',
            'description' => 'How natural was the student\'s pitch pattern?',
            'max_score' => 5,
        ],
        [
            'name' => 'Clarity',
            'description' => 'How easily could a native speaker understand the student?',
            'max_score' => 5,
        ],
    ];

    /**
     * Resolve the speech criteria for a course, honouring its ESL level.
     *
     * A course-scoped rubric is always a deliberate authoring act, so it wins.
     * A GLOBAL (courseid = 0) speech rubric may be auto-seeded boilerplate --
     * ensure_default_rubrics() creates one carrying the general criteria, and it
     * is reached from get_rubric, which any learner starting conversation or
     * pronunciation practice in any course on the site can trigger. Letting that
     * row win silently replaced a course's ESL criteria with the general set
     * from the moment anyone, anywhere, touched an unrelated feature. The level
     * select kept displaying "ESL (beginner)" and the coaching prose still
     * sounded ESL-aware, so nothing surfaced the swap.
     *
     * Nothing is destroyed by that -- the preset is shadowed, not deleted -- so
     * this resolution restores the intended behavior with no data migration.
     *
     * @param int $courseid
     * @param string $level One of the SPEECH_LEVEL_* constants.
     * @return array{criteria: array, rubricid: int} rubricid 0 when the preset was used
     */
    public static function resolve_speech_criteria(int $courseid, string $level): array {
        $preset = self::speech_preset($level);
        $rubric = self::get_active_rubric($courseid, self::TYPE_SPEECH);

        $iscoursescoped = $rubric && (int) $rubric->courseid === $courseid && $courseid > 0;
        $isgenerallevel = ($level === self::SPEECH_LEVEL_GENERAL);
        $explicit = $rubric && ($iscoursescoped || $isgenerallevel);

        if ($explicit && is_array($rubric->criteria) && !empty($rubric->criteria)) {
            return ['criteria' => $rubric->criteria, 'rubricid' => (int) $rubric->id];
        }
        return ['criteria' => $preset['criteria'], 'rubricid' => 0];
    }

    /**
     * Resolve the criteria for a Soapbox VIDEO attempt.
     *
     * The overlay rule, and why it is not the obvious one: a GLOBAL video rubric
     * never replaces the spoken base set, it only contributes its visual
     * criteria. resolve_speech_criteria()'s docblock records what happens
     * otherwise -- an auto-seeded global row silently outranks a course's
     * configured ESL level, swapping the course's criteria for the general set,
     * invisibly, because nothing in the UI shows which rubric won. Only a
     * COURSE-scoped video rubric, which is a deliberate authoring act, wins
     * outright.
     *
     * That is what keeps every hand-authored ESL speech rubric and every level
     * preset working untouched: they come through resolve_speech_criteria() as
     * the base, and only gain two rows on top.
     *
     * @param int    $courseid
     * @param string $level     Speaking level preset key.
     * @param bool   $hasvisual Whether this attempt produced usable visual evidence.
     * @return array{criteria: array, rubricid: int, visualnames: string[]}
     */
    public static function resolve_video_criteria(int $courseid, string $level, bool $hasvisual): array {
        $rubric = self::get_active_rubric($courseid, self::TYPE_VIDEO);
        $iscoursescoped = $rubric && (int) $rubric->courseid === $courseid && $courseid > 0;

        if ($iscoursescoped && is_array($rubric->criteria) && !empty($rubric->criteria)) {
            $criteria = $rubric->criteria;
            $rubricid = (int) $rubric->id;
        } else {
            $base = self::resolve_speech_criteria($courseid, $level);
            // A global video rubric contributes only its visual-flagged rows.
            $visual = self::VISUAL_CRITERIA;
            if ($rubric && is_array($rubric->criteria) && !empty($rubric->criteria)) {
                $fromglobal = array_values(array_filter(
                    $rubric->criteria,
                    static fn($c) => !empty($c['visual'])
                ));
                if (!empty($fromglobal)) {
                    $visual = $fromglobal;
                }
            }
            $criteria = array_merge($base['criteria'], $visual);
            $rubricid = (int) $base['rubricid'];
        }

        // The names are returned whether or not they survive the filter: the
        // caller needs them to decide what to put in the prompt and what to
        // allow into the score.
        $visualnames = array_values(array_map(
            static fn($c) => (string) ($c['name'] ?? ''),
            array_filter($criteria, static fn($c) => !empty($c['visual']))
        ));

        if (!$hasvisual) {
            $criteria = array_values(array_filter($criteria, static fn($c) => empty($c['visual'])));
        }

        return ['criteria' => $criteria, 'rubricid' => $rubricid, 'visualnames' => $visualnames];
    }

    /**
     * The video counterpart of speech_preset(): the level's spoken criteria plus
     * the visual ones.
     *
     * NOT REACHED IN v7.5.1, and deliberately so. This was written for a video
     * tab on rubric_admin.php that did not land: that page clamps an unknown
     * ?type back to 'conversation' and its tab strip is hard-coded to three
     * types, so nothing in the product can create a rubric row with
     * type = 'video'. Live behaviour is therefore always resolve_speech_criteria()
     * plus the hard-coded VISUAL_CRITERIA -- correct, but not admin-editable.
     *
     * Kept rather than deleted because resolve_video_criteria() already reads a
     * course-scoped video rubric when one exists, so this is the other half of a
     * mechanism that is finished apart from its UI. Whoever wires that UI up:
     * rubric_admin's criteria-cleaning loop rebuilds each entry from name,
     * description and max_score only, so it will silently drop the 'visual' flag
     * on the first Save and quietly turn an audio-only attempt into one marked
     * on body language inferred from a transcript.
     *
     * @param string $level
     * @return array{label_key: string, hint: string, criteria: array}
     */
    public static function video_preset(string $level): array {
        $preset = self::speech_preset($level);
        return [
            'label_key' => $preset['label_key'],
            'hint' => $preset['hint'],
            'criteria' => array_merge($preset['criteria'], self::VISUAL_CRITERIA),
        ];
    }


    /**
     * Whether one scored criterion counts toward the learner's score.
     *
     * The single implementation of the "assessed" contract. Every reader calls
     * this: compute_overall() below, the outcome gate in score_speech, the
     * stored-score table in soapbox.php and the learner's attempt list in
     * soapbox_present.php. Before it was extracted there were four hand-written
     * copies and three different answers for the values 0, "0" and "", so the
     * score counted a criterion all three renderers had greyed out.
     *
     * The rule is the strict one, deliberately. A criterion is excluded ONLY on
     * an explicit boolean false. An absent key counts, which is every score
     * written before v7.5.1 and every provider that ignores the field; null
     * counts; any other falsy value counts. The score is the authority and must
     * not move, and greying a row is the cheaper thing to get wrong.
     *
     * One site is deliberately looser and is not this one: score_speech coerces
     * untrusted provider JSON into a real boolean once, at the API boundary,
     * before anything stores or returns it. Everything downstream reads that
     * boolean through here.
     *
     * Pure: no globals, no database.
     *
     * @param mixed $criterion One entry of a scores array.
     * @return bool True when the criterion counts toward the score.
     */
    public static function is_assessed($criterion): bool {
        if (!is_array($criterion)) {
            // A malformed row is not a reason to take a score off anyone, and
            // it matches what compute_overall() did before the extraction.
            return true;
        }
        return ($criterion['assessed'] ?? null) !== false;
    }

    /**
     * Overall score over the criteria that were actually assessed.
     *
     * Extracted from the inline expression in score_speech so the exclusion
     * arithmetic is unit-testable without a provider, and so there is one place
     * a Soapbox total is computed rather than two that can drift.
     *
     * Three rules, each of which protects a learner who has nobody to appeal to:
     *
     *  - An entry is excluded only on an EXPLICIT `assessed === false`, which is
     *    is_assessed() above. A row with no `assessed` key counts as assessed,
     *    which is every score written before v7.5.1 and every provider that
     *    ignores the field. Do not re-inline this test: the renderers call the
     *    same helper so a total and the table printing it cannot disagree.
     *  - Excluded entries are left out of the sum AND out of maxtotal, so they
     *    neither add zero to the numerator nor inflate the denominator.
     *  - Zero assessed criteria yields overall 0 and pct 0 by an explicit
     *    branch, not by max(1, $n). A fabricated denominator would turn "nothing
     *    could be judged" into a real-looking low score.
     *
     * Pure: no globals, no database.
     *
     * @param array $scoredcriteria Entries of ['name', 'score', 'feedback', 'assessed'].
     * @return array{overall: int, assessed: int, maxtotal: int, pct: int}
     */
    public static function compute_overall(array $scoredcriteria): array {
        $sum = 0;
        $assessed = 0;
        $maxtotal = 0;

        foreach ($scoredcriteria as $c) {
            if (!self::is_assessed($c)) {
                continue;
            }
            $sum += (int) ($c['score'] ?? 0);
            $maxtotal += (int) ($c['max_score'] ?? 5);
            $assessed++;
        }

        return [
            'overall' => $assessed > 0 ? (int) round($sum / $assessed) : 0,
            'assessed' => $assessed,
            'maxtotal' => $maxtotal,
            'pct' => $maxtotal > 0 ? (int) round(100 * $sum / $maxtotal) : 0,
        ];
    }

    /**
     * Get the active rubric for a course and type.
     *
     * Checks for a course-level rubric first, then falls back to the global default (courseid=0).
     *
     * @param int $courseid
     * @param string $type 'conversation' or 'pronunciation'
     * @return object|null Rubric record with decoded criteria, or null if none found.
     */
    public static function get_active_rubric(int $courseid, string $type): ?object {
        global $DB;

        // Try course-specific first.
        $rubric = $DB->get_record(self::TABLE_RUBRICS, [
            'courseid' => $courseid,
            'type' => $type,
            'active' => 1,
        ]);

        // Fall back to global default.
        if (!$rubric) {
            $rubric = $DB->get_record(self::TABLE_RUBRICS, [
                'courseid' => 0,
                'type' => $type,
                'active' => 1,
            ]);
        }

        if (!$rubric) {
            return null;
        }

        $rubric->criteria = json_decode($rubric->criteria, true);
        return $rubric;
    }

    /**
     * Create a rubric.
     *
     * Deactivates any other active rubric for the same courseid and type first.
     *
     * @param int $courseid 0 for global default, or a specific course ID.
     * @param string $type 'conversation' or 'pronunciation'.
     * @param string $title Rubric title.
     * @param array $criteria Array of criterion definitions.
     * @return int The new rubric ID.
     */
    public static function create_rubric(int $courseid, string $type, string $title, array $criteria): int {
        global $DB;

        $now = time();

        // Deactivate any existing active rubric for this scope and type.
        $DB->set_field(self::TABLE_RUBRICS, 'active', 0, [
            'courseid' => $courseid,
            'type' => $type,
            'active' => 1,
        ]);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->type = $type;
        $record->title = $title;
        $record->criteria = json_encode($criteria);
        $record->active = 1;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record(self::TABLE_RUBRICS, $record);
    }

    /**
     * Update an existing rubric.
     *
     * @param int $rubricid
     * @param string $title
     * @param array $criteria
     * @param bool $active
     */
    public static function update_rubric(int $rubricid, string $title, array $criteria, bool $active): void {
        global $DB;

        $record = $DB->get_record(self::TABLE_RUBRICS, ['id' => $rubricid], '*', MUST_EXIST);

        // If activating this rubric, deactivate others for the same scope and type.
        if ($active) {
            $DB->set_field(self::TABLE_RUBRICS, 'active', 0, [
                'courseid' => $record->courseid,
                'type' => $record->type,
                'active' => 1,
            ]);
        }

        $record->title = $title;
        $record->criteria = json_encode($criteria);
        $record->active = $active ? 1 : 0;
        $record->timemodified = time();

        $DB->update_record(self::TABLE_RUBRICS, $record);
    }

    /**
     * Ensure the global default rubrics exist.
     *
     * Creates both conversation and pronunciation defaults if no active global rubric
     * is found for each type.
     */
    public static function ensure_default_rubrics(): void {
        global $DB;

        $conversationexists = $DB->record_exists(self::TABLE_RUBRICS, [
            'courseid' => 0,
            'type' => 'conversation',
            'active' => 1,
        ]);

        if (!$conversationexists) {
            self::create_rubric(0, 'conversation', 'Conversation Practice Rubric', self::DEFAULT_CONVERSATION_CRITERIA);
        }

        $pronunciationexists = $DB->record_exists(self::TABLE_RUBRICS, [
            'courseid' => 0,
            'type' => 'pronunciation',
            'active' => 1,
        ]);

        if (!$pronunciationexists) {
            self::create_rubric(0, 'pronunciation', 'Pronunciation Practice Rubric', self::DEFAULT_PRONUNCIATION_CRITERIA);
        }

        $speechexists = $DB->record_exists(self::TABLE_RUBRICS, [
            'courseid' => 0,
            'type' => self::TYPE_SPEECH,
            'active' => 1,
        ]);

        if (!$speechexists) {
            self::create_rubric(0, self::TYPE_SPEECH, 'Soapbox Speech Rubric', self::DEFAULT_SPEECH_CRITERIA);
        }
    }

    /**
     * Save a practice score.
     *
     * @param int $rubricid
     * @param int $userid
     * @param int $courseid
     * @param string $sessiontype 'conversation', 'pronunciation', or 'speech'
     * @param array $scores Array of per-criterion scores [{name, score, feedback}, ...]
     * @param int $overallscore Overall score for the session.
     * @param string $aifeedback AI-generated feedback text.
     * @param int $duration Session duration in seconds.
     * @param array|null $meta Optional metadata blob (e.g. Soapbox name/topic/target); JSON-encoded. Never audio/transcript.
     * @return int The new score record ID.
     */
    public static function save_score(
        int $rubricid,
        int $userid,
        int $courseid,
        string $sessiontype,
        array $scores,
        int $overallscore,
        string $aifeedback,
        int $duration,
        ?array $meta = null
    ): int {
        global $DB;

        $record = new \stdClass();
        $record->rubricid = $rubricid;
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->session_type = $sessiontype;
        $record->scores = json_encode($scores);
        $record->overall_score = $overallscore;
        $record->ai_feedback = $aifeedback;
        $record->session_duration = $duration;
        $record->session_meta = ($meta !== null) ? json_encode($meta) : null;
        $record->timecreated = time();

        return $DB->insert_record(self::TABLE_SCORES, $record);
    }

    /**
     * Get the active rubric for a given course and type (alias used by admin page).
     *
     * @param int $courseid
     * @param string $type
     * @return object|null
     */
    public static function get_rubric(int $courseid, string $type): ?object {
        return self::get_active_rubric($courseid, $type);
    }

    /**
     * Delete rubric(s) for a given course and type.
     *
     * @param int $courseid
     * @param string $type
     */
    public static function delete_rubric(int $courseid, string $type): void {
        global $DB;
        $DB->delete_records(self::TABLE_RUBRICS, [
            'courseid' => $courseid,
            'type' => $type,
        ]);
    }

    /**
     * Ensure the default rubric exists for a single type.
     *
     * @param string $type 'conversation' or 'pronunciation'
     */
    public static function ensure_default_rubric(string $type): void {
        global $DB;
        $exists = $DB->record_exists(self::TABLE_RUBRICS, [
            'courseid' => 0,
            'type' => $type,
            'active' => 1,
        ]);
        if (!$exists) {
            $criteria = self::get_default_criteria($type);
            $titles = [
                'pronunciation' => 'Pronunciation Practice Rubric',
                self::TYPE_SPEECH => 'Soapbox Speech Rubric',
                self::TYPE_VIDEO => 'Soapbox Video Rubric',
            ];
            $title = $titles[$type] ?? 'Conversation Practice Rubric';
            self::create_rubric(0, $type, $title, $criteria);
        }
    }

    /**
     * Get the built-in default criteria for a type.
     *
     * @param string $type
     * @return array
     */
    public static function get_default_criteria(string $type): array {
        if ($type === 'pronunciation') {
            return self::DEFAULT_PRONUNCIATION_CRITERIA;
        }
        if ($type === self::TYPE_VIDEO) {
            return self::default_video_criteria();
        }
        if ($type === self::TYPE_SPEECH) {
            return self::DEFAULT_SPEECH_CRITERIA;
        }
        return self::DEFAULT_CONVERSATION_CRITERIA;
    }

    /**
     * Soapbox course-type/level presets. Each preset bundles a sample rubric
     * (criteria) with a coaching `hint` injected into the scoring prompt so the
     * AI adapts its register: ESL levels get language-learning feedback, while
     * General Speech focuses on presentation skills. Used as the fallback rubric
     * when a course has no custom speech rubric, and offered as loadable samples
     * on the rubric admin page. Criteria text is English seed data (admins edit
     * it on rubric_admin.php); the `label` is shown in UI pickers.
     *
     * @return array<string, array{label_key:string, hint:string, criteria:array}>
     */
    public static function speech_presets(): array {
        return [
            self::SPEECH_LEVEL_GENERAL => [
                'label_key' => 'soapbox:level_general',
                'hint' => 'The learner is practising a general spoken presentation; focus your feedback on '
                    . 'presentation and public-speaking skills.',
                'criteria' => self::DEFAULT_SPEECH_CRITERIA,
            ],
            self::SPEECH_LEVEL_ESL_BEGINNER => [
                'label_key' => 'soapbox:level_esl_beginner',
                'hint' => 'The learner is a beginner-level English-as-a-second-language student. Prioritise '
                    . 'intelligibility and communication over native-like accuracy. Use simple, clear language in '
                    . 'your feedback, warmly praise successful communication, and give one small, concrete '
                    . 'improvement per criterion. Do not penalise a noticeable accent.',
                'criteria' => [
                    ['name' => 'Pronunciation & Intelligibility', 'description' => 'Sounds, word stress, and being understood by a patient listener.', 'max_score' => 5],
                    ['name' => 'Fluency & Pace', 'description' => 'Speaking in connected phrases without long pauses or heavy hesitation.', 'max_score' => 5],
                    ['name' => 'Basic Grammar', 'description' => 'Simple tenses, subject-verb agreement, and word order.', 'max_score' => 5],
                    ['name' => 'Core Vocabulary', 'description' => 'Using common, topic-relevant words and getting meaning across despite gaps.', 'max_score' => 5],
                    ['name' => 'Task Completion', 'description' => 'Staying on topic and saying enough on the prompt to be understood.', 'max_score' => 5],
                ],
            ],
            self::SPEECH_LEVEL_ESL_INTERMEDIATE => [
                'label_key' => 'soapbox:level_esl_intermediate',
                'hint' => 'The learner is an intermediate-level English-as-a-second-language student. Balance '
                    . 'encouragement with targeted correction: acknowledge what works, then name a couple of '
                    . 'concrete, level-appropriate improvements (a grammar pattern, a clearer transition, a more '
                    . 'precise word). Stretch them slightly beyond their comfort without overwhelming them.',
                'criteria' => [
                    ['name' => 'Pronunciation & Clarity', 'description' => 'Mostly clear sounds and stress; occasional slips that rarely block understanding.', 'max_score' => 5],
                    ['name' => 'Fluency & Pace', 'description' => 'Reasonably smooth with some hesitation; keeps going through most ideas.', 'max_score' => 5],
                    ['name' => 'Grammar', 'description' => 'Common tenses and structures handled with some errors in more complex forms.', 'max_score' => 5],
                    ['name' => 'Vocabulary', 'description' => 'Adequate range for the topic, with some reach for less common or precise words.', 'max_score' => 5],
                    ['name' => 'Organization & Coherence', 'description' => 'Clear main idea, mostly logical ordering, and basic connectors.', 'max_score' => 5],
                ],
            ],
            self::SPEECH_LEVEL_ESL_ADVANCED => [
                'label_key' => 'soapbox:level_esl_advanced',
                'hint' => 'The learner is an advanced English-as-a-second-language student. Hold them to a high '
                    . 'standard of fluency, range, and accuracy while staying encouraging. Note subtle errors in '
                    . 'idiom, register, and complex grammar, and push for more natural, native-like phrasing.',
                'criteria' => [
                    ['name' => 'Fluency & Naturalness', 'description' => 'Smooth pace, natural rhythm, and self-correction that does not disrupt flow.', 'max_score' => 5],
                    ['name' => 'Pronunciation & Stress', 'description' => 'Clear sounds plus sentence stress and intonation that carry meaning.', 'max_score' => 5],
                    ['name' => 'Grammatical Range & Accuracy', 'description' => 'Varied, complex structures used accurately.', 'max_score' => 5],
                    ['name' => 'Vocabulary & Idiom', 'description' => 'Precise, varied, idiomatic word choice and appropriate register.', 'max_score' => 5],
                    ['name' => 'Coherence & Development', 'description' => 'Well-organized ideas with connectors and full development.', 'max_score' => 5],
                ],
            ],
        ];
    }

    /**
     * Resolve a single Soapbox preset by level key, falling back to General.
     *
     * @param string $level One of the SPEECH_LEVEL_* constants.
     * @return array{label_key:string, hint:string, criteria:array}
     */
    public static function speech_preset(string $level): array {
        $presets = self::speech_presets();
        return $presets[$level] ?? $presets[self::SPEECH_LEVEL_GENERAL];
    }

    /**
     * Get a user's practice scores for a course.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $type Filter by session type ('' for all).
     * @param int $limit Maximum number of records to return.
     * @return array Array of score records with decoded scores.
     */
    public static function get_user_scores(int $userid, int $courseid, string $type = '', int $limit = 10): array {
        global $DB;

        $params = [
            'userid' => $userid,
            'courseid' => $courseid,
        ];

        $typeclause = '';
        if ($type !== '') {
            $typeclause = ' AND s.session_type = :session_type';
            $params['session_type'] = $type;
        }

        $sql = "SELECT s.*
                  FROM {" . self::TABLE_SCORES . "} s
                 WHERE s.userid = :userid
                   AND s.courseid = :courseid" . $typeclause .
               " ORDER BY s.timecreated DESC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);

        foreach ($records as $record) {
            $record->scores = json_decode($record->scores, true);
        }

        return array_values($records);
    }
}
