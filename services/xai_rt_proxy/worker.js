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

// SPDX-License-Identifier: GPL-3.0-or-later
//
// SOLA xAI Realtime WebSocket proxy — Cloudflare Worker edition.
//
// The Moodle plugin mints a short-lived HS256 JWT per learner + course +
// voice + nonce and embeds it in the proxy URL as `?token=<jwt>`. This
// Worker accepts the WebSocket upgrade, validates the JWT, opens an
// outbound WebSocket to api.x.ai with the master API key from its own
// secret store, and relays frames in both directions.
//
// Secrets required (set via `wrangler secret put <NAME>`):
//   XAI_API_KEY         — the xAI master API key.
//   MOODLE_JWT_SECRET   — HS256 secret shared with SOLA's
//                         `xai_proxy_jwt_secret` admin setting.
//
// Optional environment variables (set in wrangler.toml [vars]):
//   XAI_REALTIME_URL    — override upstream (default
//                         wss://api.x.ai/v1/realtime).
//
// Deploy: `wrangler deploy` from `services/xai_rt_proxy/`.

/**
 * xAI Realtime WebSocket relay for SOLA.
 *
 * This is a Cloudflare Worker deployed outside Moodle (it runs on the
 * Workers runtime, not in the browser and not on the Moodle server), so it is
 * deliberately not an AMD module and has no Moodle module name. It ships in
 * the repository next to the plugin because the JWT it validates is minted by
 * the plugin, and the two must stay in step.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const te = new TextEncoder();
const td = new TextDecoder();

function b64urlToBytes(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) { s += '='; }
    const bin = atob(s);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
    return out;
}

async function verifyJwt(token, secret) {
    const parts = (token || '').split('.');
    if (parts.length !== 3) { return { ok: false, reason: 'malformed' }; }
    let header, payload;
    try {
        header = JSON.parse(td.decode(b64urlToBytes(parts[0])));
        payload = JSON.parse(td.decode(b64urlToBytes(parts[1])));
    } catch (e) {
        return { ok: false, reason: 'decode_error' };
    }
    if (header.alg !== 'HS256' || header.typ !== 'JWT') {
        return { ok: false, reason: 'bad_alg' };
    }
    const key = await crypto.subtle.importKey(
        'raw', te.encode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false, ['verify']
    );
    const ok = await crypto.subtle.verify(
        'HMAC', key,
        b64urlToBytes(parts[2]),
        te.encode(parts[0] + '.' + parts[1])
    );
    if (!ok) { return { ok: false, reason: 'bad_signature' }; }
    const now = Math.floor(Date.now() / 1000);
    if (typeof payload.exp !== 'number' || payload.exp < now) {
        return { ok: false, reason: 'expired' };
    }
    if (typeof payload.nbf === 'number' && payload.nbf > now + 5) {
        return { ok: false, reason: 'not_yet_valid' };
    }
    if (payload.provider !== 'xai') {
        return { ok: false, reason: 'wrong_provider' };
    }
    if (!payload.nonce || typeof payload.nonce !== 'string') {
        return { ok: false, reason: 'no_nonce' };
    }
    return { ok: true, payload };
}

function textResponse(status, body) {
    return new Response(body, {
        status,
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
    });
}

export default {
    async fetch(request, env, ctx) {
        const url = new URL(request.url);

        if (request.method === 'GET' && url.pathname === '/health') {
            // Report the URL the route handler actually fetches against, not
            // the raw env var. CF Workers fetch() needs http(s):// for WS
            // upgrades (see line ~120 below) so the route normalises ws/wss
            // → http/https on every request. /health does the same so
            // operators see the real upstream, not a confusing wss:// from
            // a default fallback that never fires.
            let upstream = env.XAI_REALTIME_URL || 'wss://api.x.ai/v1/realtime';
            upstream = upstream.replace(/^wss:\/\//, 'https://').replace(/^ws:\/\//, 'http://');
            return textResponse(200, JSON.stringify({
                ok: true,
                upstream,
            }));
        }
        if (url.pathname !== '/rt') {
            return textResponse(404, JSON.stringify({ ok: false, error: 'not found' }));
        }

        if (request.headers.get('upgrade') !== 'websocket') {
            return textResponse(426, JSON.stringify({ ok: false, error: 'websocket upgrade required' }));
        }

        if (!env.XAI_API_KEY || !env.MOODLE_JWT_SECRET) {
            return textResponse(500, JSON.stringify({ ok: false, error: 'proxy not configured' }));
        }

        const verdict = await verifyJwt(url.searchParams.get('token') || '', env.MOODLE_JWT_SECRET);
        if (!verdict.ok) {
            return textResponse(401, JSON.stringify({ ok: false, error: verdict.reason }));
        }

        // Accept the client connection.
        const pair = new WebSocketPair();
        const client = pair[0];
        const server = pair[1];
        server.accept();

        // Open upstream connection to xAI with the master API key.
        // Cloudflare Workers fetch() for WebSocket outbound requires the
        // scheme to be http:// or https://, not ws:// or wss://. The
        // Upgrade: websocket header drives the protocol switch; the scheme
        // selects the transport security.
        const voice = (verdict.payload.voice || 'eve').replace(/[^a-zA-Z0-9_-]/g, '');
        let baseUpstream = env.XAI_REALTIME_URL || 'wss://api.x.ai/v1/realtime';
        baseUpstream = baseUpstream.replace(/^wss:\/\//, 'https://').replace(/^ws:\/\//, 'http://');
        const upstreamUrl = baseUpstream + (baseUpstream.includes('?') ? '&' : '?') + 'voice=' + encodeURIComponent(voice);
        let upstreamResp;
        try {
            upstreamResp = await fetch(upstreamUrl, {
                headers: {
                    'Upgrade': 'websocket',
                    'Connection': 'Upgrade',
                    'Authorization': 'Bearer ' + env.XAI_API_KEY,
                },
            });
        } catch (err) {
            // Catch fetch-level exceptions so the client sees a clean close
            // frame instead of a Cloudflare "Worker threw exception" 500.
            server.close(1011, 'upstream_fetch_error');
            return new Response(null, { status: 101, webSocket: client });
        }
        const upstream = upstreamResp.webSocket;
        if (!upstream) {
            server.close(1011, 'upstream_handshake_failed_' + upstreamResp.status);
            return new Response(null, { status: 101, webSocket: client });
        }
        upstream.accept();

        let closed = false;
        const teardown = (who, code, reason) => {
            if (closed) { return; }
            closed = true;
            try { server.close(code || 1000, 'closed'); } catch (e) { /* ignore */ }
            try { upstream.close(code || 1000, 'closed'); } catch (e) { /* ignore */ }
        };

        upstream.addEventListener('message', (ev) => {
            if (server.readyState === 1) { server.send(ev.data); }
        });
        upstream.addEventListener('close', (ev) => teardown('upstream', ev.code, ev.reason));
        upstream.addEventListener('error', () => teardown('upstream', 1011, 'upstream_error'));

        server.addEventListener('message', (ev) => {
            if (upstream.readyState === 1) { upstream.send(ev.data); }
        });
        server.addEventListener('close', (ev) => teardown('client', ev.code, ev.reason));
        server.addEventListener('error', () => teardown('client', 1011, 'client_error'));

        return new Response(null, { status: 101, webSocket: client });
    },
};
