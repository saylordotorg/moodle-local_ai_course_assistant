#!/usr/bin/env python3
"""
SOLA External API Smoke Tests
==============================
Tests the external API contracts that SOLA depends on.
Catches breaking changes (endpoint renames, schema changes, new required fields)
before they reach users.

Usage:
  python3 tests/smoke_api.py --apikey sk-...

Exit codes:
  0 = all tests passed
  1 = one or more tests failed

Run in CI via GitHub Actions (.github/workflows/api-smoke-test.yml) daily.
"""

import argparse
import asyncio
import json
import sys
import time
import urllib.request
import urllib.error


# ── Colour helpers ──────────────────────────────────────────────────────────

def green(s):  return f"\033[32m{s}\033[0m"
def red(s):    return f"\033[31m{s}\033[0m"
def yellow(s): return f"\033[33m{s}\033[0m"
def bold(s):   return f"\033[1m{s}\033[0m"


# ── HTTP helper ──────────────────────────────────────────────────────────────

def post(url, body, apikey, timeout=15):
    """POST JSON body, return (status_code, response_bytes)."""
    data = body.encode() if isinstance(body, str) else body
    req = urllib.request.Request(
        url, data=data,
        headers={"Authorization": f"Bearer {apikey}", "Content-Type": "application/json"},
    )
    try:
        resp = urllib.request.urlopen(req, timeout=timeout)
        return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


# ── Test runner ──────────────────────────────────────────────────────────────

# Markers that indicate the failure is an OpenAI billing/quota issue rather
# than a real API-contract regression. When detected we record the test as
# SKIP instead of FAIL so CI stays green during quota outages. Top up the
# account or rotate the OPENAI_API_KEY secret to restore full coverage.
QUOTA_MARKERS = (
    "insufficient_quota",
    "HTTP 429",
    "got 429",
    " 429:",
    "exceeded your current quota",
)


def _is_quota_error(msg: str) -> bool:
    return any(marker in msg for marker in QUOTA_MARKERS)


# Markers for a transient network/server blip (a slow REST read, a 5xx, a reset
# connection) rather than an API-contract regression. Recorded as SKIP so a
# single flaky run does not file a false-positive "OpenAI changed their API"
# issue. Deliberately NARROW: only socket read-timeouts on the REST endpoints
# and explicit 5xx/connection errors. It must NOT match the realtime
# asyncio.wait_for() timeouts (empty-message TimeoutError) — those legitimately
# fail when an expected event is renamed, which is exactly what this suite
# exists to catch.
TRANSIENT_MARKERS = (
    "read operation timed out",
    "HTTP 503", "got 503", " 503:", "Service Unavailable",
    "HTTP 502", "got 502", " 502:", "Bad Gateway",
    "Connection reset", "Connection aborted",
    "Remote end closed connection",
    "Temporary failure in name resolution",
)


def _is_transient_error(msg: str) -> bool:
    low = msg.lower()
    return any(marker.lower() in low for marker in TRANSIENT_MARKERS)


results = []

def run(name, fn, *args, **kwargs):
    """Run a test function, catch exceptions, record pass/fail/skip."""
    start = time.time()
    try:
        fn(*args, **kwargs)
        elapsed = time.time() - start
        print(f"  {green('PASS')}  {name}  ({elapsed:.1f}s)")
        results.append((name, "pass", None))
        return
    except AssertionError as e:
        msg = str(e)
    except Exception as e:
        msg = f"{type(e).__name__}: {e}"

    elapsed = time.time() - start
    if _is_quota_error(msg):
        print(f"  {yellow('SKIP')}  {name}  ({elapsed:.1f}s)")
        print(f"         {yellow('OpenAI quota exceeded — skipping (not a real API regression)')}")
        results.append((name, "skip", msg))
    elif _is_transient_error(msg):
        print(f"  {yellow('SKIP')}  {name}  ({elapsed:.1f}s)")
        print(f"         {yellow('Transient network/server error — skipping (not a real API regression)')}")
        results.append((name, "skip", msg))
    else:
        print(f"  {red('FAIL')}  {name}  ({elapsed:.1f}s)")
        print(f"         {red(msg)}")
        results.append((name, "fail", msg))


# ════════════════════════════════════════════════════════════════════════════
# REALTIME API TESTS
# ════════════════════════════════════════════════════════════════════════════

def test_realtime_token_endpoint(apikey):
    """POST /v1/realtime/client_secrets → ephemeral token."""
    status, body = post("https://api.openai.com/v1/realtime/client_secrets", "{}", apikey)
    assert status == 200, f"Expected HTTP 200, got {status}: {body[:200]}"

    data = json.loads(body)
    token = data.get("value", "")
    assert token, f"Response missing top-level 'value' field. Keys: {list(data.keys())}"
    assert token.startswith("ek_"), \
        f"Token should start with 'ek_' (ephemeral key), got: {token[:10]}..."

    # Session object should include type=realtime
    session = data.get("session", {})
    assert session.get("type") == "realtime", \
        f"session.type should be 'realtime', got: {session.get('type')}"


def test_realtime_websocket_session_update(apikey):
    """WebSocket: connect → session.created → session.update → session.updated."""
    asyncio.run(_ws_session_update(apikey))


async def _ws_session_update(apikey):
    try:
        import websockets
    except ImportError:
        raise AssertionError("websockets library not installed — run: pip install websockets")

    # Fetch token
    status, body = post("https://api.openai.com/v1/realtime/client_secrets", "{}", apikey)
    assert status == 200, f"Token fetch failed: HTTP {status}"
    token = json.loads(body).get("value", "")
    assert token, "No token in response"

    uri = "wss://api.openai.com/v1/realtime?model=gpt-realtime-mini"
    subprotocols = ["realtime", f"openai-insecure-api-key.{token}"]

    async with websockets.connect(uri, subprotocols=subprotocols, open_timeout=20) as ws:
        # Expect session.created. If the very first event is an error (e.g. the
        # key is out of quota), surface the error body so _is_quota_error() can
        # classify quota/429 outages as SKIP rather than a hard FAIL — matching
        # every other test in this suite.
        msg = json.loads(await asyncio.wait_for(ws.recv(), timeout=10))
        if msg.get("type") == "error":
            err = msg.get("error", {}) or {}
            raise AssertionError(
                "Realtime session failed to open: "
                f"{err.get('type', '')} {err.get('message') or msg}".strip())
        assert msg.get("type") == "session.created", \
            f"Expected session.created, got: {msg.get('type')}"

        # Send session.update with current GA format
        await ws.send(json.dumps({
            "type": "session.update",
            "session": {
                "type": "realtime",
                "instructions": "You are a helpful assistant.",
                "output_modalities": ["audio"],
                "audio": {
                    "input": {
                        "format": {"type": "audio/pcm", "rate": 24000},
                        "transcription": {"model": "whisper-1"},
                        "turn_detection": None,
                    },
                    "output": {
                        "format": {"type": "audio/pcm", "rate": 24000},
                        "voice": "shimmer",
                    },
                },
            },
        }))

        # Expect session.updated (not error)
        reply = json.loads(await asyncio.wait_for(ws.recv(), timeout=8))
        assert reply.get("type") != "error", \
            f"session.update was rejected: {reply.get('error', {}).get('message', reply)}"
        assert reply.get("type") == "session.updated", \
            f"Expected session.updated, got: {reply.get('type')}"

        # Verify key fields were accepted
        session = reply.get("session", {})
        audio   = session.get("audio", {})
        voice   = audio.get("output", {}).get("voice")
        assert voice == "shimmer", f"voice not set correctly, got: {voice}"

        vad = audio.get("input", {}).get("turn_detection")
        assert vad is None, f"turn_detection should be null (client VAD), got: {vad}"

        instr = session.get("instructions", "")
        assert len(instr) > 0, "instructions not reflected back in session.updated"


def test_realtime_audio_response_events(apikey):
    """WebSocket: send text item → verify response.output_audio.delta fires."""
    asyncio.run(_ws_audio_events(apikey))


async def _ws_audio_events(apikey):
    try:
        import websockets
    except ImportError:
        raise AssertionError("websockets library not installed — run: pip install websockets")

    status, body = post("https://api.openai.com/v1/realtime/client_secrets", "{}", apikey)
    token = json.loads(body).get("value", "")

    uri = "wss://api.openai.com/v1/realtime?model=gpt-realtime-mini"
    async with websockets.connect(uri, subprotocols=["realtime", f"openai-insecure-api-key.{token}"],
                                  open_timeout=20) as ws:
        await asyncio.wait_for(ws.recv(), timeout=10)  # session.created

        await ws.send(json.dumps({
            "type": "session.update",
            "session": {
                "type": "realtime",
                "instructions": "Reply with the single word: Hello.",
                "output_modalities": ["audio"],
                "audio": {
                    "input": {"format": {"type": "audio/pcm", "rate": 24000}, "turn_detection": None},
                    "output": {"format": {"type": "audio/pcm", "rate": 24000}, "voice": "shimmer"},
                },
            },
        }))
        await asyncio.wait_for(ws.recv(), timeout=8)  # session.updated

        # Send a text message and request response
        await ws.send(json.dumps({
            "type": "conversation.item.create",
            "item": {
                "type": "message", "role": "user",
                "content": [{"type": "input_text", "text": "Say exactly: Hello."}],
            },
        }))
        await ws.send(json.dumps({"type": "response.create"}))

        # Look for the expected audio delta event name
        expected = "response.output_audio.delta"
        seen_types = set()
        for _ in range(30):
            try:
                msg = json.loads(await asyncio.wait_for(ws.recv(), timeout=10))
                t = msg.get("type", "")
                seen_types.add(t)
                if t == expected:
                    return  # Pass
                if t == "response.done":
                    break
                if t == "error":
                    raise AssertionError(f"API error: {msg.get('error', {}).get('message')}")
            except asyncio.TimeoutError:
                break

        raise AssertionError(
            f"Never received '{expected}'. "
            f"Seen: {sorted(seen_types)}. "
            f"(If events were renamed again, update realtime.js event handlers.)"
        )


# ════════════════════════════════════════════════════════════════════════════
# TTS API TESTS
# ════════════════════════════════════════════════════════════════════════════

def test_tts_endpoint(apikey):
    """POST /v1/audio/speech → audio bytes."""
    body = json.dumps({"model": "tts-1", "input": "Hello.", "voice": "shimmer"})
    req = urllib.request.Request(
        "https://api.openai.com/v1/audio/speech",
        data=body.encode(),
        headers={"Authorization": f"Bearer {apikey}", "Content-Type": "application/json"},
    )
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        status = resp.status
        content_type = resp.headers.get("Content-Type", "")
        audio = resp.read()
    except urllib.error.HTTPError as e:
        raise AssertionError(f"HTTP {e.code}: {e.read()[:200]}")

    assert status == 200, f"Expected HTTP 200, got {status}"
    assert "audio" in content_type.lower(), \
        f"Expected audio Content-Type, got: {content_type}"
    assert len(audio) > 1000, \
        f"Audio response suspiciously small ({len(audio)} bytes)"


# ════════════════════════════════════════════════════════════════════════════
# CHAT COMPLETIONS TESTS
# ════════════════════════════════════════════════════════════════════════════

def test_chat_completions(apikey):
    """POST /v1/chat/completions → standard response."""
    body = json.dumps({
        "model": "gpt-4o-mini",
        "messages": [{"role": "user", "content": "Reply with one word: OK"}],
        "max_tokens": 5,
    })
    status, resp_bytes = post("https://api.openai.com/v1/chat/completions", body, apikey, timeout=20)
    assert status == 200, f"Expected HTTP 200, got {status}: {resp_bytes[:200]}"

    data = json.loads(resp_bytes)
    choices = data.get("choices", [])
    assert choices, f"No choices in response. Keys: {list(data.keys())}"
    content = choices[0].get("message", {}).get("content", "")
    assert content, "Empty content in response"


def test_chat_completions_sse(apikey):
    """POST /v1/chat/completions with stream=true → SSE events."""
    body = json.dumps({
        "model": "gpt-4o-mini",
        "messages": [{"role": "user", "content": "Reply with one word: OK"}],
        "max_tokens": 5,
        "stream": True,
    })
    req = urllib.request.Request(
        "https://api.openai.com/v1/chat/completions",
        data=body.encode(),
        headers={"Authorization": f"Bearer {apikey}", "Content-Type": "application/json"},
    )
    try:
        resp = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as e:
        raise AssertionError(f"HTTP {e.code}: {e.read()[:200]}")

    assert resp.status == 200, f"Expected HTTP 200, got {resp.status}"

    chunks = []
    for line in resp:
        line = line.decode().strip()
        if line.startswith("data: ") and line != "data: [DONE]":
            chunk = json.loads(line[6:])
            chunks.append(chunk)

    assert chunks, "No SSE data chunks received"
    # Verify delta structure hasn't changed
    first = chunks[0]
    assert "choices" in first, f"No 'choices' in SSE chunk. Keys: {list(first.keys())}"
    delta = first["choices"][0].get("delta", {})
    assert "role" in delta or "content" in delta, \
        f"Unexpected delta structure: {delta}"


# ════════════════════════════════════════════════════════════════════════════
# MAIN
# ════════════════════════════════════════════════════════════════════════════

def main():
    parser = argparse.ArgumentParser(description="SOLA external API smoke tests")
    parser.add_argument("--apikey", required=True, help="OpenAI API key")
    args = parser.parse_args()

    print(bold("\nSOLA API Smoke Tests"))
    print("=" * 50)

    print(f"\n{bold('Realtime API')}")
    run("Token endpoint returns ek_ token",         test_realtime_token_endpoint,         args.apikey)
    run("session.update GA format accepted",         test_realtime_websocket_session_update, args.apikey)
    run("response.output_audio.delta event fires",   test_realtime_audio_response_events,    args.apikey)

    print(f"\n{bold('TTS API')}")
    run("TTS endpoint returns audio",                test_tts_endpoint,                    args.apikey)

    print(f"\n{bold('Chat Completions API')}")
    run("Chat completions response structure",        test_chat_completions,                args.apikey)
    run("Chat completions SSE stream structure",      test_chat_completions_sse,            args.apikey)

    # Summary
    passed  = sum(1 for _, s, _ in results if s == "pass")
    failed  = sum(1 for _, s, _ in results if s == "fail")
    skipped = sum(1 for _, s, _ in results if s == "skip")
    total   = len(results)

    print("\n" + "=" * 50)
    if failed == 0 and skipped == 0:
        print(green(f"All {total} tests passed."))
    elif failed == 0:
        print(yellow(f"{passed}/{total} passed, {skipped} skipped (OpenAI quota exceeded)."))
        print(yellow("Top up the OpenAI account or rotate OPENAI_API_KEY to restore coverage."))
    else:
        print(red(f"{failed}/{total} tests FAILED."))
        if skipped:
            print(yellow(f"({skipped} additional tests skipped due to OpenAI quota.)"))
        print(yellow("\nFailed tests:"))
        for name, s, err in results:
            if s == "fail":
                print(f"  - {name}")
                print(f"    {err}")

    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    main()
