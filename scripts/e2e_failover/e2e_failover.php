<?php
// phpcs:ignoreFile
// End-to-end check of v7.8.8 failover detail + shorter-history retry.
// Real claude_provider / custom_provider -> real HTTP (curl) -> fake servers (mock_llm.py)
// -> real failover_chain -> real audit table on the local Moodle.
define('CLI_SCRIPT', true);
require((getenv('MOODLE_ROOT') ?: getenv('HOME') . '/Sites/moodle') . '/config.php');
require_once($CFG->dirroot . '/lib/filelib.php');

use local_ai_course_assistant\provider\claude_provider;
use local_ai_course_assistant\provider\custom_provider;
use local_ai_course_assistant\provider\failover_chain;

global $DB;
$mocklog = __DIR__ . '/mock.log';
$component = 'local_ai_course_assistant';
$prevtrusted = get_config($component, 'ssrf_trusted_endpoints');
set_config('ssrf_trusted_endpoints', "http://127.0.0.1:18080\nhttp://127.0.0.1:18081\nhttp://127.0.0.1:18082", $component);

// Moodle's own web-request guard blocks localhost and any port but 80/443; allow the fake
// servers for this run only and put the settings back afterwards.
$prevhosts = get_config('core', 'curlsecurityblockedhosts');
$prevports = get_config('core', 'curlsecurityallowedport');
set_config('curlsecurityblockedhosts', '');
set_config('curlsecurityallowedport', "80\n443\n18080\n18081\n18082");

$startid = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_ai_course_assistant_audit}');
$firstid = $startid;
$cache = \cache::make($component, 'failover_circuit');
$actions = [
    failover_chain::AUDIT_EVENT_TRIMMED,
    failover_chain::AUDIT_EVENT_CIRCUIT_OPEN,
    failover_chain::AUDIT_EVENT_FALLTHROUGH,
    failover_chain::AUDIT_EVENT_RESCUED,
];

function history(int $pairs = 6): array {
    $m = [];
    for ($i = 1; $i <= $pairs; $i++) {
        $m[] = ['role' => 'user', 'content' => "question $i"];
        $m[] = ['role' => 'assistant', 'content' => "answer $i"];
    }
    $m[] = ['role' => 'user', 'content' => 'the current question'];
    return $m;
}

function claude(int $port): claude_provider {
    return new claude_provider([
        'apikey' => 'sk-ant-api03-FAKEKEYFORTESTINGONLY0123456789ABCDEFGH',
        'model' => 'claude-haiku-4-5', 'apibaseurl' => "http://127.0.0.1:$port",
    ]);
}

function fallback(int $port): custom_provider {
    return new custom_provider(['apikey' => 'test-key', 'model' => 'gpt-4o-mini', 'apibaseurl' => "http://127.0.0.1:$port"]);
}

function ask(failover_chain $chain, array $history): array {
    $chunks = [];
    try {
        $chain->chat_completion_stream('You are a tutor.', $history, function (string $c) use (&$chunks) {
            $chunks[] = $c;
        });
        return ['answer' => implode('', $chunks), 'error' => null];
    } catch (\Throwable $e) {
        return ['answer' => implode('', $chunks), 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

function show(string $title, array $res, string $label, $cache, string $mocklog, int &$startid, array $actions): void {
    global $DB;
    echo "\n=== $title ===\n";
    echo 'learner saw: ' . json_encode($res['answer']) . ($res['error'] ? "  ERROR {$res['error']}" : '') . "\n";
    echo 'circuit for ' . $label . ': ' . ($cache->get('label_' . $label) ? 'OPEN' : 'closed') . "\n";
    echo "fake servers saw:\n";
    echo is_file($mocklog) ? preg_replace('/^/m', '  ', trim((string) file_get_contents($mocklog))) . "\n" : "  (nothing)\n";
    @file_put_contents($mocklog, '');
    [$insql, $params] = $DB->get_in_or_equal($actions, SQL_PARAMS_NAMED);
    $rows = $DB->get_records_select('local_ai_course_assistant_audit', "id > :s AND action $insql", ['s' => $startid] + $params, 'id ASC');
    echo "audit rows:\n";
    foreach ($rows as $r) {
        echo '  ' . $r->action . ' ' . $r->details . "\n";
        $startid = max($startid, (int) $r->id);
    }
    if (!$rows) {
        echo "  (none)\n";
    }
}

try {
    foreach (['e2e-fallback', 'e2e-rescue-primary', 'e2e-refuse-primary', 'e2e-short-primary'] as $stale) {
        $cache->delete('label_' . $stale);
    }
    @file_put_contents($mocklog, '');
    $opts = ['audit' => true, 'courseid' => 0, 'userid' => 0];

    // Scenario 1: the primary refuses the long history with a 400 and accepts the short one.
    $label1 = 'e2e-rescue-primary';
    $chain = new failover_chain(claude(18080), $label1, [['provider' => fallback(18082), 'label' => 'e2e-fallback']], $opts);
    $cache->delete('label_' . $label1);
    show('1. long history refused with a 400, short one accepted (first message)', ask($chain, history()), $label1, $cache, $mocklog, $startid, $actions);
    show('1b. same long conversation again (circuit must still be closed)', ask($chain, history()), $label1, $cache, $mocklog, $startid, $actions);

    // Scenario 2: the primary refuses everything.
    $label2 = 'e2e-refuse-primary';
    $chain = new failover_chain(claude(18081), $label2, [['provider' => fallback(18082), 'label' => 'e2e-fallback']], $opts);
    $cache->delete('label_' . $label2);
    show('2. primary refuses even the short history', ask($chain, history()), $label2, $cache, $mocklog, $startid, $actions);
    show('2b. next long message (primary must be skipped, circuit open)', ask($chain, history()), $label2, $cache, $mocklog, $startid, $actions);

    // Scenario 3: a short conversation that the primary refuses: nothing to trim, so no retry.
    $label3 = 'e2e-short-primary';
    $chain = new failover_chain(claude(18081), $label3, [['provider' => fallback(18082), 'label' => 'e2e-fallback']], $opts);
    $cache->delete('label_' . $label3);
    show('3. short conversation refused: no shorter retry possible', ask($chain, history(1)), $label3, $cache, $mocklog, $startid, $actions);
} finally {
    set_config('curlsecurityblockedhosts', $prevhosts === false ? '' : $prevhosts);
    set_config('curlsecurityallowedport', $prevports === false ? '' : $prevports);
}

// Tidy up: the trusted-endpoint setting and the audit rows this run wrote.
set_config('ssrf_trusted_endpoints', $prevtrusted === false ? '' : $prevtrusted, $component);
[$insql, $params] = $DB->get_in_or_equal($actions, SQL_PARAMS_NAMED);
$DB->delete_records_select('local_ai_course_assistant_audit', "id > :s AND action $insql", ['s' => $firstid] + $params);
echo "\n(done; trusted endpoints restored)\n";
