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
 * SOLA RAG fixture benchmark harness.
 *
 * Measures retrieval quality of the embedding-only arm (cosine similarity
 * on text-embedding-3-small vectors) against the optional two-stage arm
 * (embedding top-N candidates then Voyage rerank-2.5 cross-encoder).
 *
 * For each fixture in tests/golden/rag_fixtures_bus101_pol101.json:
 *   (a) Embeds the question and ranks all course chunks by cosine similarity;
 *       records the rank of the expected chunk and latency.
 *   (b) If a Voyage rerank API key is available, applies rerank-2.5 to the
 *       top 50 cosine candidates and records the new rank and added latency.
 *
 * Computes per-arm: Recall@1, Recall@3, Recall@5, MRR (mean reciprocal rank),
 * broken down by course and overall. Also reports P50/P95 latency and estimated
 * per-query rerank cost at $0.05/MTok.
 *
 * IMPORTANT: This script reads config but does NOT mutate it. It accepts an
 * optional --embed-apikey override for deployments where embed_apikey is not
 * configured in the plugin settings (e.g. the dev site uses the main apikey).
 * The override is held in memory only and never written to the database.
 *
 * Usage:
 *   sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php
 *   sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --embed-apikey=sk-...
 *   sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --fixtures=tests/golden/rag_fixtures_bus101_pol101.json
 *   sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --candidates=50 --topk=10
 *   sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --out=runs/2026-06-10-rag-bench.json
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG, $DB;
require_once($CFG->dirroot . '/lib/filelib.php');

use local_ai_course_assistant\embedding_provider\base_embedding_provider;
use local_ai_course_assistant\embedding_provider\voyage_reranker;

// ---------- CLI argument parsing ----------

$fixturespath = '';
$embedapikeyoverride = '';
$candidates = 50;
$topk = 10;
$outfile = '';
$rerankdelayms = 0;

// Embedding A/B mode (2026-07-08): each --embed-provider adds an arm to a
// head-to-head embedding-only recall comparison. Repeatable. When any arm is
// supplied the script re-embeds the fixture courses' chunk contents with each
// provider and ignores the legacy single-arm / rerank path.
$abproviders = [];
$openaiapikey = '';
$voyageapikey = '';
$abbatch = 96; // Chunk re-embed slice size; bounds per-call token usage for both providers.

$judgemode     = false;
$questionspath = '';
$samplesize    = 100;
$judgemodel    = 'gpt-4o-mini';

foreach ($argv as $arg) {
    if (preg_match('/^--fixtures=(.+)$/', $arg, $m)) {
        $fixturespath = trim($m[1]);
    } else if (preg_match('/^--embed-apikey=(.+)$/', $arg, $m)) {
        $embedapikeyoverride = trim($m[1]);
    } else if (preg_match('/^--candidates=(\d+)$/', $arg, $m)) {
        $candidates = max(10, (int) $m[1]);
    } else if (preg_match('/^--topk=(\d+)$/', $arg, $m)) {
        $topk = max(1, min(20, (int) $m[1]));
    } else if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outfile = trim($m[1]);
    } else if (preg_match('/^--rerank-delay-ms=(\d+)$/', $arg, $m)) {
        $rerankdelayms = max(0, (int) $m[1]);
    } else if (preg_match('/^--embed-provider=(.+)$/', $arg, $m)) {
        $abproviders[] = trim($m[1]);
    } else if (preg_match('/^--openai-apikey=(.+)$/', $arg, $m)) {
        $openaiapikey = trim($m[1]);
    } else if (preg_match('/^--voyage-apikey=(.+)$/', $arg, $m)) {
        $voyageapikey = trim($m[1]);
    } else if ($arg === '--judge') {
        $judgemode = true;
    } else if (preg_match('/^--questions=(.+)$/', $arg, $m)) {
        $questionspath = trim($m[1]);
    } else if (preg_match('/^--sample=(\d+)$/', $arg, $m)) {
        $samplesize = max(1, (int) $m[1]);
    } else if (preg_match('/^--judge-model=(.+)$/', $arg, $m)) {
        $judgemodel = trim($m[1]);
    } else if ($arg === '--help' || $arg === '-h') {
        echo <<<TXT
Usage: php run_rag_fixture_benchmark.php [options]

Options:
  --fixtures=PATH       Path to fixture JSON (default: tests/golden/rag_fixtures_bus101_pol101.json)
  --embed-apikey=KEY    Override embed_apikey in memory (not written to DB)
  --candidates=N        Embedding-stage candidate pool for reranker (default 50)
  --topk=N              Final top-k retrieved (default 10; max 20)
  --out=PATH            Output JSON path (default: runs/YYYY-MM-DD-rag-bench.json)
  --rerank-delay-ms=N   Sleep N ms before each rerank call (default 0). Use ~21000
                        on a free-tier Voyage key (~3 requests/minute).

Embedding A/B mode (repeatable --embed-provider switches on a head-to-head
embedding-only recall comparison; the rerank arm is skipped in this mode):
  --embed-provider=SPEC provider[:model], e.g. openai:text-embedding-3-small,
                        voyage:voyage-3.5, voyage:voyage-4. Repeat to add arms.
                        Each arm RE-EMBEDS the fixture courses' chunk contents
                        with that provider, so arms compare like against like;
                        the stored vectors are ignored. Site config is set per
                        arm and restored at exit.
  --openai-apikey=KEY   API key used for openai:* arms (in memory only).
  --voyage-apikey=KEY   API key used for voyage:* arms (in memory only).

  Example three-way A/B:
    php run_rag_fixture_benchmark.php \
      --embed-provider=openai:text-embedding-3-small \
      --embed-provider=voyage:voyage-3.5 \
      --embed-provider=voyage:voyage-4 \
      --openai-apikey=sk-... --voyage-apikey=pa-...

Judge mode (--judge): LLM-judged relevance across pipeline configs.
  Family A (before/after via the live retriever, embeddings held constant):
    baseline (prod default) | rerank-only | parent-only | full:window | full:page.
    The 'full:*' arms turn on Voyage rerank-2.5 + parent-document expansion
    together; pass --voyage-apikey so the rerank arms actually exercise Voyage.
  Family B: OpenAI vs Voyage embeddings (bare cosine, in-memory re-embed).
  Example:
    php run_rag_fixture_benchmark.php --judge --sample=40 \
      --openai-apikey=sk-... --voyage-apikey=pa-...

TXT;
        exit(0);
    }
}

if ($fixturespath === '') {
    $fixturespath = __DIR__ . '/../../tests/golden/rag_fixtures_bus101_pol101.json';
} else if ($fixturespath[0] !== '/') {
    $fixturespath = __DIR__ . '/../../' . $fixturespath;
}

if ($outfile === '') {
    $outdir = __DIR__ . '/../../runs';
    if (!is_dir($outdir)) {
        mkdir($outdir, 0775, true);
    }
    $outfile = $outdir . '/' . date('Y-m-d-His') . '-rag-bench.json';
} else if ($outfile[0] !== '/') {
    $outfile = __DIR__ . '/../../' . $outfile;
}

// ---------- Load fixtures ----------

$rawjson = file_get_contents($fixturespath);
if ($rawjson === false) {
    fwrite(STDERR, "ERROR: cannot read fixture file: {$fixturespath}\n");
    exit(1);
}
$fixturedoc = json_decode($rawjson, true);
if (!is_array($fixturedoc) || empty($fixturedoc['fixtures'])) {
    fwrite(STDERR, "ERROR: fixture file is malformed or has no fixtures: {$fixturespath}\n");
    exit(1);
}
$fixtures = $fixturedoc['fixtures'];
echo "Loaded " . count($fixtures) . " fixtures from " . basename($fixturespath) . "\n";

// ---------- Judge mode (2026-07-18): label-free LLM relevance grading ----------
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

    // Family A: pipeline-config arms via the real retriever (embeddings held
    // constant at whatever is indexed — OpenAI in prod). This is the full-stack
    // BEFORE/AFTER: 'baseline' is today's production default (no rerank, single
    // chunk); the 'full:*' arms turn on Voyage rerank-2.5 + parent-document
    // expansion together. 'rerank-only' and 'parent-only' isolate each factor
    // so the combined lift can be attributed. Window vs page compares the two
    // parent-document return scopes under rerank.
    $famA = [
        ['label' => 'baseline',    'cfg' => ['rerank_enabled' => '0', 'rag_return_scope' => 'chunk']],
        ['label' => 'rerank-only', 'cfg' => ['rerank_enabled' => '1', 'rag_return_scope' => 'chunk']],
        ['label' => 'parent-only', 'cfg' => ['rerank_enabled' => '0', 'rag_return_scope' => 'window', 'rag_window_size' => '1']],
        ['label' => 'full:window', 'cfg' => ['rerank_enabled' => '1', 'rag_return_scope' => 'window', 'rag_window_size' => '1']],
        ['label' => 'full:page',   'cfg' => ['rerank_enabled' => '1', 'rag_return_scope' => 'page']],
    ];
    $touched = ['rag_return_scope', 'rerank_enabled', 'rag_window_size', 'rerank_apikey'];
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
        // Ensure rerank arms actually exercise Voyage rerank-2.5 even when the
        // site's live rerank key is unset, by using --voyage-apikey when given.
        // Without a key the reranker no-ops and the arm silently equals cosine.
        if (($arm['cfg']['rerank_enabled'] ?? '0') === '1' && $voyageapikey !== '') {
            set_config('rerank_apikey', $voyageapikey, 'local_ai_course_assistant');
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

    // Family C: full-stack before/after (in-memory re-embed + REAL Voyage
    // rerank-2.5 + REAL parent-document expansion via rag_retriever::merge_parents).
    // Isolates the embedding provider's contribution to the whole pipeline:
    //   before(oa,bare) = OpenAI 3-small, no rerank, single chunk (prod default)
    //   full(oa)        = OpenAI 3-small + rerank + parent-doc(window)
    //   full(voyage)    = Voyage 3.5     + rerank + parent-doc(window)
    // No DB writes: vectors are in-memory; the reranker and merge_parents are the
    // real production components. Rerank arms need a Voyage key (--voyage-apikey).
    $famC = [
        ['label' => 'before(oa,bare)', 'prov' => 'openai', 'model' => 'text-embedding-3-small', 'dim' => 1536, 'key' => ($openaiapikey ?: $judgekey), 'rerank' => false, 'scope' => 'chunk'],
        ['label' => 'full(oa)',        'prov' => 'openai', 'model' => 'text-embedding-3-small', 'dim' => 1536, 'key' => ($openaiapikey ?: $judgekey), 'rerank' => true,  'scope' => 'window'],
        ['label' => 'full(voyage)',    'prov' => 'voyage', 'model' => 'voyage-3.5',             'dim' => 1024, 'key' => $voyageapikey,               'rerank' => true,  'scope' => 'window'],
    ];
    $ckeys = ['embed_provider', 'embed_model', 'embed_dimensions', 'embed_apikey',
              'rerank_enabled', 'rag_return_scope', 'rag_window_size', 'rerank_apikey'];
    $origC = [];
    foreach ($ckeys as $k) { $origC[$k] = get_config('local_ai_course_assistant', $k); }
    $restoreC = function () use ($origC) {
        foreach ($origC as $k => $v) { set_config($k, ($v === false) ? null : $v, 'local_ai_course_assistant'); }
    };
    register_shutdown_function($restoreC);

    // Rich chunk metadata (content + cmid + chunkindex) for parent-doc expansion.
    $richchunks = []; // cid -> [chunkid => ['content','cmid','chunkindex']]
    foreach ($courseids as $cid) {
        $rows = $DB->get_records_select('local_ai_course_assistant_chunks',
            'courseid = :cid', ['cid' => $cid], 'cmid, chunkindex',
            'id, content, cmid, chunkindex');
        foreach ($rows as $row) {
            if (trim((string) $row->content) === '') { continue; }
            $richchunks[$cid][(int) $row->id] = [
                'content'    => (string) $row->content,
                'cmid'       => (int) ($row->cmid ?? 0),
                'chunkindex' => (int) ($row->chunkindex ?? 0),
            ];
        }
    }

    $resultsC = [];
    foreach ($famC as $arm) {
        if (empty($arm['key'])) { echo "  (skip {$arm['label']}: no embedding key)\n"; continue; }
        if ($arm['rerank'] && $voyageapikey === '') { echo "  (skip {$arm['label']}: rerank needs --voyage-apikey)\n"; continue; }
        $restoreC();
        set_config('embed_provider', $arm['prov'], 'local_ai_course_assistant');
        set_config('embed_model', $arm['model'], 'local_ai_course_assistant');
        set_config('embed_dimensions', $arm['dim'], 'local_ai_course_assistant');
        set_config('embed_apikey', $arm['key'], 'local_ai_course_assistant');
        set_config('rerank_enabled', $arm['rerank'] ? '1' : '0', 'local_ai_course_assistant');
        set_config('rag_return_scope', $arm['scope'], 'local_ai_course_assistant');
        set_config('rag_window_size', '1', 'local_ai_course_assistant');
        if ($arm['rerank']) { set_config('rerank_apikey', $voyageapikey, 'local_ai_course_assistant'); }
        try {
            $prov = base_embedding_provider::create_from_config();
        } catch (\Throwable $e) {
            echo "  (skip {$arm['label']}: provider error: " . mb_substr($e->getMessage(), 0, 100) . ")\n";
            $restoreC(); continue;
        }
        $isvoyage = $prov instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider;
        $reranker = $arm['rerank'] ? new \local_ai_course_assistant\embedding_provider\voyage_reranker() : null;

        // Re-embed each course's chunk contents (document vectors), in-memory.
        $vecs = [];
        foreach ($richchunks as $cid => $chunks) {
            $ids = array_keys($chunks);
            $texts = array_map(fn($c) => $c['content'], $chunks);
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
            if (empty($vecs[$cid])) { $scored++; continue; }
            $qvec = $isvoyage ? $prov->embed_query($q['question']) : $prov->embed($q['question']);
            if (empty($qvec)) { $scored++; continue; }
            $sc = [];
            foreach ($vecs[$cid] as $chunkid => $vec) {
                $sc[] = ['id' => $chunkid, 's' => cosine_sim($qvec, $vec)];
            }
            usort($sc, fn($a, $b) => $b['s'] <=> $a['s']);

            if ($arm['rerank']) {
                $candn = max($topk, min($candidates, count($sc)));
                $cand = array_slice($sc, 0, $candn);
                $docs = array_map(fn($x) => $richchunks[$cid][$x['id']]['content'], $cand);
                if ($rerankdelayms > 0) { usleep($rerankdelayms * 1000); }
                try {
                    $rr = $reranker->rerank($q['question'], $docs, $topk);
                } catch (\Throwable $e) {
                    echo "  ({$arm['label']} rerank error: " . mb_substr($e->getMessage(), 0, 80) . ")\n";
                    $errors++; continue;
                }
                $winners = [];
                foreach ($rr as $e) { if (isset($cand[$e['index']])) { $winners[] = $cand[$e['index']]; } }
            } else {
                $winners = array_slice($sc, 0, $topk);
            }

            $rows = [];
            foreach ($winners as $w) {
                $c = $richchunks[$cid][$w['id']];
                $rows[] = ['content' => $c['content'], 'cmid' => $c['cmid'], 'chunkindex' => $c['chunkindex']];
            }
            if ($arm['scope'] !== 'chunk' && !empty($rows)) {
                $cmids = array_unique(array_map(fn($r) => $r['cmid'], $rows));
                $siblings = [];
                foreach ($richchunks[$cid] as $c) {
                    if (in_array($c['cmid'], $cmids, true)) {
                        $siblings[$c['cmid']][] = ['content' => $c['content'], 'chunkindex' => $c['chunkindex']];
                    }
                }
                $rows = \local_ai_course_assistant\rag_retriever::merge_parents($rows, $siblings, $arm['scope'], 1, 6000);
            }
            $passages = array_map(fn($r) => (string) $r['content'], $rows);
            if (empty($passages)) { $scored++; continue; }
            $grades = judge_passages($q['question'], $passages, $judgemodel, $judgekey);
            if ($grades === null) { $errors++; continue; }
            $ndcg += \local_ai_course_assistant\rag_judge::ndcg_at_k($grades, $topk);
            $prec += \local_ai_course_assistant\rag_judge::precision_at_k($grades, $topk);
            $hit  += \local_ai_course_assistant\rag_judge::hit_at_k($grades, $topk);
            $mean += \local_ai_course_assistant\rag_judge::mean_relevance($grades, $topk);
            $scored++;
        }
        $n = max(1, $scored);
        $resultsC[] = ['arm' => $arm['label'], 'ndcg' => $ndcg / $n, 'precision' => $prec / $n,
                       'hit' => $hit / $n, 'mean_rel' => $mean / $n, 'scored' => $scored, 'errors' => $errors];
        printf("  %-16s nDCG@%d=%.3f  P@%d=%.3f  hit@%d=%.3f  mean=%.2f  (scored %d, judge-err %d)\n",
            $arm['label'], $topk, $ndcg / $n, $topk, $prec / $n, $topk, $hit / $n, $mean / $n, $scored, $errors);
        $restoreC();
    }
    $restoreC();

    echo "\n" . str_repeat('=', 64) . "\n";
    echo "FAMILY C (full-stack: embeddings + Voyage rerank + parent-doc, in-memory)\n";
    echo str_repeat('=', 64) . "\n";
    foreach ($resultsC as $r) {
        printf("  %-16s nDCG=%.3f  P@k=%.3f  hit@k=%.3f  mean=%.2f\n",
            $r['arm'], $r['ndcg'], $r['precision'], $r['hit'], $r['mean_rel']);
    }
    echo "\n";

    $out = [
        'run_at'     => date('c'),
        'mode'       => 'judge',
        'judge'      => $judgemodel,
        'topk'       => $topk,
        'n'          => count($qitems),
        'family_a'   => $resultsA,
        'family_b'   => $resultsB,
        'family_c'   => $resultsC,
    ];
    file_put_contents($outfile, json_encode($out, JSON_PRETTY_PRINT));
    echo "Results written to: {$outfile}\n";
    exit(0);
}

// ---------- Embedding provider A/B mode (2026-07-08) ----------
// When one or more --embed-provider arms are supplied, run a head-to-head
// embedding-only recall comparison. Each arm RE-EMBEDS the fixture courses'
// chunk CONTENTS with that provider (document vectors) and each query (query
// vectors for Voyage), so every arm compares like against like -- the stored
// OpenAI vectors are ignored. Site embedding config is set per arm and always
// restored (a shutdown hook restores even on a fatal), so the plugin's stored
// configuration is unchanged once the CLI exits.
if (!empty($abproviders)) {
    echo "\n" . str_repeat('=', 64) . "\n";
    echo "EMBEDDING A/B MODE: " . count($abproviders) . " arm(s): " . implode(', ', $abproviders) . "\n";
    echo str_repeat('=', 64) . "\n\n";

    // Preload chunk CONTENTS (not stored vectors) for the fixture courses.
    $abcontents = []; // courseid -> [chunkid => content]
    $abcourseids = array_unique(array_column($fixtures, 'courseid'));
    foreach ($abcourseids as $courseid) {
        $rows = $DB->get_records_select(
            'local_ai_course_assistant_chunks',
            'courseid = :cid',
            ['cid' => $courseid],
            '',
            'id, content'
        );
        $abcontents[$courseid] = [];
        foreach ($rows as $row) {
            if (trim((string) $row->content) !== '') {
                $abcontents[$courseid][(int) $row->id] = (string) $row->content;
            }
        }
        echo "Loaded " . count($abcontents[$courseid]) . " chunk contents for courseid={$courseid}\n";
    }
    echo "\n";

    // Capture original embedding config and guarantee restoration.
    $aborigcfg = [
        'embed_provider'   => get_config('local_ai_course_assistant', 'embed_provider'),
        'embed_model'      => get_config('local_ai_course_assistant', 'embed_model'),
        'embed_apikey'     => get_config('local_ai_course_assistant', 'embed_apikey'),
        'embed_dimensions' => get_config('local_ai_course_assistant', 'embed_dimensions'),
    ];
    $abrestore = function () use ($aborigcfg) {
        foreach ($aborigcfg as $k => $v) {
            set_config($k, ($v === false) ? null : $v, 'local_ai_course_assistant');
        }
    };
    // Restore even if a fatal aborts mid-arm; idempotent with the per-arm restore.
    register_shutdown_function($abrestore);

    $absummaries = [];
    $abperfixture = [];

    foreach ($abproviders as $spec) {
        [$prov, $model] = array_pad(explode(':', $spec, 2), 2, '');
        $prov = strtolower(trim($prov));
        $model = trim($model);
        $armkey = ($prov === 'voyage') ? $voyageapikey : $openaiapikey;

        echo str_repeat('-', 64) . "\n";
        echo "ARM: {$spec}  (provider={$prov}, model=" . ($model !== '' ? $model : '(default)') . ")\n";
        echo str_repeat('-', 64) . "\n";

        // Apply this arm's config (restored after the arm). Set embed_dimensions
        // to the arm provider's NATIVE default width: OpenAI 1536, Voyage 1024.
        // The base provider hard-defaults an unset width to 1536, which Voyage
        // rejects (its MRL widths are only 256/512/1024/2048), so the width must
        // be pinned per provider rather than left unset.
        $armdim = ($prov === 'voyage') ? 1024 : 1536;
        set_config('embed_provider', $prov, 'local_ai_course_assistant');
        set_config('embed_model', ($model !== '') ? $model : null, 'local_ai_course_assistant');
        set_config('embed_dimensions', $armdim, 'local_ai_course_assistant');
        if ($armkey !== '') {
            set_config('embed_apikey', $armkey, 'local_ai_course_assistant');
        }

        try {
            $armprovider = base_embedding_provider::create_from_config();
        } catch (\Throwable $e) {
            echo "  ERROR building provider: " . $e->getMessage() . "\n\n";
            $abrestore();
            continue;
        }
        $armmodel = $armprovider->get_model();
        $isvoyage = $armprovider instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider;
        echo "  class=" . get_class($armprovider) . "  model={$armmodel}\n";

        // Re-embed all chunk contents for each course (document vectors), sliced
        // to bound per-call token usage regardless of the provider batch size.
        $armvecs = []; // courseid -> [chunkid => vec]
        $embedfail = false;
        $t0embed = microtime(true);
        foreach ($abcontents as $courseid => $contents) {
            $ids = array_keys($contents);
            $texts = array_values($contents);
            $armvecs[$courseid] = [];
            for ($off = 0; $off < count($texts); $off += $abbatch) {
                $sliceids = array_slice($ids, $off, $abbatch);
                $slicetexts = array_slice($texts, $off, $abbatch);
                try {
                    $vecs = $armprovider->embed_batch($slicetexts);
                } catch (\Throwable $e) {
                    echo "  ERROR embedding chunks (course {$courseid}, offset {$off}): "
                        . mb_substr($e->getMessage(), 0, 160) . "\n";
                    $embedfail = true;
                    break 2;
                }
                if (count($vecs) !== count($slicetexts)) {
                    echo "  ERROR: returned " . count($vecs) . " vectors for " . count($slicetexts) . " texts\n";
                    $embedfail = true;
                    break 2;
                }
                foreach ($sliceids as $i => $chunkid) {
                    $armvecs[$courseid][$chunkid] = $vecs[$i];
                }
            }
            echo "  embedded " . count($armvecs[$courseid]) . " chunks for course {$courseid}\n";
        }
        if ($embedfail) {
            $abrestore();
            echo "\n";
            continue;
        }
        $embedsec = round(microtime(true) - $t0embed, 1);
        echo "  chunk re-embed took {$embedsec}s\n";

        // Rank each fixture's query against this arm's re-embedded chunks.
        $ranks = [];
        $perfix = [];
        $qlatencies = [];
        foreach ($fixtures as $fx) {
            $cid = (int) $fx['courseid'];
            $q = (string) $fx['question'];
            $expid = (int) $fx['expected_chunk_id'];
            $expsub = (string) ($fx['expected_substring'] ?? '');
            if (empty($armvecs[$cid])) {
                $ranks[] = null;
                continue;
            }
            $tq = microtime(true);
            try {
                $qvec = $isvoyage ? $armprovider->embed_query($q) : $armprovider->embed($q);
            } catch (\Throwable $e) {
                echo "  query embed error ({$fx['id']}): " . mb_substr($e->getMessage(), 0, 120) . "\n";
                $ranks[] = null;
                continue;
            }
            $qlatencies[] = (int) round((microtime(true) - $tq) * 1000);
            if (empty($qvec)) {
                $ranks[] = null;
                continue;
            }
            $scored = [];
            foreach ($armvecs[$cid] as $chunkid => $vec) {
                $scored[] = ['id' => $chunkid, 'score' => cosine_sim($qvec, $vec)];
            }
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $rank = null;
            $submatch = false;
            foreach ($scored as $i => $e) {
                if ($e['id'] === $expid) {
                    $rank = $i + 1;
                    break;
                }
            }
            if ($rank === null && $expsub !== '') {
                foreach ($scored as $i => $e) {
                    $content = $abcontents[$cid][$e['id']] ?? '';
                    if ($content !== '' && str_contains($content, substr($expsub, 0, 50))) {
                        $rank = $i + 1;
                        $submatch = true;
                        break;
                    }
                }
            }
            $ranks[] = $rank;
            $perfix[] = [
                'fixture_id' => $fx['id'],
                'course'     => $fx['course'],
                'difficulty' => $fx['difficulty'] ?? '',
                'rank'       => $rank,
                'substring_match' => $submatch,
            ];
        }

        $absummaries[] = [
            'arm'             => $spec,
            'provider'        => $prov,
            'model'           => $armmodel,
            'n'               => count($ranks),
            'recall_at_1'     => compute_metrics($ranks, 1)['recall_at_k'],
            'recall_at_3'     => compute_metrics($ranks, 3)['recall_at_k'],
            'recall_at_5'     => compute_metrics($ranks, 5)['recall_at_k'],
            'mrr'             => compute_metrics($ranks, 999)['mrr'],
            'p50_query_ms'    => pct($qlatencies, 50),
            'p95_query_ms'    => pct($qlatencies, 95),
            'chunk_embed_sec' => $embedsec,
        ];
        $abperfixture[$spec] = $perfix;

        $armsum = $absummaries[count($absummaries) - 1];
        printf("  recall@1=%.1f%%  recall@3=%.1f%%  recall@5=%.1f%%  mrr=%.3f\n\n",
            $armsum['recall_at_1'] * 100,
            $armsum['recall_at_3'] * 100,
            $armsum['recall_at_5'] * 100,
            $armsum['mrr']);

        $abrestore();
    }

    // ---------- A/B comparison table ----------
    echo str_repeat('=', 64) . "\n";
    echo "EMBEDDING A/B RESULTS (embedding-only recall, " . count($fixtures) . " fixtures)\n";
    echo str_repeat('=', 64) . "\n\n";
    $hdr = ['Arm', 'Model', 'N', 'R@1', 'R@3', 'R@5', 'MRR', 'P50q'];
    $widths = [26, 22, 4, 7, 7, 7, 7, 7];
    $line = '';
    foreach ($hdr as $i => $h) {
        $line .= str_pad($h, $widths[$i]) . ' ';
    }
    echo $line . "\n" . str_repeat('-', array_sum($widths) + count($widths)) . "\n";
    foreach ($absummaries as $s) {
        $cells = [
            str_pad(substr($s['arm'], 0, 25), $widths[0]),
            str_pad(substr($s['model'], 0, 21), $widths[1]),
            str_pad((string) $s['n'], $widths[2]),
            str_pad(sprintf('%.1f%%', $s['recall_at_1'] * 100), $widths[3]),
            str_pad(sprintf('%.1f%%', $s['recall_at_3'] * 100), $widths[4]),
            str_pad(sprintf('%.1f%%', $s['recall_at_5'] * 100), $widths[5]),
            str_pad(sprintf('%.3f', $s['mrr']), $widths[6]),
            str_pad($s['p50_query_ms'] !== null ? $s['p50_query_ms'] . 'ms' : 'n/a', $widths[7]),
        ];
        echo implode(' ', $cells) . "\n";
    }
    echo "\n";

    // Deltas vs the first arm (treated as the baseline).
    if (count($absummaries) > 1) {
        $base = $absummaries[0];
        echo "Deltas vs baseline (" . $base['arm'] . "):\n";
        foreach (array_slice($absummaries, 1) as $s) {
            printf("  %-26s  R@1 %+.1fpp  R@3 %+.1fpp  R@5 %+.1fpp  MRR %+.3f\n",
                $s['arm'],
                ($s['recall_at_1'] - $base['recall_at_1']) * 100,
                ($s['recall_at_3'] - $base['recall_at_3']) * 100,
                ($s['recall_at_5'] - $base['recall_at_5']) * 100,
                $s['mrr'] - $base['mrr']);
        }
        echo "\n";
    }

    // ---------- Write JSON ----------
    $about = [
        'run_at'       => date('c'),
        'mode'         => 'embedding_ab',
        'fixture_file' => basename($fixturespath),
        'arms'         => $absummaries,
        'per_fixture'  => $abperfixture,
    ];
    file_put_contents($outfile, json_encode($about, JSON_PRETTY_PRINT));
    echo "Results written to: {$outfile}\n";
    exit(0);
}

// ---------- Build embedding provider ----------

// If the caller supplied an override key, apply it via set_config() for the
// duration of the run. base_embedding_provider reads get_config(), so the
// override has to land in plugin config; the original value is captured here
// and restored after the benchmark loop (see the restore block below), so the
// site's stored configuration is unchanged once the CLI exits.
if ($embedapikeyoverride !== '') {
    $origkey = get_config('local_ai_course_assistant', 'embed_apikey');
    set_config('embed_apikey', $embedapikeyoverride, 'local_ai_course_assistant');
    echo "embed_apikey override applied (will be restored at exit)\n";
}

$provider = base_embedding_provider::create_from_config();
$providerclass = get_class($provider);
echo "Embedding provider: {$providerclass} (model=" . $provider->get_model() . ")\n";

// ---------- Check Voyage rerank availability ----------

$reranker = new voyage_reranker();
$rerankavailable = $reranker->is_configured();
echo "Voyage rerank-2.5: " . ($rerankavailable ? "AVAILABLE" : "NOT CONFIGURED (embed arm only)") . "\n";
echo "Candidates (embedding pool for reranker): {$candidates}\n";
echo "Top-K (final returned): {$topk}\n\n";

// ---------- Pre-load all chunk embeddings per course ----------
// We load them once into memory to avoid repeated DB round-trips.

$coursechunks = []; // courseid -> [chunkid -> ['content'=>..., 'vec'=>...]]

$courseids = array_unique(array_column($fixtures, 'courseid'));
foreach ($courseids as $courseid) {
    $rows = $DB->get_records_select(
        'local_ai_course_assistant_chunks',
        'courseid = :cid AND embedding IS NOT NULL',
        ['cid' => $courseid],
        '',
        'id, content, embedding'
    );
    $coursechunks[$courseid] = [];
    foreach ($rows as $row) {
        $vec = json_decode($row->embedding, true);
        if (is_array($vec) && !empty($vec)) {
            $coursechunks[$courseid][$row->id] = [
                'content' => $row->content,
                'vec'     => $vec,
            ];
        }
    }
    echo "Loaded " . count($coursechunks[$courseid]) . " embedded chunks for courseid={$courseid}\n";
}
echo "\n";

// ---------- Helper: cosine similarity ----------

/**
 * Cosine similarity between two float vectors.
 *
 * @param float[] $a
 * @param float[] $b
 * @return float
 */
function cosine_sim(array $a, array $b): float {
    $dot = $norma = $normb = 0.0;
    $len = count($a);
    for ($i = 0; $i < $len; $i++) {
        $ai    = (float) ($a[$i] ?? 0.0);
        $bi    = (float) ($b[$i] ?? 0.0);
        $dot  += $ai * $bi;
        $norma += $ai * $ai;
        $normb += $bi * $bi;
    }
    if ($norma == 0.0 || $normb == 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($norma) * sqrt($normb));
}

// ---------- Judge-mode helpers (2026-07-18) ----------

/**
 * LLM-grade each passage 0-3 for relevance to the question (one batched call).
 * Returns exactly count($passages) grades, or null on judge/parse failure.
 *
 * @param string $question The learner question.
 * @param string[] $passages Retrieved passage texts in rank order.
 * @param string $model Judge model id.
 * @param string $apikey OpenAI API key.
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
 *
 * @param array $qitems Question rows, each ['courseid' => int, 'question' => string].
 * @param int $topk Passages to retrieve and judge per question.
 * @param string $model Judge model id.
 * @param string $key OpenAI API key for the judge.
 * @return array
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

// ---------- Run each fixture ----------

$results = [];
$fixtureidx = 0;

foreach ($fixtures as $fixture) {
    $fixtureidx++;
    $fid     = $fixture['id'];
    $cid     = (int) $fixture['courseid'];
    $q       = $fixture['question'];
    $expid   = (int) $fixture['expected_chunk_id'];
    $expsub  = $fixture['expected_substring'];
    $diff    = $fixture['difficulty'];
    $course  = $fixture['course'];

    printf("[%02d/%02d] %s (%s) - %s\n", $fixtureidx, count($fixtures), $fid, $course, $diff);
    printf("  Q: %s\n", $q);

    // Sanity: does the expected chunk exist in our loaded set?
    if (!isset($coursechunks[$cid][$expid])) {
        echo "  WARNING: expected chunk id {$expid} not found in loaded chunks for course {$cid}\n";
    }

    // ---- Stage 1: Embed query ----
    $t0embed = microtime(true);
    if ($provider instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider) {
        $queryvec = $provider->embed_query($q);
    } else {
        $queryvec = $provider->embed($q);
    }
    $embedlatencyms = (int) round((microtime(true) - $t0embed) * 1000);

    if (empty($queryvec)) {
        echo "  ERROR: embedding returned empty vector; skipping\n";
        $results[] = [
            'fixture_id'     => $fid,
            'course'         => $course,
            'difficulty'     => $diff,
            'error'          => 'embed_empty',
        ];
        continue;
    }

    // ---- Stage 2: Score all chunks by cosine ----
    $t0cosine = microtime(true);
    $scored = [];
    foreach ($coursechunks[$cid] as $chunkid => $chunk) {
        $scored[] = [
            'id'      => $chunkid,
            'content' => $chunk['content'],
            'score'   => cosine_sim($queryvec, $chunk['vec']),
        ];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $cosinelatencyms = (int) round((microtime(true) - $t0cosine) * 1000);
    $totalembedlatencyms = $embedlatencyms + $cosinelatencyms;

    // Find rank of expected chunk in cosine results, and capture its raw
    // cosine score so we can report whether the production relevance floor
    // (rag_min_similarity) would drop it before reranking. (2026-06-13)
    $cosinerank = null;
    $cosinesubmatch = false;
    $targetscore = null;
    foreach ($scored as $rank1 => $entry) {
        if ($entry['id'] === $expid) {
            $cosinerank = $rank1 + 1; // 1-based
            $targetscore = $entry['score'];
            break;
        }
    }
    // Fallback: substring match in case of chunk ID mismatch due to re-index.
    if ($cosinerank === null && $expsub !== '') {
        foreach ($scored as $rank1 => $entry) {
            if (str_contains($entry['content'], substr($expsub, 0, 50))) {
                $cosinerank = $rank1 + 1;
                $cosinesubmatch = true;
                break;
            }
        }
    }

    $top5cosine = array_slice(array_column($scored, 'id'), 0, 5);
    printf("  [embed-only] rank=%s target_cosine=%s latency=%dms top5_ids=[%s]%s\n",
        $cosinerank !== null ? $cosinerank : 'NOT_FOUND',
        $targetscore !== null ? number_format($targetscore, 4) : 'n/a',
        $totalembedlatencyms,
        implode(',', $top5cosine),
        $cosinesubmatch ? ' (substring match)' : ''
    );

    $row = [
        'fixture_id'          => $fid,
        'course'              => $course,
        'difficulty'          => $diff,
        'question'            => $q,
        'expected_chunk_id'   => $expid,
        'cosine_rank'         => $cosinerank,
        'target_cosine_score' => $targetscore,
        'cosine_substring_match' => $cosinesubmatch,
        'embed_latency_ms'    => $embedlatencyms,
        'cosine_latency_ms'   => $cosinelatencyms,
        'total_embed_ms'      => $totalembedlatencyms,
        'top10_cosine_ids'    => array_column(array_slice($scored, 0, 10), 'id'),
        'rerank_rank'         => null,
        'rerank_latency_ms'   => null,
        'rerank_total_tokens' => null,
        'rerank_substring_match' => false,
        'error'               => null,
    ];

    // ---- Optional stage 2: Voyage rerank ----
    if ($rerankavailable) {
        $stage1 = array_slice($scored, 0, $candidates);
        $documents = array_map(fn($r) => $r['content'], $stage1);

        // Pace requests for low-rate-limit keys (Voyage free tier allows ~3
        // requests/minute until a payment method is on file).
        if ($rerankdelayms > 0) {
            usleep($rerankdelayms * 1000);
        }
        $t0rerank = microtime(true);
        try {
            // Retry on 429 with a flat backoff so a transient rate-limit hit
            // does not void the whole arm. Latency is measured per successful
            // attempt only.
            $attempt = 0;
            while (true) {
                try {
                    $t0rerank = microtime(true);
                    $reranked = $reranker->rerank($q, $documents, $topk);
                    break;
                } catch (\Throwable $re) {
                    $attempt++;
                    if ($attempt >= 5 || stripos($re->getMessage(), 'too many requests') === false) {
                        throw $re;
                    }
                    fwrite(STDERR, "  [rerank]     429, retry {$attempt}/5 in 25s\n");
                    sleep(25);
                }
            }
            $reranklatencyms = (int) round((microtime(true) - $t0rerank) * 1000);

            // Count approximate tokens for cost estimate.
            $totaltokens = 0;
            foreach ($documents as $doc) {
                // Rough char-to-token ratio for English: 4 chars per token.
                $totaltokens += (int) ceil(mb_strlen($doc) / 4);
            }
            $totaltokens += (int) ceil(mb_strlen($q) / 4);
            $row['rerank_total_tokens'] = $totaltokens;

            // Find rank of expected chunk in reranked results.
            $rerankrank = null;
            $reranksubmatch = false;
            foreach ($reranked as $rank2 => $entry) {
                $idx = $entry['index'];
                if (isset($stage1[$idx])) {
                    $chunkid = $stage1[$idx]['id'];
                    if ($chunkid === $expid) {
                        $rerankrank = $rank2 + 1;
                        break;
                    }
                    // Substring fallback.
                    if ($expsub !== '' && str_contains($stage1[$idx]['content'], substr($expsub, 0, 50))) {
                        $rerankrank = $rank2 + 1;
                        $reranksubmatch = true;
                        break;
                    }
                }
            }

            $top5rerank = [];
            foreach (array_slice($reranked, 0, 5) as $entry) {
                $idx = $entry['index'];
                if (isset($stage1[$idx])) {
                    $top5rerank[] = $stage1[$idx]['id'];
                }
            }

            $row['rerank_rank']           = $rerankrank;
            $row['rerank_latency_ms']     = $reranklatencyms;
            $row['rerank_substring_match'] = $reranksubmatch;

            printf("  [rerank]     rank=%s latency=%dms (+%dms) top5_ids=[%s]%s\n",
                $rerankrank !== null ? $rerankrank : 'NOT_FOUND',
                $reranklatencyms,
                $reranklatencyms,
                implode(',', $top5rerank),
                $reranksubmatch ? ' (substring match)' : ''
            );
        } catch (\Throwable $e) {
            $row['error'] = 'rerank_error: ' . mb_substr($e->getMessage(), 0, 200);
            printf("  [rerank]     ERROR: %s\n", $row['error']);
        }
    }

    $results[] = $row;
    echo "\n";
}

// Restore embed_apikey if we overrode it. A get_config() miss returns false;
// in that case restore to empty string (the setting's unset-equivalent).
if ($embedapikeyoverride !== '') {
    set_config('embed_apikey', ($origkey === false) ? '' : $origkey, 'local_ai_course_assistant');
    echo "embed_apikey restored to original value\n\n";
}

// ---------- Production floor sanity check (2026-06-13) ----------
// The production retriever (rag_retriever::retrieve) drops chunks scoring below
// rag_min_similarity BEFORE reranking. This harness ranks by raw cosine and does
// not apply that floor, so report which fixtures' target chunks would be dropped
// in production even though they are "recalled" here.
$floorraw = get_config('local_ai_course_assistant', 'rag_min_similarity');
$floor = ($floorraw === false || $floorraw === '') ? 0.25 : (float) $floorraw;
echo str_repeat('=', 64) . "\n";
echo 'PRODUCTION FLOOR CHECK (rag_min_similarity = ' . number_format($floor, 4) . ")\n";
echo str_repeat('=', 64) . "\n";
$below = [];
$notfound = [];
$scoredcount = 0;
$minscore = 1.0;
$maxscore = -1.0;
$sumscore = 0.0;
foreach ($results as $r) {
    if (!array_key_exists('target_cosine_score', $r) || $r['target_cosine_score'] === null) {
        $notfound[] = $r['fixture_id'] ?? '?';
        continue;
    }
    $s = (float) $r['target_cosine_score'];
    $scoredcount++;
    $sumscore += $s;
    $minscore = min($minscore, $s);
    $maxscore = max($maxscore, $s);
    if ($s < $floor) {
        $below[] = sprintf('%s  cos=%.4f  course=%s', $r['fixture_id'] ?? '?', $s, $r['course'] ?? '?');
    }
}
printf("Target-chunk cosine over %d located fixtures: min=%.4f  mean=%.4f  max=%.4f\n",
    $scoredcount, $scoredcount ? $minscore : 0.0,
    $scoredcount ? $sumscore / $scoredcount : 0.0, $scoredcount ? $maxscore : 0.0);
printf("Target chunk BELOW the %.2f floor (would be dropped in production): %d of %d\n",
    $floor, count($below), $scoredcount);
foreach ($below as $b) {
    echo "  - $b\n";
}
if ($notfound) {
    printf("Target chunk not located by cosine at all (unfixable by floor change): %d (%s)\n",
        count($notfound), implode(',', $notfound));
}
echo "\n";

// ---------- Compute metrics ----------

/**
 * Compute Recall@K and MRR for an array of ranks (null = not found).
 *
 * @param array $ranks Array of int|null ranks (1-based).
 * @param int $k
 * @return array{recall_at_k: float, mrr: float}
 */
function compute_metrics(array $ranks, int $k): array {
    $n = count($ranks);
    if ($n === 0) {
        return ['recall_at_k' => 0.0, 'mrr' => 0.0];
    }
    $hits = 0;
    $mrr  = 0.0;
    foreach ($ranks as $rank) {
        if ($rank !== null && $rank <= $k) {
            $hits++;
        }
        if ($rank !== null) {
            $mrr += 1.0 / $rank;
        }
    }
    return [
        'recall_at_k' => $hits / $n,
        'mrr'         => $mrr / $n,
    ];
}

/**
 * Nearest-rank percentile.
 *
 * @param int[] $values
 * @param int $p 0..100
 * @return int|null
 */
function pct(array $values, int $p): ?int {
    if (empty($values)) {
        return null;
    }
    sort($values);
    $idx = (int) ceil(($p / 100) * count($values)) - 1;
    return $values[max(0, min(count($values) - 1, $idx))];
}

// Slice results by course and overall.
$groups = ['overall' => $results];
foreach ($results as $r) {
    $groups[$r['course']][] = $r;
}

$summary = [];

foreach ($groups as $label => $group) {
    $cosineranks = array_map(fn($r) => $r['cosine_rank'] ?? null, $group);
    $rerankranks = array_map(fn($r) => $r['rerank_rank'] ?? null, $group);
    $hasrerank   = $rerankavailable && count(array_filter($rerankranks, fn($x) => $x !== null)) > 0;

    $embedms = array_filter(array_map(fn($r) => $r['total_embed_ms'] ?? null, $group));
    $rerankms = array_filter(array_map(fn($r) => $r['rerank_latency_ms'] ?? null, $group));

    $totaltokens = array_sum(array_filter(array_map(fn($r) => $r['rerank_total_tokens'] ?? 0, $group)));
    $costusd = $totaltokens > 0 ? ($totaltokens / 1000000) * 0.05 : 0.0;
    $costperquery = count($group) > 0 && $totaltokens > 0
        ? ($totaltokens / count($group) / 1000000) * 0.05
        : 0.0;

    $entry = [
        'group'                => $label,
        'n'                    => count($group),
        'cosine_recall_at_1'   => compute_metrics($cosineranks, 1)['recall_at_k'],
        'cosine_recall_at_3'   => compute_metrics($cosineranks, 3)['recall_at_k'],
        'cosine_recall_at_5'   => compute_metrics($cosineranks, 5)['recall_at_k'],
        'cosine_mrr'           => compute_metrics($cosineranks, 999)['mrr'],
        'cosine_p50_embed_ms'  => pct(array_values($embedms), 50),
        'cosine_p95_embed_ms'  => pct(array_values($embedms), 95),
        'rerank_recall_at_1'   => $hasrerank ? compute_metrics($rerankranks, 1)['recall_at_k'] : null,
        'rerank_recall_at_3'   => $hasrerank ? compute_metrics($rerankranks, 3)['recall_at_k'] : null,
        'rerank_recall_at_5'   => $hasrerank ? compute_metrics($rerankranks, 5)['recall_at_k'] : null,
        'rerank_mrr'           => $hasrerank ? compute_metrics($rerankranks, 999)['mrr'] : null,
        'rerank_p50_ms'        => pct(array_values($rerankms), 50),
        'rerank_p95_ms'        => pct(array_values($rerankms), 95),
        'rerank_total_tokens'  => $totaltokens,
        'rerank_cost_usd'      => $costusd,
        'rerank_cost_per_query_usd' => $costperquery,
        'delta_recall_at_3'    => $hasrerank
            ? compute_metrics($rerankranks, 3)['recall_at_k'] - compute_metrics($cosineranks, 3)['recall_at_k']
            : null,
    ];
    $summary[] = $entry;
}

// ---------- Print summary table ----------

echo "\n=== RESULTS SUMMARY ===\n\n";
echo "Embedding model: " . $provider->get_model() . "\n";
echo "Rerank arm: " . ($rerankavailable ? "ACTIVE (rerank-2.5)" : "SKIPPED (no key)") . "\n";
echo "Candidates (N): {$candidates}  Top-K: {$topk}\n\n";

$cols = ['Group', 'N', 'Cos@1', 'Cos@3', 'Cos@5', 'Cos MRR', 'Rnk@1', 'Rnk@3', 'Rnk@5', 'Rnk MRR', 'Delta@3', 'P50emb', 'P50rnk', 'Cost/q'];
echo implode(' | ', array_map(fn($c) => str_pad($c, 8), $cols)) . "\n";
echo str_repeat('-', 8 * count($cols) + 3 * (count($cols) - 1)) . "\n";

foreach ($summary as $s) {
    $pct3 = function($v) { return $v !== null ? sprintf('%.1f%%', $v * 100) : 'n/a'; };
    $ms   = function($v) { return $v !== null ? $v . 'ms' : 'n/a'; };
    $usd  = function($v) { return $v !== null ? sprintf('$%.5f', $v) : 'n/a'; };

    $row = [
        str_pad(substr($s['group'], 0, 8), 8),
        str_pad($s['n'], 8),
        str_pad($pct3($s['cosine_recall_at_1']), 8),
        str_pad($pct3($s['cosine_recall_at_3']), 8),
        str_pad($pct3($s['cosine_recall_at_5']), 8),
        str_pad(sprintf('%.3f', $s['cosine_mrr']), 8),
        str_pad($pct3($s['rerank_recall_at_1']), 8),
        str_pad($pct3($s['rerank_recall_at_3']), 8),
        str_pad($pct3($s['rerank_recall_at_5']), 8),
        str_pad($s['rerank_mrr'] !== null ? sprintf('%.3f', $s['rerank_mrr']) : 'n/a', 8),
        str_pad($s['delta_recall_at_3'] !== null ? sprintf('%+.1f%%', $s['delta_recall_at_3'] * 100) : 'n/a', 8),
        str_pad($ms($s['cosine_p50_embed_ms']), 8),
        str_pad($ms($s['rerank_p50_ms']), 8),
        str_pad($usd($s['rerank_cost_per_query_usd']), 8),
    ];
    echo implode(' | ', $row) . "\n";
}

echo "\n";

// ---------- Write JSON output ----------

$output = [
    'run_at'           => date('c'),
    'fixture_file'     => basename($fixturespath),
    'embed_model'      => $provider->get_model(),
    'rerank_available' => $rerankavailable,
    'candidates_n'     => $candidates,
    'topk'             => $topk,
    'summary'          => $summary,
    'per_fixture'      => $results,
];

file_put_contents($outfile, json_encode($output, JSON_PRETTY_PRINT));
echo "Results written to: {$outfile}\n";
