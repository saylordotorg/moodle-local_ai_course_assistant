# RAG judge-mode harness — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `--judge` mode to `admin/cli/run_rag_fixture_benchmark.php` that grades retrieved-passage relevance with an LLM (label-free) and compares configs — Family A (chunk/page, rerank) via the real retriever, Family B (OpenAI/Voyage) via in-memory re-embed.

**Architecture:** Pure metric + parse logic in a new testable class `classes/rag_judge.php` (unit-tested). The `--judge` block in the CLI orchestrates: load unlabeled questions → per arm, retrieve (or re-embed) → LLM-grade each passage 0-3 → aggregate metrics. Config is flipped per Family-A arm and restored on exit (existing `register_shutdown_function` pattern). Runs on dev via SSM.

**Tech Stack:** PHP 8.3, Moodle plugin, PHPUnit, `curl` to OpenAI chat completions.

## Global Constraints

- Namespace `local_ai_course_assistant`. Match the style of `classes/rag_retriever.php` and the existing CLI.
- Pure functions in `rag_judge` are side-effect-free; unit-tested with `\basic_testcase`.
- Default k (top-k) is the CLI's existing `$topk` (default 10); judge mode passes it through.
- API keys never printed; read from plugin config (`embed_apikey`, fallback `apikey`) or `--openai-apikey`.
- Config touched by Family A (`rag_return_scope`, `rerank_enabled`) is captured before and restored on exit — the run must not mutate stored config.
- Lint every changed file: `/opt/homebrew/opt/php@8.3/bin/php -l <file>`.
- Unit tests run from `~/Sites/moodle`: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/<file>`.
- Commit after each task.

---

## File Structure

- `classes/rag_judge.php` (new) — `ndcg_at_k`, `precision_at_k`, `hit_at_k`, `mean_relevance`, `parse_grades` (all pure static).
- `admin/cli/run_rag_fixture_benchmark.php` (modify) — new `--judge` args, the judge HTTP call, Family A block, Family B block.
- `tests/rag_judge_test.php` (new) — unit tests for the class.

---

### Task 1: `rag_judge` metrics + parser (pure, tested)

**Files:**
- Create: `classes/rag_judge.php`
- Test: `tests/rag_judge_test.php`

**Interfaces:**
- Produces: `rag_judge::ndcg_at_k(array $grades, int $k): float`, `precision_at_k(array $grades, int $k, int $threshold=2): float`, `hit_at_k(array $grades, int $k, int $threshold=2): int`, `mean_relevance(array $grades, int $k): float`, `parse_grades(string $reply, int $expected): ?array` (returns exactly `$expected` ints clamped 0-3, or null on parse/length failure).

- [ ] **Step 1: Write the failing test**

```php
// tests/rag_judge_test.php
namespace local_ai_course_assistant;
defined('MOODLE_INTERNAL') || die();

class rag_judge_test extends \basic_testcase {
    public function test_ndcg_perfect_and_zero_and_mixed() {
        $this->assertEqualsWithDelta(1.0, rag_judge::ndcg_at_k([3, 2, 1], 3), 1e-9);
        $this->assertEqualsWithDelta(0.0, rag_judge::ndcg_at_k([0, 0, 0], 3), 1e-9);
        // grades [0,3,0]: DCG = 7/log2(3); IDCG = 7/log2(2)=7 -> 0.6309
        $this->assertEqualsWithDelta(0.63093, rag_judge::ndcg_at_k([0, 3, 0], 3), 1e-4);
    }
    public function test_precision_hit_mean() {
        $this->assertEqualsWithDelta(0.5, rag_judge::precision_at_k([3, 1, 2, 0], 4), 1e-9);
        $this->assertSame(0, rag_judge::hit_at_k([1, 0, 1], 3));
        $this->assertSame(1, rag_judge::hit_at_k([1, 2, 0], 3));
        $this->assertEqualsWithDelta(2.0, rag_judge::mean_relevance([3, 0, 3], 3), 1e-9);
    }
    public function test_parse_grades() {
        $this->assertSame([3, 2, 0, 1, 0], rag_judge::parse_grades('[3, 2, 0, 1, 0]', 5));
        // extra prose around the array is tolerated
        $this->assertSame([3, 2, 1, 0, 0], rag_judge::parse_grades("grades: [3,2,1,0,0] done", 5));
        // out-of-range clamped
        $this->assertSame([3, 0], rag_judge::parse_grades('[5, -1]', 2));
        // wrong length -> null
        $this->assertNull(rag_judge::parse_grades('[3, 2]', 5));
        // garbage -> null
        $this->assertNull(rag_judge::parse_grades('no json here', 3));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/rag_judge_test.php`
Expected: FAIL — class `rag_judge` not found.

- [ ] **Step 3: Implement `classes/rag_judge.php`**

```php
<?php
// This file is part of Moodle - http://moodle.org/
// [GPL v3 boilerplate matching other classes in this plugin]

namespace local_ai_course_assistant;

/**
 * Pure scoring + parse helpers for the RAG judge-mode benchmark.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rag_judge {

    /** nDCG@k using graded relevance (gain = 2^grade - 1). 0.0 when nothing is relevant. */
    public static function ndcg_at_k(array $grades, int $k): float {
        $g = array_slice(array_map('intval', $grades), 0, $k);
        $dcg = 0.0;
        foreach ($g as $i => $gi) {
            $dcg += (pow(2, $gi) - 1) / log($i + 2, 2);
        }
        $ideal = $g;
        rsort($ideal);
        $idcg = 0.0;
        foreach ($ideal as $i => $gi) {
            $idcg += (pow(2, $gi) - 1) / log($i + 2, 2);
        }
        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }

    /** Fraction of the top-k passages graded >= threshold. */
    public static function precision_at_k(array $grades, int $k, int $threshold = 2): float {
        $g = array_slice(array_map('intval', $grades), 0, $k);
        if (empty($g)) {
            return 0.0;
        }
        $rel = count(array_filter($g, fn($x) => $x >= $threshold));
        return $rel / count($g);
    }

    /** 1 if any top-k passage is graded >= threshold, else 0. */
    public static function hit_at_k(array $grades, int $k, int $threshold = 2): int {
        foreach (array_slice(array_map('intval', $grades), 0, $k) as $x) {
            if ($x >= $threshold) {
                return 1;
            }
        }
        return 0;
    }

    /** Mean grade over the top-k passages. */
    public static function mean_relevance(array $grades, int $k): float {
        $g = array_slice(array_map('intval', $grades), 0, $k);
        if (empty($g)) {
            return 0.0;
        }
        return array_sum($g) / count($g);
    }

    /**
     * Parse a judge reply into exactly $expected integer grades clamped to 0-3.
     * Extracts the first JSON array in the reply. Returns null on parse failure
     * or length mismatch (so the caller can count a judge error, never zero-fill).
     *
     * @return int[]|null
     */
    public static function parse_grades(string $reply, int $expected): ?array {
        if (!preg_match('/\[[\s\S]*?\]/', $reply, $m)) {
            return null;
        }
        $arr = json_decode($m[0], true);
        if (!is_array($arr) || count($arr) !== $expected) {
            return null;
        }
        $out = [];
        foreach ($arr as $v) {
            if (!is_numeric($v)) {
                return null;
            }
            $out[] = max(0, min(3, (int) $v));
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/rag_judge_test.php`
Expected: PASS.

- [ ] **Step 5: Lint + commit**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l classes/rag_judge.php
git add classes/rag_judge.php tests/rag_judge_test.php
git commit -m "feat(rag): rag_judge metrics + grade parser (pure, tested)"
```

---

### Task 2: `--judge` mode core + Family A (config arms via retrieve())

**Files:**
- Modify: `admin/cli/run_rag_fixture_benchmark.php`

**Interfaces:**
- Consumes: `rag_judge` (Task 1), `rag_retriever::retrieve()`.
- Produces: `--judge`, `--questions=PATH`, `--sample=N`, `--judge-model=` flags; the `--judge` block that prints a Family A arms table and writes a run JSON. Global helpers `judge_passages()` and `judge_arm()`.

- [ ] **Step 1: Add the new CLI args**

In the arg-parse `foreach ($argv ...)` loop, alongside the existing `--embed-provider` etc. cases, add:
```php
    } else if ($arg === '--judge') {
        $judgemode = true;
    } else if (preg_match('/^--questions=(.+)$/', $arg, $m)) {
        $questionspath = trim($m[1]);
    } else if (preg_match('/^--sample=(\d+)$/', $arg, $m)) {
        $samplesize = max(1, (int) $m[1]);
    } else if (preg_match('/^--judge-model=(.+)$/', $arg, $m)) {
        $judgemodel = trim($m[1]);
```
And initialise the defaults near the other `$` defaults at the top (after `$abbatch = 96;`):
```php
$judgemode     = false;
$questionspath = '';
$samplesize    = 100;
$judgemodel    = 'gpt-4o-mini';
```

- [ ] **Step 2: Add the judge helper functions**

Add these two global functions near `cosine_sim()` (anywhere at file top-level function scope):
```php
/**
 * LLM-grade each passage 0-3 for relevance to the question (one batched call).
 * Returns exactly count($passages) grades, or null on judge/parse failure.
 *
 * @return int[]|null
 */
function judge_passages(string $question, array $passages, string $model, string $apikey): ?array {
    $k = count($passages);
    if ($k === 0) {
        return [];
    }
    $lines = [];
    foreach ($passages as $i => $p) {
        $lines[] = 'PASSAGE ' . ($i + 1) . ":\n" . mb_substr((string) $p, 0, 1500);
    }
    $sys = "You grade how well each passage answers a student's question, for a retrieval-quality eval. "
        . "Grade each passage 0-3: 0 = irrelevant, 1 = tangentially related, 2 = relevant / partially answers, "
        . "3 = directly answers. Return ONLY a JSON array of {$k} integers, one grade per passage in order, "
        . "e.g. [3,1,0,2,0].";
    $user = 'QUESTION: ' . $question . "\n\n" . implode("\n\n", $lines);
    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0,
    ]);
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $c = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apikey],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($code === 429 || $code >= 500) {
            sleep(3);
            continue;
        }
        if ($code !== 200 || !$resp) {
            return null;
        }
        $body = json_decode($resp, true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        return \local_ai_course_assistant\rag_judge::parse_grades($content, $k);
    }
    return null;
}

/**
 * Run the live retriever over the questions under the current config and judge
 * the returned passages. Returns aggregate metrics for one arm.
 */
function judge_arm(array $qitems, int $topk, string $model, string $key): array {
    $ndcg = $prec = $hit = $mean = 0.0;
    $scored = 0;
    $errors = 0;
    foreach ($qitems as $q) {
        $res = \local_ai_course_assistant\rag_retriever::retrieve($q['courseid'], $q['question'], $topk, 0);
        $passages = array_map(fn($r) => (string) $r['content'], $res);
        if (empty($passages)) {
            // Retrieval returned nothing -> zero relevance for this question.
            $scored++;
            continue;
        }
        $grades = judge_passages($q['question'], $passages, $model, $key);
        if ($grades === null) {
            $errors++;
            continue;
        }
        $ndcg += \local_ai_course_assistant\rag_judge::ndcg_at_k($grades, $topk);
        $prec += \local_ai_course_assistant\rag_judge::precision_at_k($grades, $topk);
        $hit  += \local_ai_course_assistant\rag_judge::hit_at_k($grades, $topk);
        $mean += \local_ai_course_assistant\rag_judge::mean_relevance($grades, $topk);
        $scored++;
    }
    $n = max(1, $scored);
    return ['ndcg' => $ndcg / $n, 'precision' => $prec / $n, 'hit' => $hit / $n,
            'mean_rel' => $mean / $n, 'scored' => $scored, 'errors' => $errors];
}
```

- [ ] **Step 3: Add the `--judge` block (Family A)**

Immediately AFTER the fixtures are loaded (`$fixtures = $fixturedoc['fixtures'];` / the `echo "Loaded ..."` line) and BEFORE the `if (!empty($abproviders))` A/B block, insert:
```php
if ($judgemode) {
    // ---------- Judge mode: label-free relevance grading ----------
    $qpath = $questionspath !== ''
        ? (($questionspath[0] === '/') ? $questionspath : __DIR__ . '/../../' . $questionspath)
        : __DIR__ . '/../../tests/golden/rag_fixtures_synthetic_1000.json';
    $qdoc = json_decode((string) file_get_contents($qpath), true);
    $qsrc = $qdoc['questions'] ?? $qdoc['fixtures'] ?? [];
    $qitems = [];
    foreach ($qsrc as $i => $row) {
        if (!empty($row['question']) && !empty($row['courseid'])) {
            $qitems[] = [
                'id'       => (string) ($row['id'] ?? $i),
                'courseid' => (int) $row['courseid'],
                'question' => (string) $row['question'],
            ];
        }
    }
    usort($qitems, fn($a, $b) => strcmp($a['id'], $b['id']));
    $qitems = array_slice($qitems, 0, $samplesize);

    $judgekey = get_config('local_ai_course_assistant', 'embed_apikey');
    if ($judgekey === false || $judgekey === '') {
        $judgekey = (string) get_config('local_ai_course_assistant', 'apikey');
    }
    if ($openaiapikey !== '') {
        $judgekey = $openaiapikey;
    }
    if ($judgekey === '') {
        fwrite(STDERR, "ERROR: no OpenAI key for the judge (set embed_apikey/apikey or --openai-apikey)\n");
        exit(1);
    }

    echo "JUDGE MODE: " . count($qitems) . " questions, judge={$judgemodel}, top-k={$topk}\n";
    echo str_repeat('=', 64) . "\n\n";

    // Family A: pipeline-config arms via the real retriever.
    $famA = [
        ['label' => 'return=chunk', 'cfg' => ['rag_return_scope' => 'chunk']],
        ['label' => 'return=page',  'cfg' => ['rag_return_scope' => 'page']],
        ['label' => 'rerank=off',   'cfg' => ['rerank_enabled' => '0']],
        ['label' => 'rerank=on',    'cfg' => ['rerank_enabled' => '1']],
    ];
    $touched = ['rag_return_scope', 'rerank_enabled'];
    $origA = [];
    foreach ($touched as $key) {
        $origA[$key] = get_config('local_ai_course_assistant', $key);
    }
    $restoreA = function () use ($origA) {
        foreach ($origA as $k => $v) {
            set_config($k, ($v === false) ? null : $v, 'local_ai_course_assistant');
        }
    };
    register_shutdown_function($restoreA);

    $resultsA = [];
    foreach ($famA as $arm) {
        $restoreA();
        foreach ($arm['cfg'] as $k => $v) {
            set_config($k, $v, 'local_ai_course_assistant');
        }
        $per = judge_arm($qitems, $topk, $judgemodel, $judgekey);
        $resultsA[] = ['arm' => $arm['label']] + $per;
        printf("  %-13s nDCG@%d=%.3f  P@%d=%.3f  hit@%d=%.3f  mean=%.2f  (scored %d, judge-err %d)\n",
            $arm['label'], $topk, $per['ndcg'], $topk, $per['precision'], $topk, $per['hit'],
            $per['mean_rel'], $per['scored'], $per['errors']);
    }
    $restoreA();

    echo "\n" . str_repeat('=', 64) . "\n";
    echo "FAMILY A (pipeline config, via live retriever)\n";
    echo str_repeat('=', 64) . "\n";
    $hdr = ['Arm', 'nDCG', 'P@k', 'hit@k', 'mean'];
    echo implode(' | ', array_map(fn($h) => str_pad($h, 13), $hdr)) . "\n";
    foreach ($resultsA as $r) {
        echo implode(' | ', [
            str_pad($r['arm'], 13),
            str_pad(sprintf('%.3f', $r['ndcg']), 13),
            str_pad(sprintf('%.3f', $r['precision']), 13),
            str_pad(sprintf('%.3f', $r['hit']), 13),
            str_pad(sprintf('%.2f', $r['mean_rel']), 13),
        ]) . "\n";
    }
    echo "\n";

    $out = [
        'run_at'     => date('c'),
        'mode'       => 'judge',
        'judge'      => $judgemodel,
        'topk'       => $topk,
        'n'          => count($qitems),
        'family_a'   => $resultsA,
    ];
    file_put_contents($outfile, json_encode($out, JSON_PRETTY_PRINT));
    echo "Results written to: {$outfile}\n";
    exit(0);
}
```

- [ ] **Step 4: Lint**

Run: `/opt/homebrew/opt/php@8.3/bin/php -l admin/cli/run_rag_fixture_benchmark.php`
Expected: no syntax errors.

- [ ] **Step 5: Dev smoke (controller runs this — implementer just confirms lint)**

The end-to-end run happens on dev via SSM (has chunks, keys, retriever), sampled small:
`sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --judge --sample=8`
Expected: prints a Family A table with 4 arms; `return=page` and `rerank=on` show nDCG/hit >= their off/chunk counterparts within noise; judge-err small; config restored afterward. (Implementer: confirm `php -l` only; note that the dev smoke is the controller's step.)

- [ ] **Step 6: Commit**

```bash
git add admin/cli/run_rag_fixture_benchmark.php
git commit -m "feat(rag): --judge mode + Family A (config arms via retriever)"
```

---

### Task 3: Family B (embedding-provider arms via re-embed)

**Files:**
- Modify: `admin/cli/run_rag_fixture_benchmark.php` (inside the `--judge` block, before the JSON write)

**Interfaces:**
- Consumes: `base_embedding_provider::create_from_config`, `embed_batch`/`embed_query`/`embed`, `cosine_sim`, `judge_passages`, `rag_judge`.
- Produces: a Family B arms table + `family_b` in the run JSON.

- [ ] **Step 1: Add the Family B block**

Inside the `if ($judgemode)` block, AFTER the Family A table print and BEFORE building `$out`, insert:
```php
    // Family B: embedding-provider arms via in-memory re-embed (bare cosine,
    // no rerank/scope/parent-doc). Comparable to the embedding A/B, judged.
    $famB = [
        ['label' => 'openai:3-small', 'prov' => 'openai', 'model' => 'text-embedding-3-small', 'dim' => 1536, 'key' => ($openaiapikey ?: $judgekey)],
        ['label' => 'voyage:3.5',     'prov' => 'voyage', 'model' => 'voyage-3.5',            'dim' => 1024, 'key' => $voyageapikey],
    ];
    $origB = [
        'embed_provider'   => get_config('local_ai_course_assistant', 'embed_provider'),
        'embed_model'      => get_config('local_ai_course_assistant', 'embed_model'),
        'embed_apikey'     => get_config('local_ai_course_assistant', 'embed_apikey'),
        'embed_dimensions' => get_config('local_ai_course_assistant', 'embed_dimensions'),
    ];
    $restoreB = function () use ($origB) {
        foreach ($origB as $k => $v) {
            set_config($k, ($v === false) ? null : $v, 'local_ai_course_assistant');
        }
    };
    register_shutdown_function($restoreB);

    // Preload chunk contents for the courses referenced by the sampled questions.
    $courseids = array_values(array_unique(array_map(fn($q) => $q['courseid'], $qitems)));
    $bcontents = [];
    foreach ($courseids as $cid) {
        $rows = $DB->get_records_select('local_ai_course_assistant_chunks',
            'courseid = :cid', ['cid' => $cid], '', 'id, content');
        foreach ($rows as $row) {
            if (trim((string) $row->content) !== '') {
                $bcontents[$cid][(int) $row->id] = (string) $row->content;
            }
        }
    }

    $resultsB = [];
    foreach ($famB as $arm) {
        if (empty($arm['key'])) {
            echo "  (skip {$arm['label']}: no API key)\n";
            continue;
        }
        $restoreB();
        set_config('embed_provider', $arm['prov'], 'local_ai_course_assistant');
        set_config('embed_model', $arm['model'], 'local_ai_course_assistant');
        set_config('embed_dimensions', $arm['dim'], 'local_ai_course_assistant');
        set_config('embed_apikey', $arm['key'], 'local_ai_course_assistant');
        try {
            $prov = base_embedding_provider::create_from_config();
        } catch (\Throwable $e) {
            echo "  (skip {$arm['label']}: provider error: " . mb_substr($e->getMessage(), 0, 100) . ")\n";
            $restoreB();
            continue;
        }
        $isvoyage = $prov instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider;

        // Re-embed each course's chunk contents (document vectors), sliced.
        $vecs = [];
        foreach ($bcontents as $cid => $contents) {
            $ids = array_keys($contents);
            $texts = array_values($contents);
            for ($off = 0; $off < count($texts); $off += $abbatch) {
                $embs = $prov->embed_batch(array_slice($texts, $off, $abbatch));
                foreach (array_slice($ids, $off, $abbatch) as $j => $chunkid) {
                    $vecs[$cid][$chunkid] = $embs[$j];
                }
            }
        }

        $ndcg = $prec = $hit = $mean = 0.0; $scored = 0; $errors = 0;
        foreach ($qitems as $q) {
            $cid = $q['courseid'];
            $qvec = $isvoyage ? $prov->embed_query($q['question']) : $prov->embed($q['question']);
            if (empty($qvec) || empty($vecs[$cid])) { $scored++; continue; }
            $scoredchunks = [];
            foreach ($vecs[$cid] as $chunkid => $vec) {
                $scoredchunks[] = ['id' => $chunkid, 's' => cosine_sim($qvec, $vec)];
            }
            usort($scoredchunks, fn($a, $b) => $b['s'] <=> $a['s']);
            $passages = [];
            foreach (array_slice($scoredchunks, 0, $topk) as $sc) {
                $passages[] = $bcontents[$cid][$sc['id']];
            }
            $grades = judge_passages($q['question'], $passages, $judgemodel, $judgekey);
            if ($grades === null) { $errors++; continue; }
            $ndcg += \local_ai_course_assistant\rag_judge::ndcg_at_k($grades, $topk);
            $prec += \local_ai_course_assistant\rag_judge::precision_at_k($grades, $topk);
            $hit  += \local_ai_course_assistant\rag_judge::hit_at_k($grades, $topk);
            $mean += \local_ai_course_assistant\rag_judge::mean_relevance($grades, $topk);
            $scored++;
        }
        $n = max(1, $scored);
        $resultsB[] = ['arm' => $arm['label'], 'ndcg' => $ndcg / $n, 'precision' => $prec / $n,
                       'hit' => $hit / $n, 'mean_rel' => $mean / $n, 'scored' => $scored, 'errors' => $errors];
        printf("  %-15s nDCG@%d=%.3f  P@%d=%.3f  hit@%d=%.3f  mean=%.2f  (scored %d, judge-err %d)\n",
            $arm['label'], $topk, $ndcg / $n, $topk, $prec / $n, $topk, $hit / $n, $mean / $n, $scored, $errors);
        $restoreB();
    }
    $restoreB();

    echo "\n" . str_repeat('=', 64) . "\n";
    echo "FAMILY B (embedding provider, in-memory re-embed, bare cosine)\n";
    echo str_repeat('=', 64) . "\n";
    foreach ($resultsB as $r) {
        printf("  %-15s nDCG=%.3f  P@k=%.3f  hit@k=%.3f  mean=%.2f\n",
            $r['arm'], $r['ndcg'], $r['precision'], $r['hit'], $r['mean_rel']);
    }
    echo "\n";
```
Then change the `$out` array (Task 2 Step 3) to also carry Family B — replace `'family_a' => $resultsA,` with:
```php
        'family_a'   => $resultsA,
        'family_b'   => $resultsB,
```

- [ ] **Step 2: Lint**

Run: `/opt/homebrew/opt/php@8.3/bin/php -l admin/cli/run_rag_fixture_benchmark.php`
Expected: no syntax errors.

- [ ] **Step 3: Dev smoke (controller step)**

On dev via SSM, with both keys available server-side:
`sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --judge --sample=8 --openai-apikey=<embed_apikey> --voyage-apikey=<rerank_apikey>`
Expected: Family A table (4 arms) + Family B table (openai vs voyage); Voyage arm skips cleanly if no key; config (embed_* and rag_*) restored afterward (verify `embed_provider`=openai, `rag_return_scope` unchanged).

- [ ] **Step 4: Commit**

```bash
git add admin/cli/run_rag_fixture_benchmark.php
git commit -m "feat(rag): --judge Family B (embedding-provider re-embed arms)"
```

---

## Self-Review

**Spec coverage:** `--judge` mode (Task 2); Family A chunk/page + rerank via `retrieve()` (Task 2); Family B openai/voyage via re-embed (Task 3); nDCG/precision/hit/mean (Task 1); judge = gpt-4o-mini batched 0-3 with length-checked parse and error counting (Tasks 1-2); `--sample` deterministic (Task 2); synthetic questions default input (Task 2); config restore on exit for both families (Tasks 2-3); JSON + tables output (Tasks 2-3); dev run (smoke steps). Covered.

**Placeholder scan:** the GPL header in Task 1 Step 3 says "[GPL v3 boilerplate matching other classes]" — the implementer copies the exact header block from an existing `classes/*.php`; every logic line is complete.

**Type consistency:** `rag_judge` method names/signatures match between Task 1 (definition + tests) and their calls in Tasks 2-3. `judge_passages(string,array,string,string): ?array` and `judge_arm(array,int,string,string): array` are consistent between definition (Task 2) and use. Config keys `rag_return_scope`/`rerank_enabled` (Family A) and `embed_*` (Family B) match `rag_retriever`/`base_embedding_provider` reads. `$abbatch`, `$topk`, `$outfile`, `$openaiapikey`, `$voyageapikey`, `$DB`, `cosine_sim`, `register_shutdown_function` are all pre-existing in the CLI.

**Note:** Family A and Family B are separate comparisons (different retrieval paths) and print as two tables — do not read a Family-A arm against a Family-B arm.
