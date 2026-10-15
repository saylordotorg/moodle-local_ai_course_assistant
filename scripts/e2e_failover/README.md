# End-to-end failover check with a fake provider

Runs the real `claude_provider` and `custom_provider` (OpenAI-compatible) over real HTTP
against a small fake API server, through the real `failover_chain` and the real audit table
of a local Moodle. It exists because the unit tests use fake provider objects that throw
`provider_http_exception` directly, which skips `base_provider`'s HTTP error handling.

It shows, for a 13-message history:

1. a primary that refuses the long history with a Claude-shaped HTTP 400 and accepts a
   shorter one: the learner gets the primary's answer, its circuit stays closed, and one
   `failover_history_trimmed` row records the 400 text with the fake key replaced by
   `[redacted-key]`;
2. a primary that refuses everything: a `failed` row, then the backup answers;
3. a short conversation: nothing to trim, no retry.

## Run it

```bash
cd scripts/e2e_failover
python3 mock_llm.py 18080 long400   &   # Anthropic /v1/messages, 400 above 4 messages
python3 mock_llm.py 18081 always400 &   # Anthropic /v1/messages, always 400
python3 mock_llm.py 18082 openai_ok &   # OpenAI /v1/chat/completions, streams FALLBACK-ANSWER
: > mock.log
MOODLE_ROOT=~/Sites/moodle php e2e_failover.php    # PHP 8.3, plugin synced into that Moodle
pkill -f mock_llm.py
```

The script lets Moodle's own request guard reach 127.0.0.1 on those three ports, sets the
plugin's `ssrf_trusted_endpoints`, and puts both back afterwards. It also deletes the audit
rows it wrote. Run it only against a LOCAL development site: it changes site settings while it
runs and expects to be the only user of the circuit cache labels `e2e-*`.

Notes: circuit state lives in Moodle's cache for 15 minutes, so a label reused across runs
starts open; the script clears its own labels first. This folder is excluded from the plugin zip
(`scripts/` is).
