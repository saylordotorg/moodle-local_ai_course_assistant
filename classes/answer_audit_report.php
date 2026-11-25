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
 * The answer audit page (8.4.0): one course's recorded answers, filtered by verdict, rule and date; one answer in
 * detail; a CSV or JSON export; and a counts report (top unsupported topics, rules by hit rate and false-alarm rate).
 *
 * Access: `local/ai_course_assistant:viewanalytics` in the course context (teachers of that course; a site admin
 * holds every capability, so any course). Every read is scoped to the course: the detail view refuses an answer id
 * of another course, and a verdict id.
 *
 * Redaction: an answer is treated as redacted when its answer row OR its latest verdict row is marked redacted
 * (is_redacted(), the same rule for the list, the detail view and the export). A redacted answer never shows or
 * exports its question, answer or claim text, even if text is still in a row (a hand restore or an edit); its
 * metadata, verdicts and hashes still show.
 *
 * Export: every matching answer, oldest first, streamed from a recordset (write_csv(), write_json()); there is no
 * row cap, so an export never silently drops rows.
 *
 * Learners are named by `anonymizer::name()` only, as on the instructor dashboard; no user id is exported.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_audit_report {

    /** @var string */
    private const COMPONENT = 'local_ai_course_assistant';

    /** @var string The capability the page needs in the course context. */
    public const CAPABILITY = 'local/ai_course_assistant:viewanalytics';

    /** @var string A verdict filter value: answers with no verdict yet. */
    public const UNCHECKED = 'unchecked';

    /** @var int Answers listed on the page. */
    public const PAGE_LIMIT = 200;

    /** @var int Topics and rules in the counts report. */
    public const REPORT_TOP = 10;

    /** @var string[] Claim statuses the topics report counts. */
    public const UNSUPPORTED = ['not_in_course', 'contradicted'];

    /** @var string[] Review outcomes that count as a false alarm of a rule. */
    public const FALSE_ALARM_OUTCOMES = ['false_alarm', 'rule_too_strict'];

    /** @var string[] Export columns, in order. */
    public const COLUMNS = ['id', 'time', 'learner', 'activity', 'mode', 'model', 'provider', 'categories', 'rules',
        'verdict', 'severity', 'claims', 'passages', 'redacted', 'question', 'answer', 'rowhash'];

    /**
     * Stop the request unless the current user may read this course's audit.
     *
     * @param int $courseid
     * @return \context_course
     * @throws \required_capability_exception
     */
    public static function require_view(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        require_capability(self::CAPABILITY, $context);
        return $context;
    }

    /**
     * Normalise the page's filter parameters. Unknown verdicts and malformed dates are dropped.
     *
     * @param string $verdict '', 'unchecked' or one of answer_audit::STATUSES
     * @param string $rule a rule id, matched exactly
     * @param string $from YYYY-MM-DD (start of that day, user's timezone)
     * @param string $to YYYY-MM-DD (end of that day)
     * @return array{verdict: string, rule: string, since: int, until: int, from: string, to: string}
     */
    public static function parse_filters(string $verdict, string $rule, string $from, string $to): array {
        $verdict = in_array($verdict, self::verdict_values(), true) ? $verdict : '';
        $rule = trim($rule);
        if (\core_text::strlen($rule) > 100) {
            $rule = '';
        }
        $since = self::day_start($from);
        $until = self::day_start($to);
        return [
            'verdict' => $verdict,
            'rule' => $rule,
            'since' => $since,
            'until' => $until ? $until + DAYSECS - 1 : 0,
            'from' => $since ? $from : '',
            'to' => $until ? $to : '',
        ];
    }

    /**
     * The verdict filter's values (without the empty "any").
     *
     * @return string[]
     */
    public static function verdict_values(): array {
        return array_merge([self::UNCHECKED], answer_audit::STATUSES);
    }

    /**
     * Content rule ids recorded on this course's answers, sorted.
     *
     * @param int $courseid
     * @return string[]
     */
    public static function rule_choices(int $courseid): array {
        global $DB;
        $ids = [];
        // Answer rows and verdict rows: the rule filter matches rules recorded on either.
        $rs = $DB->get_recordset_select(answer_audit::TABLE, 'courseid = :courseid AND rules IS NOT NULL',
            ['courseid' => $courseid], '', 'id, rules');
        foreach ($rs as $rec) {
            foreach (self::content_rules(self::json_list($rec->rules)) as $r) {
                $ids[$r['id']] = true;
            }
        }
        $rs->close();
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /**
     * Every matching export row of a course's answers, oldest first, flat (one row per answer, its latest verdict
     * inlined), streamed from a recordset. No cap.
     *
     * @param int $courseid
     * @param array $filters verdict, rule, since, until (any may be missing)
     * @return \Generator<array> rows keyed by COLUMNS
     */
    public static function export_iter(int $courseid, array $filters): \Generator {
        $cms = self::course_cms($courseid);
        foreach (self::collect($courseid, $filters, 0, false) as [$a, $v]) {
            yield self::flat_row($a, $v, $cms);
        }
    }

    /**
     * Every matching export row, as an array (tests and small courses; the page streams export_iter()).
     *
     * @param int $courseid
     * @param array $filters
     * @return array[]
     */
    public static function export_rows(int $courseid, array $filters): array {
        return iterator_to_array(self::export_iter($courseid, $filters), false);
    }

    /**
     * One answer of this course, with every verdict, for the detail view.
     *
     * @param int $courseid
     * @param int $answerid an answer row id of this course
     * @return array
     * @throws \moodle_exception answeraudit:notfound for any other id
     */
    public static function detail(int $courseid, int $answerid): array {
        global $DB;
        $a = $DB->get_record(answer_audit::TABLE, ['id' => $answerid, 'courseid' => $courseid, 'kind' => 'answer']);
        if (!$a) {
            throw new \moodle_exception('answeraudit:notfound', self::COMPONENT);
        }
        $cms = self::course_cms($courseid);
        $verdicts = [];
        $vrecs = $DB->get_records(answer_audit::TABLE,
            ['kind' => 'verdict', 'supersedes' => (int) $a->id, 'courseid' => $courseid], 'id ASC');
        $redacted = self::is_redacted($a, $vrecs ? end($vrecs) : null);
        foreach ($vrecs as $v) {
            $vredacted = $redacted || !empty($v->redacted);
            $claims = [];
            foreach (self::json_list($v->claims) as $c) {
                $claims[] = [
                    'text' => $vredacted ? '' : (string) ($c['text'] ?? ''),
                    'type' => (string) ($c['type'] ?? ''),
                    'status' => (string) ($c['status'] ?? ''),
                    'severity' => (string) ($c['severity'] ?? ''),
                ];
            }
            $verdicts[] = [
                'id' => (int) $v->id,
                'time' => (int) $v->timecreated,
                'status' => (string) $v->status,
                'severity' => (string) ($v->severity ?? ''),
                'rules' => self::rule_text(self::content_rules(self::json_list($v->rules))),
                'rule_items' => self::content_rules(self::json_list($v->rules)),
                'judge_model' => (string) ($v->judge_model ?? ''),
                'claims' => $claims,
                'rowhash' => (string) $v->rowhash,
            ];
        }
        $passages = [];
        foreach (self::json_list($a->chunks) as $c) {
            $cmid = (int) ($c['cmid'] ?? 0);
            $passages[] = [
                'activity' => $cmid && isset($cms[$cmid]) ? $cms[$cmid] : self::coursewide(),
                'score' => sprintf('%.3f', (float) ($c['score'] ?? 0)),
            ];
        }
        $validators = [];
        foreach (self::json_list($a->validators) as $val) {
            $validators[] = trim((string) ($val['name'] ?? '') . ' ' . (string) ($val['severity'] ?? ''));
        }
        return [
            'id' => (int) $a->id,
            'time' => (int) $a->timecreated,
            'learner' => anonymizer::name((int) $a->userid),
            'activity' => self::activity($a, $cms),
            'mode' => (string) ($a->mode ?? ''),
            'model' => (string) ($a->model ?? ''),
            'provider' => (string) ($a->provider ?? ''),
            'redacted' => $redacted,
            'question' => $redacted ? '' : (string) ($a->question ?? ''),
            'answer' => $redacted ? '' : (string) ($a->answer ?? ''),
            'categories' => implode(', ', array_map('strval', self::json_scalars($a->categories))),
            'category_ids' => array_map('strval', self::json_scalars($a->categories)),
            'rules' => self::rule_text(self::content_rules(self::json_list($a->rules))),
            'rule_items' => self::content_rules(self::json_list($a->rules)),
            'flow' => implode(', ', self::status_ids(self::json_list($a->rules))),
            'flow_ids' => self::status_ids(self::json_list($a->rules)),
            'validators' => implode(', ', $validators),
            'citations' => count(self::json_list($a->citations)),
            'passages' => $passages,
            'verdicts' => $verdicts,
            'rowhash' => (string) $a->rowhash,
            'prevhash' => (string) $a->prevhash,
        ];
    }

    /**
     * The counts report: top unsupported topics (claims not_in_course or contradicted in each answer's latest
     * verdict, grouped by the activity the learner asked from) and rules ranked by hits, with the hit rate (share of
     * the course's answers) and the false-alarm rate (share of reviewed rule items the teacher marked false_alarm
     * or rule_too_strict). Course-wide, all time.
     *
     * @param int $courseid
     * @param int $top
     * @return array{topics: array[], rules: array[], answers: int}
     */
    public static function report(int $courseid, int $top = self::REPORT_TOP): array {
        global $DB;
        $cms = self::course_cms($courseid);

        // Topics.
        $topics = [];
        $latest = self::latest_verdicts($courseid);
        if ($latest) {
            $cmids = [];
            foreach (array_chunk(array_keys($latest), 500) as $chunk) {
                [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'aid');
                $cmids += $DB->get_records_select_menu(answer_audit::TABLE,
                    "id $in AND courseid = :courseid AND kind = :kind", $params + ['courseid' => $courseid,
                    'kind' => 'answer'], '', 'id, cmid');
            }
            foreach ($latest as $aid => $v) {
                if (!array_key_exists($aid, $cmids)) {
                    continue;
                }
                $cmid = (int) $cmids[$aid];
                $topic = $cmid && isset($cms[$cmid]) ? $cms[$cmid] : self::coursewide();
                foreach (self::json_list($v->claims) as $c) {
                    $status = (string) ($c['status'] ?? '');
                    if (!in_array($status, self::UNSUPPORTED, true)) {
                        continue;
                    }
                    $topics[$topic] = $topics[$topic] ?? ['topic' => $topic, 'not_in_course' => 0,
                        'contradicted' => 0, 'total' => 0];
                    $topics[$topic][$status]++;
                    $topics[$topic]['total']++;
                }
            }
        }
        $topics = array_values($topics);
        usort($topics, static fn(array $x, array $y): int =>
            [$y['total'], $y['contradicted'], $x['topic']] <=> [$x['total'], $x['contradicted'], $y['topic']]);

        // Rules.
        $answers = $DB->count_records(answer_audit::TABLE, ['courseid' => $courseid, 'kind' => 'answer']);
        $fired = [];
        $rs = $DB->get_recordset_select(answer_audit::TABLE, 'courseid = :courseid AND kind = :kind AND rules IS NOT NULL',
            ['courseid' => $courseid, 'kind' => 'answer'], '', 'id, rules');
        foreach ($rs as $rec) {
            foreach (array_unique(array_column(self::content_rules(self::json_list($rec->rules)), 'id')) as $id) {
                $fired[$id][] = (int) $rec->id;
            }
        }
        $rs->close();
        $outcomes = $DB->get_records_select_menu('local_ai_course_assistant_review_res',
            "courseid = :courseid AND source = :source AND outcome <> ''",
            ['courseid' => $courseid, 'source' => 'rule'], '', 'sourceid, outcome');
        $rules = [];
        foreach ($fired as $id => $aids) {
            $reviewed = 0;
            $falsealarms = 0;
            foreach ($aids as $aid) {
                if (!isset($outcomes[$aid])) {
                    continue;
                }
                $reviewed++;
                if (in_array($outcomes[$aid], self::FALSE_ALARM_OUTCOMES, true)) {
                    $falsealarms++;
                }
            }
            $rules[] = [
                'rule' => (string) $id,
                'hits' => count($aids),
                'hitrate' => $answers ? count($aids) / $answers : 0.0,
                'reviewed' => $reviewed,
                'falsealarms' => $falsealarms,
                'falsealarmrate' => $reviewed ? $falsealarms / $reviewed : null,
            ];
        }
        usort($rules, static fn(array $x, array $y): int => [$y['hits'], $x['rule']] <=> [$x['hits'], $y['rule']]);

        return ['topics' => array_slice($topics, 0, $top), 'rules' => array_slice($rules, 0, $top),
            'answers' => $answers];
    }

    /**
     * Write CSV to a stream: a first row with the chain check, then the column names, then every row, each cell
     * formula-escaped.
     *
     * @param resource $handle
     * @param iterable $rows export_iter() or export_rows()
     * @param array $chain answer_audit::verify_chain()
     * @return int rows written
     */
    public static function write_csv($handle, iterable $rows, array $chain): int {
        fputcsv($handle, ['chain_verified', !empty($chain['ok']) ? 'yes' : 'no', 'broken_at',
            $chain['brokenat'] === null ? '' : (string) (int) $chain['brokenat']]);
        fputcsv($handle, self::COLUMNS);
        $count = 0;
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::COLUMNS as $col) {
                $cells[] = transcript_report::csv_cell((string) ($row[$col] ?? ''));
            }
            fputcsv($handle, $cells);
            $count++;
        }
        return $count;
    }

    /**
     * Write JSON to a stream: {chain, filters, rows: [...]}, one row at a time.
     *
     * @param resource $handle
     * @param iterable $rows
     * @param array $chain
     * @param array $filters
     * @return int rows written
     */
    public static function write_json($handle, iterable $rows, array $chain, array $filters): int {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $head = [
            'chain' => ['verified' => !empty($chain['ok']),
                'broken_at' => $chain['brokenat'] === null ? null : (int) $chain['brokenat']],
            'filters' => [
                'verdict' => (string) ($filters['verdict'] ?? ''),
                'rule' => (string) ($filters['rule'] ?? ''),
                'since' => (int) ($filters['since'] ?? 0),
                'until' => (int) ($filters['until'] ?? 0),
            ],
        ];
        fwrite($handle, '{"chain":' . json_encode($head['chain'], $flags) . ',"filters":'
            . json_encode($head['filters'], $flags) . ',"rows":[');
        $count = 0;
        foreach ($rows as $row) {
            fwrite($handle, ($count ? ",\n" : "\n") . json_encode($row, $flags));
            $count++;
        }
        fwrite($handle, "\n]}\n");
        return $count;
    }

    /**
     * CSV text (write_csv() into memory).
     *
     * @param iterable $rows
     * @param array $chain
     * @return string
     */
    public static function to_csv(iterable $rows, array $chain): string {
        $handle = fopen('php://temp', 'r+');
        self::write_csv($handle, $rows, $chain);
        rewind($handle);
        $out = (string) stream_get_contents($handle);
        fclose($handle);
        return $out;
    }

    /**
     * JSON text (write_json() into memory).
     *
     * @param iterable $rows
     * @param array $chain
     * @param array $filters
     * @return string
     */
    public static function to_json(iterable $rows, array $chain, array $filters): string {
        $handle = fopen('php://temp', 'r+');
        self::write_json($handle, $rows, $chain, $filters);
        rewind($handle);
        $out = (string) stream_get_contents($handle);
        fclose($handle);
        return $out;
    }

    /**
     * The page's template context. Every value is raw text: the template escapes it.
     *
     * @param int $courseid
     * @param array $filters parse_filters()
     * @param int $detailid an answer to open, or 0
     * @return array
     */
    public static function template_context(int $courseid, array $filters, int $detailid = 0): array {
        $str = static fn(string $k, $a = null): string => get_string('answeraudit:' . $k, self::COMPONENT, $a);
        $base = new \moodle_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $courseid]);
        $keep = ['courseid' => $courseid];
        foreach (['verdict', 'rule', 'from', 'to'] as $k) {
            if (($filters[$k] ?? '') !== '') {
                $keep[$k] = $filters[$k];
            }
        }
        $cms = self::course_cms($courseid);
        $chain = answer_audit::verify_chain($courseid);

        $verdictoptions = [['value' => '', 'label' => $str('any'), 'selected' => $filters['verdict'] === '']];
        foreach (self::verdict_values() as $v) {
            $verdictoptions[] = ['value' => $v, 'label' => self::status_label($v), 'selected' => $filters['verdict'] === $v];
        }
        $ruleoptions = [['value' => '', 'label' => $str('any'), 'selected' => $filters['rule'] === '']];
        foreach (self::rule_choices($courseid) as $r) {
            $ruleoptions[] = ['value' => $r, 'label' => $r, 'selected' => $filters['rule'] === $r];
        }

        $rows = [];
        $found = iterator_to_array(self::collect($courseid, $filters, self::PAGE_LIMIT + 1, true), false);
        $truncated = count($found) > self::PAGE_LIMIT;
        foreach (array_slice($found, 0, self::PAGE_LIMIT) as [$a, $v]) {
            $redacted = self::is_redacted($a, $v);
            $rules = self::content_rules(self::json_list($a->rules));
            $rows[] = [
                'id' => (int) $a->id,
                'time' => userdate((int) $a->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'learner' => anonymizer::name((int) $a->userid),
                'activity' => self::activity($a, $cms),
                'verdict' => self::status_label($v ? (string) $v->status : self::UNCHECKED),
                'rules' => answer_labels::rules($rules),
                'redacted' => $redacted,
                'question' => $redacted ? '' : shorten_text((string) ($a->question ?? ''), 120),
                'detailurl' => (new \moodle_url($base, $keep + ['id' => (int) $a->id]))->out(false),
            ];
        }

        $detail = null;
        if ($detailid > 0) {
            $d = self::detail($courseid, $detailid);
            // Readable labels for the page only: detail() and the exports keep the stored codes.
            $d['time'] = userdate($d['time']);
            $d['hasquestion'] = $d['question'] !== '';
            $d['hasanswer'] = $d['answer'] !== '';
            $d['haspassages'] = !empty($d['passages']);
            $d['hasverdicts'] = !empty($d['verdicts']);
            $d['categories'] = implode(', ', array_map([answer_labels::class, 'category'], $d['category_ids']));
            $d['rules'] = answer_labels::rules($d['rule_items']);
            $d['flow'] = array_map(static fn(string $id): array => ['label' => answer_labels::flow($id)],
                $d['flow_ids']);
            $d['hasflow'] = !empty($d['flow']);
            foreach ($d['passages'] as $i => $p) {
                $d['passages'][$i]['label'] = $str('passage_score', (object) $p);
            }
            foreach ($d['verdicts'] as $i => $v) {
                $d['verdicts'][$i]['headingid'] = 'aica-aa-verdict-' . (int) $v['id'];
                $d['verdicts'][$i]['status'] = answer_labels::verdict($v['status'], $v['severity']);
                $d['verdicts'][$i]['checked'] = $str('verdict_checked', (object) [
                    'time' => userdate($v['time']),
                    'model' => $v['judge_model'] !== '' ? $v['judge_model'] : '-',
                ]);
                $d['verdicts'][$i]['rules'] = $v['rule_items'] ? $str('verdict_rules', answer_labels::rules($v['rule_items']))
                    : '';
                $d['verdicts'][$i]['hasclaims'] = !empty($v['claims']);
                foreach ($v['claims'] as $j => $c) {
                    $d['verdicts'][$i]['claims'][$j] = [
                        'text' => $c['text'],
                        'hastext' => $c['text'] !== '',
                        'status' => answer_labels::claim_status($c['status']),
                        'type' => answer_labels::claim_type($c['type']),
                        'severity' => answer_labels::severity($c['severity']),
                    ];
                }
            }
            $d['backurl'] = (new \moodle_url($base, $keep))->out(false);
            $detail = $d;
        }

        $report = self::report($courseid);
        $topics = $report['topics'];
        $rulesreport = [];
        foreach ($report['rules'] as $r) {
            $rulesreport[] = [
                'rule' => $r['rule'],
                'hits' => $r['hits'],
                'hitrate' => format_float($r['hitrate'] * 100, 1) . '%',
                'reviewed' => $r['reviewed'],
                'falsealarms' => $r['falsealarms'],
                'falsealarmrate' => $r['falsealarmrate'] === null ? '-'
                    : format_float($r['falsealarmrate'] * 100, 1) . '%',
            ];
        }

        $exporturl = static fn(string $format): string =>
            (new \moodle_url($base, $keep + ['format' => $format, 'sesskey' => sesskey()]))->out(false);

        $hasfilters = count($keep) > 1;
        return [
            'formaction' => $base->out(false),
            'reseturl' => $base->out(false),
            'hasfilters' => $hasfilters,
            'dashboardurl' => (new \moodle_url('/local/ai_course_assistant/instructor_dashboard.php',
                ['courseid' => $courseid]))->out(false),
            'courseid' => $courseid,
            'verdictoptions' => $verdictoptions,
            'ruleoptions' => $ruleoptions,
            'from' => $filters['from'],
            'to' => $filters['to'],
            'chainok' => !empty($chain['ok']),
            'chaintext' => !empty($chain['ok']) ? $str('chain_ok')
                : $str('chain_broken', (int) $chain['brokenat']),
            'chainlabel' => !empty($chain['ok']) ? $str('chain_ok_label') : $str('chain_broken_label'),
            'csvurl' => $exporturl('csv'),
            'jsonurl' => $exporturl('json'),
            'hasrows' => !empty($rows),
            'emptytext' => $hasfilters ? $str('norows') : $str('empty'),
            'rows' => $rows,
            'rowcount' => $truncated ? $str('truncated', self::PAGE_LIMIT) : $str('rowcount', count($rows)),
            'hasdetail' => $detail !== null,
            'detail' => $detail,
            'hastopics' => !empty($topics),
            'topics' => $topics,
            'hasrulesreport' => !empty($rulesreport),
            'rulesreport' => $rulesreport,
        ];
    }

    /**
     * A verdict status label.
     *
     * @param string $status
     * @return string
     */
    public static function status_label(string $status): string {
        return answer_labels::status($status);
    }

    /**
     * Answer rows of the course with their latest verdict, filtered.
     *
     * @param int $courseid
     * @param array $filters
     * @param int $limit 0 = every matching answer
     * @param bool $newestfirst
     * @return \Generator<array> [answer record, latest verdict record or null]
     */
    private static function collect(int $courseid, array $filters, int $limit, bool $newestfirst): \Generator {
        global $DB;
        $verdict = (string) ($filters['verdict'] ?? '');
        $rule = (string) ($filters['rule'] ?? '');
        $where = 'courseid = :courseid AND kind = :kind';
        $params = ['courseid' => $courseid, 'kind' => 'answer'];
        if (!empty($filters['since'])) {
            $where .= ' AND timecreated >= :since';
            $params['since'] = (int) $filters['since'];
        }
        if (!empty($filters['until'])) {
            $where .= ' AND timecreated <= :until';
            $params['until'] = (int) $filters['until'];
        }
        $latest = self::latest_verdicts($courseid);
        $found = 0;
        $rs = $DB->get_recordset_select(answer_audit::TABLE, $where, $params, $newestfirst ? 'id DESC' : 'id ASC');
        try {
            foreach ($rs as $a) {
                if ($limit > 0 && $found >= $limit) {
                    break;
                }
                $v = $latest[(int) $a->id] ?? null;
                if ($verdict !== '') {
                    $status = $v ? (string) $v->status : self::UNCHECKED;
                    if ($status !== $verdict) {
                        continue;
                    }
                }
                if ($rule !== '') {
                    $ids = array_column(self::content_rules(array_merge(self::json_list($a->rules),
                        $v ? self::json_list($v->rules) : [])), 'id');
                    if (!in_array($rule, $ids, true)) {
                        continue;
                    }
                }
                $found++;
                yield [$a, $v];
            }
        } finally {
            $rs->close();
        }
    }

    /**
     * Whether an answer is treated as redacted: its answer row or its latest verdict row is marked redacted.
     *
     * @param \stdClass $a answer row
     * @param \stdClass|null $v latest verdict row
     * @return bool
     */
    private static function is_redacted(\stdClass $a, ?\stdClass $v): bool {
        return !empty($a->redacted) || ($v !== null && !empty($v->redacted));
    }

    /**
     * The latest verdict of each answer of the course, by answer id.
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    private static function latest_verdicts(int $courseid): array {
        global $DB;
        $sql = "SELECT v.*
                  FROM {" . answer_audit::TABLE . "} v
                 WHERE v.id IN (SELECT MAX(v2.id)
                                  FROM {" . answer_audit::TABLE . "} v2
                                 WHERE v2.courseid = :courseid AND v2.kind = :kind
                              GROUP BY v2.supersedes)";
        $out = [];
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid, 'kind' => 'verdict']) as $v) {
            $out[(int) $v->supersedes] = $v;
        }
        return $out;
    }

    /**
     * One flat export row.
     *
     * @param \stdClass $a answer row
     * @param \stdClass|null $v latest verdict
     * @param array $cms
     * @return array
     */
    private static function flat_row(\stdClass $a, ?\stdClass $v, array $cms): array {
        $redacted = self::is_redacted($a, $v);
        $rules = self::content_rules(self::json_list($a->rules));
        if ($v) {
            foreach (self::content_rules(self::json_list($v->rules)) as $r) {
                if (!in_array($r, $rules, true)) {
                    $rules[] = $r;
                }
            }
        }
        $claims = [];
        foreach ($v ? self::json_list($v->claims) : [] as $c) {
            $head = (string) ($c['status'] ?? '') . '/' . (string) ($c['type'] ?? '') . '/' . (string) ($c['severity'] ?? '');
            $claims[] = $redacted ? $head : $head . ': ' . (string) ($c['text'] ?? '');
        }
        return [
            'id' => (int) $a->id,
            'time' => gmdate('Y-m-d\TH:i:s\Z', (int) $a->timecreated),
            'learner' => anonymizer::name((int) $a->userid),
            'activity' => self::activity($a, $cms),
            'mode' => (string) ($a->mode ?? ''),
            'model' => (string) ($a->model ?? ''),
            'provider' => (string) ($a->provider ?? ''),
            'categories' => implode(';', array_map('strval', self::json_scalars($a->categories))),
            'rules' => self::rule_text($rules, ';'),
            'verdict' => $v ? (string) $v->status : self::UNCHECKED,
            'severity' => $v ? (string) ($v->severity ?? '') : '',
            'claims' => implode(' | ', $claims),
            'passages' => count(self::json_list($a->chunks)),
            'redacted' => $redacted ? 1 : 0,
            'question' => $redacted ? '' : (string) ($a->question ?? ''),
            'answer' => $redacted ? '' : (string) ($a->answer ?? ''),
            'rowhash' => (string) $a->rowhash,
        ];
    }

    /**
     * Rules as "id:action" joined.
     *
     * @param array $rules
     * @param string $glue
     * @return string
     */
    private static function rule_text(array $rules, string $glue = ', '): string {
        return implode($glue, array_map(static fn(array $r): string => $r['id'] . ':' . $r['action'], $rules));
    }

    /**
     * The status entries of a rules list (answer_flow::audit_rules(): ids mode.* and outcome.*, action none), as ids:
     * the course mode and what happened to the answer. Shown in the detail view only.
     *
     * @param array $rules
     * @return string[]
     */
    private static function status_ids(array $rules): array {
        $out = [];
        foreach ($rules as $r) {
            if (is_array($r) && is_string($r['id'] ?? null) && ($r['action'] ?? null) === 'none'
                    && (str_starts_with($r['id'], 'mode.') || str_starts_with($r['id'], 'outcome.'))) {
                $out[] = $r['id'];
            }
        }
        return $out;
    }

    /**
     * Content rules only: [{id, action}] with a string id not starting claim_check., and an action other than none.
     *
     * @param array $rules
     * @return array[]
     */
    private static function content_rules(array $rules): array {
        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || !is_string($r['action'] ?? null)) {
                continue;
            }
            if (str_starts_with($r['id'], claim_checker::FAILED_RULE_PREFIX) || $r['action'] === 'none') {
                continue;
            }
            $out[] = ['id' => $r['id'], 'action' => $r['action']];
        }
        return $out;
    }

    /**
     * A JSON list column decoded to its array items, or [].
     *
     * @param string|null $json
     * @return array[]
     */
    private static function json_list(?string $json): array {
        $decoded = $json === null ? null : json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * A JSON list column decoded to its scalar items, or [].
     *
     * @param string|null $json
     * @return array
     */
    private static function json_scalars(?string $json): array {
        $decoded = $json === null ? null : json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_scalar')) : [];
    }

    /**
     * The activity an answer was asked from, or "Course-wide".
     *
     * @param \stdClass $a
     * @param array $cms
     * @return string
     */
    private static function activity(\stdClass $a, array $cms): string {
        $cmid = (int) ($a->cmid ?? 0);
        return $cmid && isset($cms[$cmid]) ? $cms[$cmid] : self::coursewide();
    }

    /**
     * The label for no activity.
     *
     * @return string
     */
    private static function coursewide(): string {
        return get_string('answeraudit:coursewide', self::COMPONENT);
    }

    /**
     * Activity names of a course by cmid (raw: the template escapes them).
     *
     * @param int $courseid
     * @return array<int, string>
     */
    private static function course_cms(int $courseid): array {
        try {
            $out = [];
            foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
                $out[(int) $cm->id] = (string) $cm->name;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The start of a YYYY-MM-DD day in the user's timezone, or 0.
     *
     * @param string $day
     * @return int
     */
    private static function day_start(string $day): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($day), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return 0;
        }
        return (int) make_timestamp((int) $m[1], (int) $m[2], (int) $m[3]);
    }
}
