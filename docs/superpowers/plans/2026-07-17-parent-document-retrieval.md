# Parent-document retrieval — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retrieve on small chunks for precision, then expand the selected chunks into a larger parent unit (a neighbor window or the whole module page) before injecting them into the prompt, so cross-page answers arrive as coherent passages.

**Architecture:** A config-gated, post-selection expansion step in `rag_retriever::retrieve()`. Selection/floor/scope/rerank are unchanged and still operate on small chunks; expansion runs on the final top-k rows, deduplicates by `cmid`, reconstructs page text from the stored chunks (de-overlapping the 50-word joins and the repeated `[Section] Title:` prefix), and caps size with a fallback. No DB schema change — uses existing `cmid`/`chunkindex`.

**Tech Stack:** PHP 8.3, Moodle plugin, PHPUnit (Moodle test harness).

## Global Constraints

- Namespace `local_ai_course_assistant`. Match existing code style in `classes/rag_retriever.php` and `classes/content_chunker.php`.
- The two pure helpers must be side-effect-free (no DB, no provider) and unit-tested with `\basic_testcase`, matching `filter_and_rank`/`scope_to_document`.
- Default behavior unchanged: `rag_return_scope` defaults to `chunk`, which must be byte-identical to today's output.
- New admin strings go in `lang/en/local_ai_course_assistant.php`; a full 46-language i18n sync is required before release (the completeness test fails otherwise).
- PHP lint every changed file: `/opt/homebrew/opt/php@8.3/bin/php -l <file>`.
- Run tests from the Moodle root (`~/Sites/moodle`): `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/<file>`.

---

## File Structure

- `classes/content_chunker.php` (modify) — add pure `reconstruct(array $contents): string`.
- `classes/rag_retriever.php` (modify) — add pure `merge_parents(...)`; wire expansion into `retrieve()`.
- `settings.php` (modify) — add `rag_return_scope`, `rag_window_size`, `rag_parent_max_chars` in the Content & RAG section.
- `lang/en/local_ai_course_assistant.php` (modify) — strings for the three settings.
- `tests/content_chunker_test.php` (create) — `reconstruct` unit tests.
- `tests/rag_retriever_test.php` (modify) — `merge_parents` unit tests.

---

### Task 1: `content_chunker::reconstruct()` — de-overlap + prefix strip

**Files:**
- Modify: `classes/content_chunker.php`
- Test: `tests/content_chunker_test.php` (create)

**Interfaces:**
- Produces: `content_chunker::reconstruct(array $contents): string` — given the ordered chunk `content` strings of ONE module, returns one passage with the shared `[Section] Title:` prefix kept once and the 50-word overlaps between consecutive chunks removed.

- [ ] **Step 1: Write the failing test**

```php
// tests/content_chunker_test.php
namespace local_ai_course_assistant;
defined('MOODLE_INTERNAL') || die();

class content_chunker_test extends \basic_testcase {
    public function test_reconstruct_single_chunk_unchanged() {
        $c = "[2.1: Legal Forms] Corporations: a corporation is a legal entity";
        $this->assertSame($c, content_chunker::reconstruct([$c]));
    }

    public function test_reconstruct_dedupes_overlap_and_prefix() {
        $prefix = "[2.1: Legal Forms] Corporations: ";
        $c0 = $prefix . "a corporation is a legal entity separate from its owners";
        // c1 = prefix + 4-word overlap tail of c0 + new body
        $c1 = $prefix . "separate from its owners and can raise capital by issuing stock";
        $out = content_chunker::reconstruct([$c0, $c1]);
        $this->assertSame(
            $prefix . "a corporation is a legal entity separate from its owners "
                . "and can raise capital by issuing stock",
            $out
        );
        // Prefix header appears exactly once.
        $this->assertSame(1, substr_count($out, "Corporations:"));
    }

    public function test_reconstruct_empty() {
        $this->assertSame('', content_chunker::reconstruct([]));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/content_chunker_test.php`
Expected: FAIL — `Error: Call to undefined method ...content_chunker::reconstruct()`.

- [ ] **Step 3: Implement `reconstruct` in `classes/content_chunker.php`**

Add this method inside the `content_chunker` class (after `chunk`):
```php
    /**
     * Reconstruct a module's chunks into one passage: keep the shared
     * "[Section] Title:" prefix once, and remove the word overlap between
     * consecutive chunks. Pure (no DB/provider) so it is unit-testable.
     *
     * @param string[] $contents Ordered chunk `content` strings for one module.
     * @return string
     */
    public static function reconstruct(array $contents): string {
        $contents = array_values($contents);
        $n = count($contents);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $contents[0];
        }

        // Longest common prefix across all chunks, cut at the last ": " so we
        // only strip the "[Section] Title: " header, never shared body text.
        $prefix = $contents[0];
        foreach ($contents as $c) {
            $max = min(strlen($prefix), strlen($c));
            $i = 0;
            while ($i < $max && $prefix[$i] === $c[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') {
                break;
            }
        }
        $cut = strrpos($prefix, ': ');
        $prefix = ($cut !== false) ? substr($prefix, 0, $cut + 2) : '';
        $plen = strlen($prefix);

        $result    = $contents[0];
        $prevwords = preg_split('/\s+/', trim(substr($contents[0], $plen)), -1, PREG_SPLIT_NO_EMPTY);

        for ($k = 1; $k < $n; $k++) {
            $body     = trim(substr($contents[$k], $plen));
            $curwords = preg_split('/\s+/', $body, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($curwords)) {
                continue;
            }
            // Largest o such that the last o words of prev equal the first o of cur.
            $cap = min(count($prevwords), count($curwords), 100);
            $ov  = 0;
            for ($o = $cap; $o >= 1; $o--) {
                if (array_slice($prevwords, -$o) === array_slice($curwords, 0, $o)) {
                    $ov = $o;
                    break;
                }
            }
            $newwords = array_slice($curwords, $ov);
            if (!empty($newwords)) {
                $result .= ' ' . implode(' ', $newwords);
            }
            $prevwords = $curwords;
        }
        return $result;
    }
```

- [ ] **Step 4: Run test, verify it passes**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/content_chunker_test.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Lint + commit**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l classes/content_chunker.php
git add classes/content_chunker.php tests/content_chunker_test.php
git commit -m "feat(rag): content_chunker::reconstruct (de-overlap + prefix strip)"
```

---

### Task 2: `rag_retriever::merge_parents()` — expand + dedup + cap

**Files:**
- Modify: `classes/rag_retriever.php`
- Test: `tests/rag_retriever_test.php`

**Interfaces:**
- Consumes: `content_chunker::reconstruct` (Task 1).
- Produces: `rag_retriever::merge_parents(array $topkrows, array $siblingsbycmid, string $mode, int $windowsize, int $maxchars): array`.
  - `$topkrows`: final selected rows `{content, score, cmid, modtype, chunkindex, ...}`.
  - `$siblingsbycmid`: `[cmid => [ ['content'=>string,'chunkindex'=>int], ... ]]` (all chunks for the cmids present).
  - `$mode`: `'window'` | `'page'`. Returns rows with expanded `content`, deduped by `cmid` (first/highest-ranked hit wins), plus `expand_mode` and `expanded_from`. Rows with no `cmid` or no siblings pass through unchanged. Over-`$maxchars` pages fall back to a window, then to the single matched chunk.

- [ ] **Step 1: Write the failing test**

Append to `tests/rag_retriever_test.php`:
```php
    public function test_merge_parents_page_dedupes_and_reconstructs() {
        $prefix = "[U1] Intro: ";
        $siblings = [510 => [
            ['content' => $prefix . "alpha beta gamma", 'chunkindex' => 0],
            ['content' => $prefix . "beta gamma delta epsilon", 'chunkindex' => 1],
        ]];
        // Two top-k hits from the same cmid -> collapse to one expanded page.
        $topk = [
            ['content' => $prefix . "beta gamma delta epsilon", 'score' => 0.9, 'cmid' => 510, 'chunkindex' => 1],
            ['content' => $prefix . "alpha beta gamma", 'score' => 0.8, 'cmid' => 510, 'chunkindex' => 0],
        ];
        $out = \local_ai_course_assistant\rag_retriever::merge_parents($topk, $siblings, 'page', 1, 6000);
        $this->assertCount(1, $out);
        $this->assertSame($prefix . "alpha beta gamma delta epsilon", $out[0]['content']);
        $this->assertSame('page', $out[0]['expand_mode']);
        $this->assertSame(2, $out[0]['expanded_from']);
        $this->assertSame(0.9, $out[0]['score']); // best score preserved
    }

    public function test_merge_parents_passthrough_without_cmid() {
        $topk = [['content' => 'x', 'score' => 0.7, 'cmid' => null, 'chunkindex' => 0]];
        $out = \local_ai_course_assistant\rag_retriever::merge_parents($topk, [], 'page', 1, 6000);
        $this->assertSame($topk, $out);
    }

    public function test_merge_parents_cap_falls_back_to_chunk() {
        $big = str_repeat('word ', 50);
        $siblings = [7 => [
            ['content' => $big . 'a', 'chunkindex' => 0],
            ['content' => $big . 'b', 'chunkindex' => 1],
            ['content' => $big . 'c', 'chunkindex' => 2],
        ]];
        $matched = $big . 'b';
        $topk = [['content' => $matched, 'score' => 0.9, 'cmid' => 7, 'chunkindex' => 1]];
        // maxchars smaller than any multi-chunk merge -> fall back to matched chunk.
        $out = \local_ai_course_assistant\rag_retriever::merge_parents($topk, $siblings, 'page', 1, strlen($matched) + 5);
        $this->assertSame($matched, $out[0]['content']);
    }
```

- [ ] **Step 2: Run test, verify it fails**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/rag_retriever_test.php`
Expected: FAIL — undefined method `merge_parents`.

- [ ] **Step 3: Implement `merge_parents` in `classes/rag_retriever.php`**

Add after `scope_to_document`:
```php
    /**
     * Expand selected chunks into parent units (neighbor window or whole page),
     * deduplicated by cmid, size-capped with fallback. Pure (no DB/provider).
     *
     * @param array  $topkrows       Final selected rows (post-rerank), rank-sorted.
     * @param array  $siblingsbycmid [cmid => [ ['content'=>string,'chunkindex'=>int], ... ]]
     * @param string $mode           'window' | 'page'.
     * @param int    $windowsize     Neighbors each side for 'window' mode.
     * @param int    $maxchars       Per-passage cap; over-cap pages fall back.
     * @return array Expanded rows (same shape + 'expand_mode', 'expanded_from').
     */
    public static function merge_parents(array $topkrows, array $siblingsbycmid,
            string $mode, int $windowsize, int $maxchars): array {
        $out = [];
        $seen = [];
        foreach ($topkrows as $row) {
            $cmid = (int) ($row['cmid'] ?? 0);
            if ($cmid <= 0 || empty($siblingsbycmid[$cmid])) {
                $out[] = $row;
                continue;
            }
            if (isset($seen[$cmid])) {
                continue; // page already emitted from a higher-ranked hit
            }
            $seen[$cmid] = true;

            $siblings = $siblingsbycmid[$cmid];
            usort($siblings, fn($a, $b) => ((int) $a['chunkindex']) <=> ((int) $b['chunkindex']));
            $center = (int) ($row['chunkindex'] ?? 0);

            $pick = function (int $win) use ($siblings, $center) {
                return array_values(array_filter($siblings,
                    fn($s) => abs(((int) $s['chunkindex']) - $center) <= $win));
            };

            $selected = ($mode === 'window') ? $pick($windowsize) : $siblings;
            $merged = content_chunker::reconstruct(array_map(fn($s) => (string) $s['content'], $selected));

            // Size cap: page -> window -> single matched chunk.
            if (strlen($merged) > $maxchars) {
                $selected = $pick(max(1, $windowsize));
                $merged = content_chunker::reconstruct(array_map(fn($s) => (string) $s['content'], $selected));
                if (strlen($merged) > $maxchars) {
                    $selected = [['content' => (string) $row['content'], 'chunkindex' => $center]];
                    $merged = (string) $row['content'];
                }
            }

            $newrow = $row;
            $newrow['content']       = $merged;
            $newrow['expand_mode']   = $mode;
            $newrow['expanded_from'] = count($selected);
            $out[] = $newrow;
        }
        return $out;
    }
```

- [ ] **Step 4: Run test, verify it passes**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit local/ai_course_assistant/tests/rag_retriever_test.php`
Expected: PASS (existing tests + 3 new).

- [ ] **Step 5: Lint + commit**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l classes/rag_retriever.php
git add classes/rag_retriever.php tests/rag_retriever_test.php
git commit -m "feat(rag): merge_parents — expand chunks to window/page, dedup, cap"
```

---

### Task 3: Config settings

**Files:**
- Modify: `settings.php` (Content & RAG section)
- Modify: `lang/en/local_ai_course_assistant.php`

**Interfaces:**
- Produces: config keys `rag_return_scope` (`chunk`|`window`|`page`, default `chunk`), `rag_window_size` (int, default 1), `rag_parent_max_chars` (int, default 6000).

- [ ] **Step 1: Add English strings**

In `lang/en/local_ai_course_assistant.php` add:
```php
$string['rag_return_scope'] = 'Retrieval return scope';
$string['rag_return_scope_desc'] = 'What to inject for each retrieved hit. "chunk" (default) injects the matched ~400-word chunk. "window" injects the matched chunk plus neighbouring chunks from the same page. "page" injects the whole module page the match belongs to. window/page help when an answer spans a page, at the cost of a larger prompt.';
$string['rag_window_size'] = 'Retrieval window size';
$string['rag_window_size_desc'] = 'For "window" return scope: number of neighbouring chunks to include on each side of a matched chunk. Default 1.';
$string['rag_parent_max_chars'] = 'Parent passage cap (characters)';
$string['rag_parent_max_chars_desc'] = 'Maximum characters for an expanded window/page passage. Over-cap pages fall back to a window, then to the single chunk. Default 6000.';
```

- [ ] **Step 2: Register the settings in `settings.php`**

In the Content & RAG settings page, after the existing `rag_chunksize` setting, add:
```php
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/rag_return_scope',
        get_string('rag_return_scope', 'local_ai_course_assistant'),
        get_string('rag_return_scope_desc', 'local_ai_course_assistant'),
        'chunk',
        ['chunk' => 'chunk', 'window' => 'window', 'page' => 'page']
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_window_size',
        get_string('rag_window_size', 'local_ai_course_assistant'),
        get_string('rag_window_size_desc', 'local_ai_course_assistant'),
        '1', PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_parent_max_chars',
        get_string('rag_parent_max_chars', 'local_ai_course_assistant'),
        get_string('rag_parent_max_chars_desc', 'local_ai_course_assistant'),
        '6000', PARAM_INT
    ));
```
(Use the exact `$settings` variable name in scope at that point in `settings.php` — match the adjacent `rag_*` settings; if RAG settings are added to a differently-named settingpage object there, use that object.)

- [ ] **Step 3: Lint + verify strings load**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l settings.php
/opt/homebrew/opt/php@8.3/bin/php -l lang/en/local_ai_course_assistant.php
```
Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add settings.php lang/en/local_ai_course_assistant.php
git commit -m "feat(rag): rag_return_scope/window_size/parent_max_chars settings"
```

Note: before release, run the repo's i18n sync so the three keys land in all 45 non-English lang files (the completeness test fails otherwise).

---

### Task 4: Wire parent expansion into `retrieve()`

**Files:**
- Modify: `classes/rag_retriever.php` (`retrieve()` tail)

**Interfaces:**
- Consumes: `merge_parents` (Task 2), the new config (Task 3), `$DB`.

- [ ] **Step 1: Read the current `retrieve()` tail**

The method currently returns in two places: inside the rerank block (`return $out;`) and at the end (`return array_slice($scored, 0, $topk);`). Refactor so both feed one variable `$final`, then apply expansion once.

- [ ] **Step 2: Replace the rerank block's early return**

In the rerank block, change:
```php
                        if (!empty($out)) {
                            return $out;
                        }
```
to:
```php
                        if (!empty($out)) {
                            $final = $out;
                        }
```
and add, just before the `catch` closes the rerank attempt, nothing else (the fallthrough handles the rest).

- [ ] **Step 3: Replace the final return with selection + expansion**

Change the method's final line:
```php
        return array_slice($scored, 0, $topk);
```
to:
```php
        $final = $final ?? array_slice($scored, 0, $topk);

        // Parent-document expansion (opt-in). Selection above is unchanged;
        // here we optionally widen each hit to a neighbour window or full page.
        $rawreturn = get_config('local_ai_course_assistant', 'rag_return_scope');
        $returnscope = ($rawreturn === false || $rawreturn === '') ? 'chunk' : (string) $rawreturn;
        if ($returnscope === 'chunk') {
            return $final;
        }
        $rawwin = get_config('local_ai_course_assistant', 'rag_window_size');
        $windowsize = ($rawwin === false || $rawwin === '') ? 1 : max(0, (int) $rawwin);
        $rawcap = get_config('local_ai_course_assistant', 'rag_parent_max_chars');
        $maxchars = ($rawcap === false || $rawcap === '') ? 6000 : max(500, (int) $rawcap);

        $cmids = array_values(array_unique(array_filter(
            array_map(fn($r) => (int) ($r['cmid'] ?? 0), $final))));
        $siblingsbycmid = [];
        if (!empty($cmids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $rows = $DB->get_records_select(
                'local_ai_course_assistant_chunks',
                "courseid = :cid AND cmid {$insql}",
                array_merge(['cid' => $courseid], $inparams),
                'cmid, chunkindex',
                'id, cmid, chunkindex, content'
            );
            foreach ($rows as $r) {
                $siblingsbycmid[(int) $r->cmid][] = [
                    'content'    => (string) $r->content,
                    'chunkindex' => (int) $r->chunkindex,
                ];
            }
        }
        return self::merge_parents($final, $siblingsbycmid, $returnscope, $windowsize, $maxchars);
```
Also declare `$final = null;` near the top of `retrieve()` (just after `global $DB;`) so the `?? ` is defined even when rerank is off.

- [ ] **Step 4: Lint**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l classes/rag_retriever.php
```
Expected: no syntax errors.

- [ ] **Step 5: Regression + live verification on dev**

Deploy to dev and verify both regimes:
```bash
python3 deploy_dev.py --target dev
```
Then via SSM (as done for the earlier eyeball), for BUS101 (courseid 11), question "What is the difference between a sole proprietorship and a corporation, and how does liability differ?":
- With `rag_return_scope=chunk`: output identical to today (fragments across cmids 510/509/508/504).
- With `rag_return_scope=page`: fewer rows, each the full reconstructed page (no mid-sentence starts, `[2.1: Legal Forms of Business]` header once per page).
Confirm the same via **Prompt Playground** (`/local/ai_course_assistant/prompt_playground.php`) live-retrieval box — it calls `retrieve()`, so expanded passages show automatically.

- [ ] **Step 6: Commit**

```bash
git add classes/rag_retriever.php
git commit -m "feat(rag): wire parent-document expansion into retrieve()"
```

---

## Self-Review

**Spec coverage:** modes chunk/window/page (Tasks 3+4), dedup by cmid (Task 2), de-overlap + prefix strip (Task 1), char cap with fallback (Task 2), no schema change (uses existing `cmid`/`chunkindex`), selection path untouched (Task 4 only adds a tail step), playground/debug show expanded passages automatically because they call `retrieve()` (Task 4 Step 5). All covered.

**Placeholder scan:** no TBD/TODO; every code step has complete code. The one soft spot is the `$settings` object name in Task 3 Step 2 — flagged explicitly to match the adjacent `rag_*` registration in `settings.php` rather than guessed.

**Type consistency:** `reconstruct(array): string` used by `merge_parents` (Task 2) and `retrieve()` matches Task 1. `merge_parents(array,array,string,int,int): array` signature matches between Task 2 definition, its tests, and the Task 4 call. Config keys `rag_return_scope`/`rag_window_size`/`rag_parent_max_chars` are identical across Tasks 3 and 4. Row shape `{content,score,cmid,chunkindex,...}` matches what `retrieve()` already produces.

**Evaluation note:** recall@k is unchanged (selection is unchanged), so the embedding/rerank benchmark does not measure this; the win is answer coherence on cross-page questions, evaluated via the real-student-question set (separate spec) and the live dev verification in Task 4 Step 5.
