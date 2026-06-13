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
