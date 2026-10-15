#!/usr/bin/env python3
"""Fake LLM API for the SOLA failover end-to-end test.

usage: mock_llm.py PORT MODE
  MODE long400   - Anthropic /v1/messages: HTTP 400 when the request holds more than 4 messages,
                   otherwise streams "PRIMARY-ANSWER".
  MODE always400 - Anthropic /v1/messages: HTTP 400 every time.
  MODE openai_ok - OpenAI /v1/chat/completions: streams "FALLBACK-ANSWER".

The 400 body quotes a fake API key so the test can check it is blanked.
Every request is appended to mock.log next to this file.
"""
import json
import os
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = int(sys.argv[1])
MODE = sys.argv[2]
LOG = os.path.join(os.path.dirname(os.path.abspath(__file__)), "mock.log")
FAKE_KEY = "sk-ant-api03-FAKEKEYFORTESTINGONLY0123456789ABCDEFGH"


def log(line):
    with open(LOG, "a") as f:
        f.write(line + "\n")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, status, body, ctype="application/json"):
        data = body.encode()
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length).decode("utf-8", "replace")
        try:
            body = json.loads(raw)
        except Exception:
            body = {}
        n = len(body.get("messages", []))
        stream = bool(body.get("stream"))

        if MODE in ("long400", "always400") and self.path.endswith("/messages"):
            if MODE == "always400" or n > 4:
                log(f"port={PORT} mode={MODE} path={self.path} messages={n} -> 400")
                err = {
                    "type": "error",
                    "error": {
                        "type": "invalid_request_error",
                        "message": f"messages.5.content.0.text: text content blocks must be non-empty (key {FAKE_KEY} rejected)",
                    },
                }
                return self._send(400, json.dumps(err))
            log(f"port={PORT} mode={MODE} path={self.path} messages={n} -> 200 PRIMARY-ANSWER")
            if not stream:
                return self._send(200, json.dumps({
                    "id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model", "m"),
                    "content": [{"type": "text", "text": "PRIMARY-ANSWER"}], "stop_reason": "end_turn",
                    "usage": {"input_tokens": 10, "output_tokens": 3}}))
            events = [
                {"type": "message_start", "message": {"id": "msg_1", "type": "message", "role": "assistant",
                 "content": [], "model": body.get("model", "m"), "stop_reason": None,
                 "usage": {"input_tokens": 10, "output_tokens": 1}}},
                {"type": "content_block_start", "index": 0, "content_block": {"type": "text", "text": ""}},
                {"type": "content_block_delta", "index": 0, "delta": {"type": "text_delta", "text": "PRIMARY-ANSWER"}},
                {"type": "content_block_stop", "index": 0},
                {"type": "message_delta", "delta": {"stop_reason": "end_turn", "stop_sequence": None},
                 "usage": {"output_tokens": 3}},
                {"type": "message_stop"},
            ]
            out = "".join(f"event: {e['type']}\ndata: {json.dumps(e)}\n\n" for e in events)
            return self._send(200, out, "text/event-stream")

        if MODE == "openai_ok" and self.path.endswith("/chat/completions"):
            log(f"port={PORT} mode={MODE} path={self.path} messages={n} -> 200 FALLBACK-ANSWER")
            chunks = [
                {"choices": [{"delta": {"content": "FALLBACK-ANSWER"}, "finish_reason": None}]},
                {"choices": [{"delta": {}, "finish_reason": "stop"}],
                 "usage": {"prompt_tokens": 12, "completion_tokens": 3, "total_tokens": 15}},
            ]
            if not stream:
                return self._send(200, json.dumps({
                    "id": "c1", "model": body.get("model", "m"),
                    "choices": [{"message": {"role": "assistant", "content": "FALLBACK-ANSWER"}, "finish_reason": "stop"}],
                    "usage": {"prompt_tokens": 12, "completion_tokens": 3, "total_tokens": 15}}))
            out = "".join(f"data: {json.dumps(c)}\n\n" for c in chunks) + "data: [DONE]\n\n"
            return self._send(200, out, "text/event-stream")

        log(f"port={PORT} mode={MODE} path={self.path} messages={n} -> 404 (unexpected route)")
        self._send(404, json.dumps({"error": "unexpected route"}))


HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
