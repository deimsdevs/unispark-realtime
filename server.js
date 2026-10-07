'use strict';

require('dotenv').config();
const WebSocket = require('ws');
const http = require('http');
const https = require('https');
const crypto = require('crypto');

// ---------------------------------------------------------------------------
// Configuration (all secrets come from environment variables)
// ---------------------------------------------------------------------------
const PORT = process.env.PORT || 8080;
const TOKEN_SECRET = process.env.TOKEN_SECRET || '';
const SITE_STATUS_URL = process.env.SITE_STATUS_URL || 'https://unispark.rf.gd/dashboard/chat/update_status.php';
const STATUS_KEY = process.env.STATUS_KEY || '';
const XIRSYS_USER = process.env.XIRSYS_USER || '';
const XIRSYS_SECRET = process.env.XIRSYS_SECRET || '';
const XIRSYS_CHANNEL = process.env.XIRSYS_CHANNEL || 'unispark-realtime';
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || 'https://unispark.rf.gd')
    .split(',').map(s => s.trim()).filter(Boolean);
const FACETIME_TESTERS = new Set(
    (process.env.FACETIME_TESTERS || '').split(',').map(s => s.trim()).filter(Boolean)
);
const FACETIME_OPEN = process.env.FACETIME_OPEN === '1';

if (!TOKEN_SECRET || TOKEN_SECRET.length < 32) {
    console.error('TOKEN_SECRET is missing or shorter than 32 characters. Refusing to start.');
    process.exit(1);
}

const OFFLINE_GRACE_MS = 8000;          // wait before marking a user offline (page navigations reconnect)
const HEARTBEAT_TIMEOUT_MS = 70000;     // background tabs throttle timers, so be generous
const AUTH_TIMEOUT_MS = 10000;
const RECENT_WINDOW_MS = 60000;         // do not re-pair the same two people within this window
const RECENT_RELAX_MS = 6000;           // ...unless both have waited this long (small user pools)

// ---------------------------------------------------------------------------
// Token verification (token is created by dashboard/realtime_token.php)
// ---------------------------------------------------------------------------
function b64urlDecode(str) {
    return Buffer.from(str.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

function verifyToken(token) {
    if (typeof token !== 'string' || token.length > 2048) return null;
    const parts = token.split('.');
    if (parts.length !== 2) return null;

    const expected = crypto.createHmac('sha256', TOKEN_SECRET).update(parts[0]).digest();
    let given;
    try { given = b64urlDecode(parts[1]); } catch (e) { return null; }
    if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return null;

    let payload;
    try { payload = JSON.parse(b64urlDecode(parts[0]).toString('utf8')); } catch (e) { return null; }
    if (!payload || !payload.uid || !payload.exp) return null;
    if (payload.exp < Math.floor(Date.now() / 1000)) return null;

    return {
        uid: String(payload.uid),
        name: String(payload.name || 'User').slice(0, 60),
        blocked: Array.isArray(payload.bl) ? payload.bl.map(String) : []
    };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function sendTo(ws, obj) {
    if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
}

function sendToUser(uid, obj) {
    const set = sockets.get(uid);
    if (!set) return 0;
    let delivered = 0;
    const payload = JSON.stringify(obj);
    set.forEach(ws => {
        if (ws.readyState === WebSocket.OPEN) { ws.send(payload); delivered++; }
    });
    return delivered;
}

function safePhoto(photo) {
    if (typeof photo !== 'string' || photo.length > 300) return '';
    return /["'<>\s`]/.test(photo) ? '' : photo;
}

function originAllowed(origin) {
    return !!origin && ALLOWED_ORIGINS.includes(origin);
}

// ---------------------------------------------------------------------------
// Presence: in memory, with a grace period before going offline
// ---------------------------------------------------------------------------
const sockets = new Map();            // uid -> Set<ws>
const offlineTimers = new Map();      // uid -> timeout
const reportedOnline = new Set();     // uids we have told the website are online

function isOnline(uid) {
    const set = sockets.get(uid);
    return !!(set && set.size > 0);
}

// ---------------------------------------------------------------------------
// Chat: presence watchers and active 1:1 calls
// ---------------------------------------------------------------------------
const watchers = new Map();           // uid -> Set<ws> watching that user's presence
const activeCalls = new Map();        // uid -> partner uid, while a 1:1 call is connected

function announcePresence(uid, online) {
    const set = watchers.get(uid);
    if (!set) return;
    const payload = JSON.stringify({ type: 'chat_presence', userId: uid, online: online });
    set.forEach(ws => { if (ws.readyState === WebSocket.OPEN) ws.send(payload); });
}

function unwatch(ws) {
    if (!ws.watching) return;
    const set = watchers.get(ws.watching);
    if (set) {
        set.delete(ws);
        if (set.size === 0) watchers.delete(ws.watching);
    }
    ws.watching = null;
}

function chatWatch(ws, data) {
    const target = String(data.targetUserId || '');
    unwatch(ws);
    if (!/^\d+$/.test(target)) return;
    ws.watching = target;
    if (!watchers.has(target)) watchers.set(target, new Set());
    watchers.get(target).add(ws);
    sendTo(ws, { type: 'chat_presence', userId: target, online: isOnline(target) });
}

function inCall(uid) {
    return activeCalls.has(uid);
}

function clearCall(uid) {
    const partner = activeCalls.get(uid);
    activeCalls.delete(uid);
    if (partner && activeCalls.get(partner) === uid) activeCalls.delete(partner);
    return partner;
}

function pushStatusToSite(uid, online) {
    const params = new URLSearchParams({ user_id: uid, is_online: online ? '1' : '0' });
    if (STATUS_KEY) params.set('key', STATUS_KEY);
    const req = https.get(`${SITE_STATUS_URL}?${params.toString()}`, res => { res.resume(); });
    req.setTimeout(8000, () => req.destroy());
    req.on('error', () => { /* the site may block server requests; presence still works in memory */ });
}

function markOnline(uid) {
    const timer = offlineTimers.get(uid);
    if (timer) { clearTimeout(timer); offlineTimers.delete(uid); }
        if (!reportedOnline.has(uid)) {
        reportedOnline.add(uid);
        pushStatusToSite(uid, true);
        announcePresence(uid, true);
        console.log(`User ${uid} online`);
    }
}

function scheduleOffline(uid) {
    if (isOnline(uid) || offlineTimers.has(uid)) return;
    const timer = setTimeout(() => {
        offlineTimers.delete(uid);
        if (isOnline(uid)) return;
                reportedOnline.delete(uid);
        pushStatusToSite(uid, false);
        announcePresence(uid, false);
        console.log(`User ${uid} offline`);
    }, OFFLINE_GRACE_MS);
    offlineTimers.set(uid, timer);
}

// ---------------------------------------------------------------------------
// Facetime: queue, pairing, sessions
// ---------------------------------------------------------------------------
const ftQueue = [];                   // sockets waiting for a partner
const ftSessions = new Map();         // sid -> { sid, a, b, startedAt }
const recentPartners = new Map();     // uid -> { partner, at }
const ftBlocks = new Map();           // uid -> Set<uid> (in memory only for now)

function canUseFacetime(uid) {
    return FACETIME_OPEN || FACETIME_TESTERS.has(uid);
}

function inFacetime(uid) {
    const set = sockets.get(uid);
    if (!set) return false;
    for (const ws of set) if (ws.ft.state !== 'idle') return true;
    return false;
}

function ftStats() {
    return { waiting: ftQueue.length, active: ftSessions.size * 2 };
}

function removeFromQueue(ws) {
    const i = ftQueue.indexOf(ws);
    if (i !== -1) ftQueue.splice(i, 1);
}

function isBlocked(a, b) {
    const ab = ftBlocks.get(a.user.uid);
    const ba = ftBlocks.get(b.user.uid);
    return a.user.blocked.includes(b.user.uid) || b.user.blocked.includes(a.user.uid) ||
        (ab && ab.has(b.user.uid)) || (ba && ba.has(a.user.uid));
}

function isRecent(a, b) {
    const now = Date.now();
    const ra = recentPartners.get(a.user.uid);
    const rb = recentPartners.get(b.user.uid);
    return (ra && ra.partner === b.user.uid && now - ra.at < RECENT_WINDOW_MS) ||
        (rb && rb.partner === a.user.uid && now - rb.at < RECENT_WINDOW_MS);
}

function findPair() {
    const now = Date.now();
    for (const allowRecent of [false, true]) {
        for (let i = 0; i < ftQueue.length; i++) {
            for (let j = i + 1; j < ftQueue.length; j++) {
                const a = ftQueue[i];
                const b = ftQueue[j];
                if (a.user.uid === b.user.uid) continue;
                if (isBlocked(a, b)) continue;
                if (isRecent(a, b)) {
                    const waitedEnough = now - Math.max(a.ft.queuedAt, b.ft.queuedAt) >= RECENT_RELAX_MS;
                    if (!(allowRecent && waitedEnough)) continue;
                }
                return [a, b];
            }
        }
    }
    return null;
}

function tryPair() {
    let pair;
    while ((pair = findPair())) startSession(pair[0], pair[1]);
}

function enqueue(ws) {
    if (ws.readyState !== WebSocket.OPEN || ws.ft.state === 'queued') return;
    ws.ft = { state: 'queued', sid: null, partner: null, queuedAt: Date.now() };
    ftQueue.push(ws);
    sendTo(ws, Object.assign({ type: 'ft_searching' }, ftStats()));
    tryPair();
}

function startSession(a, b) {
    removeFromQueue(a);
    removeFromQueue(b);

    const sid = crypto.randomBytes(8).toString('hex');
    const caller = Math.random() < 0.5 ? a : b;
    const callee = caller === a ? b : a;

    ftSessions.set(sid, { sid, a: caller, b: callee, startedAt: Date.now() });
    caller.ft = { state: 'matched', sid, partner: callee, queuedAt: 0 };
    callee.ft = { state: 'matched', sid, partner: caller, queuedAt: 0 };

    // The callee hears first so its peer connection exists before the offer arrives.
    sendTo(callee, { type: 'ft_matched', sid, role: 'callee', partner: { id: caller.user.uid, name: caller.user.name } });
    sendTo(caller, { type: 'ft_matched', sid, role: 'caller', partner: { id: callee.user.uid, name: callee.user.name } });
    console.log(`Facetime session ${sid}: ${caller.user.uid} <-> ${callee.user.uid}`);
}

function endSession(ws, opts) {
    const sid = ws.ft.sid;
    const partner = ws.ft.partner;
    if (!sid) return;

    ftSessions.delete(sid);
    if (partner) {
        const now = Date.now();
        recentPartners.set(ws.user.uid, { partner: partner.user.uid, at: now });
        recentPartners.set(partner.user.uid, { partner: ws.user.uid, at: now });
    }

    ws.ft = { state: 'idle' };
    if (partner && partner.ft.sid === sid) {
        partner.ft = { state: 'idle' };
        sendTo(partner, { type: 'ft_partner_left', reason: opts.reason || 'left' });
        enqueue(partner);                       // the partner is searching again automatically
    }
    if (opts.requeueSelf) enqueue(ws);
}

function ftJoin(ws) {
    if (!canUseFacetime(ws.user.uid)) {
        sendTo(ws, { type: 'ft_error', code: 'not_allowed' });
        return;
    }
    if (ws.ft.state === 'matched') endSession(ws, { requeueSelf: false, reason: 'left' });
    if (ws.ft.state === 'idle') {
        const set = sockets.get(ws.user.uid);
        for (const other of set) {
            if (other !== ws && other.ft.state !== 'idle') {
                sendTo(ws, { type: 'ft_error', code: 'already_active' });
                return;
            }
        }
    }
    enqueue(ws);
}

function ftSkip(ws) {
    if (ws.ft.state === 'matched') {
        endSession(ws, { requeueSelf: true, reason: 'skipped' });
    } else if (ws.ft.state === 'queued') {
        sendTo(ws, Object.assign({ type: 'ft_searching' }, ftStats()));
    }
}

function ftLeave(ws) {
    if (ws.ft.state === 'queued') {
        removeFromQueue(ws);
        ws.ft = { state: 'idle' };
    } else if (ws.ft.state === 'matched') {
        endSession(ws, { requeueSelf: false, reason: 'left' });
    }
    sendTo(ws, { type: 'ft_left' });
}

function ftSignal(ws, data) {
    if (ws.ft.state !== 'matched' || data.sid !== ws.ft.sid) return;
    if (!['offer', 'answer', 'ice'].includes(data.kind)) return;
    sendTo(ws.ft.partner, { type: 'ft_signal', sid: data.sid, kind: data.kind, payload: data.payload });
}

function ftBlock(ws, data) {
    const wanted = String((data && data.targetUserId) || '');
    const current = ws.ft.state === 'matched' ? ws.ft.partner.user.uid : null;
    const target = /^\d+$/.test(wanted) ? wanted : current;
    if (!target || target === ws.user.uid) return;

    if (!ftBlocks.has(ws.user.uid)) ftBlocks.set(ws.user.uid, new Set());
    ftBlocks.get(ws.user.uid).add(target);

    // Only end the call if the blocked person is the one we are connected to right now
    if (current && current === target) {
        endSession(ws, { requeueSelf: true, reason: 'skipped' });   // the other person just sees a skip
    }
}

setInterval(tryPair, 1000);

// ---------------------------------------------------------------------------
// HTTP: health check, TURN credentials, presence
// ---------------------------------------------------------------------------
let turnCache = { at: 0, body: null };

function corsHeaders(req) {
    const headers = { 'Vary': 'Origin' };
    const origin = req.headers.origin;
    if (originAllowed(origin)) {
        headers['Access-Control-Allow-Origin'] = origin;
        headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS';
        headers['Access-Control-Allow-Headers'] = 'Content-Type';
    }
    return headers;
}

function isAuthorizedRequest(req, url) {
    return originAllowed(req.headers.origin) || !!verifyToken(url.searchParams.get('token'));
}

function handleTurn(req, res, url, cors) {
    if (!isAuthorizedRequest(req, url)) {
        res.writeHead(403, cors);
        return res.end('{}');
    }
    if (!XIRSYS_USER || !XIRSYS_SECRET) {
        res.writeHead(503, cors);
        return res.end('{}');
    }
    if (turnCache.body && Date.now() - turnCache.at < 10 * 60 * 1000) {
        res.writeHead(200, Object.assign({ 'Content-Type': 'application/json' }, cors));
        return res.end(turnCache.body);
    }

    const auth = Buffer.from(`${XIRSYS_USER}:${XIRSYS_SECRET}`).toString('base64');
    const body = JSON.stringify({ format: 'urls' });
    const request = https.request({
        hostname: 'global.xirsys.net',
        path: `/_turn/${XIRSYS_CHANNEL}`,
        method: 'PUT',
        headers: {
            'Authorization': 'Basic ' + auth,
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(body)
        }
    }, response => {
        let data = '';
        response.on('data', chunk => { data += chunk; });
        response.on('end', () => {
            if (response.statusCode === 200) turnCache = { at: Date.now(), body: data };
            res.writeHead(response.statusCode === 200 ? 200 : 502, Object.assign({ 'Content-Type': 'application/json' }, cors));
            res.end(response.statusCode === 200 ? data : '{}');
        });
    });
    request.setTimeout(8000, () => request.destroy());
    request.on('error', () => {
        res.writeHead(502, cors);
        res.end('{}');
    });
    request.write(body);
    request.end();
}

function handlePresence(req, res, url, cors) {
    if (!isAuthorizedRequest(req, url)) {
        res.writeHead(403, cors);
        return res.end('{}');
    }
    const ids = (url.searchParams.get('ids') || '').split(',')
        .map(s => s.trim()).filter(s => /^\d+$/.test(s)).slice(0, 50);
    const result = {};
    ids.forEach(id => { result[id] = isOnline(id); });
    res.writeHead(200, Object.assign({ 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }, cors));
    res.end(JSON.stringify(result));
}

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const cors = corsHeaders(req);

    if (req.method === 'OPTIONS') {
        res.writeHead(204, cors);
        return res.end();
    }
    if (url.pathname === '/turn-credentials') return handleTurn(req, res, url, cors);
    if (url.pathname === '/presence') return handlePresence(req, res, url, cors);

    res.writeHead(200, Object.assign({ 'Content-Type': 'text/plain' }, cors));
    res.end('Unispark realtime server alive');
});

// ---------------------------------------------------------------------------
// WebSocket
// ---------------------------------------------------------------------------
const CALL_TYPES = ['call_offer', 'call_answer', 'ice_candidate', 'call_rejected', 'call_ended', 'call_ready'];
const CHAT_TYPES = ['chat_new', 'chat_typing', 'chat_read', 'chat_delivered'];

// Chat events carry no message content. The sender id is set here, so it cannot be faked.
function handleChatSignal(ws, data) {
    const target = String(data.targetUserId || '');
    if (!/^\d+$/.test(target) || target === ws.user.uid) return;
    if (ws.user.blocked.includes(target)) return;

    const out = { type: data.type, fromUserId: ws.user.uid };
    if (data.type === 'chat_typing') out.typing = !!data.typing;
    sendToUser(target, out);
}

const wss = new WebSocket.Server({
    server,
    maxPayload: 64 * 1024,
    verifyClient: info => !info.origin || originAllowed(info.origin)
});

function rateOk(ws) {
    const now = Date.now();
    if (!ws.rl || now - ws.rl.t > 5000) ws.rl = { t: now, n: 0 };
    ws.rl.n++;
    if (ws.rl.n > 200) {
        ws.close(4008, 'rate limit');
        return false;
    }
    return true;
}

function handleAuth(ws, data) {
    if (ws.user) return;
    const user = verifyToken(data.token);
    if (!user) {
        sendTo(ws, { type: 'auth_error' });
        ws.close(4001, 'invalid token');
        return;
    }
    clearTimeout(ws.authTimer);
    ws.user = user;
    ws.lastBeat = Date.now();
    if (!sockets.has(user.uid)) sockets.set(user.uid, new Set());
    sockets.get(user.uid).add(ws);
    markOnline(user.uid);
    sendTo(ws, { type: 'auth_success', userId: user.uid, facetime: canUseFacetime(user.uid) });
}

function handleCallSignal(ws, data) {
    const target = String(data.targetUserId || '');
    if (!target || target === ws.user.uid) return;
    const me = ws.user.uid;

    if (data.type === 'call_offer') {
        if (inFacetime(me)) return;
        if (inCall(me)) clearCall(me);          // treat as stale and let the new call through
        if (!isOnline(target)) {
            sendTo(ws, { type: 'call_rejected', fromUserId: target, unavailable: true });
            return;
        }
        if (inFacetime(target) || inCall(target)) {
            sendTo(ws, { type: 'call_rejected', fromUserId: target, busy: true });
            return;
        }
        data.callerName = ws.user.name;
        data.callerPhoto = safePhoto(data.callerPhoto);
    } else if (data.type === 'call_answer') {
        activeCalls.set(me, target);
        activeCalls.set(target, me);
    } else if (data.type === 'call_ended' || data.type === 'call_rejected') {
        clearCall(me);
    }

    sendToUser(target, Object.assign({}, data, { fromUserId: me }));
}

wss.on('connection', ws => {
    ws.user = null;
    ws.ft = { state: 'idle' };
    ws.lastBeat = Date.now();
    ws.authTimer = setTimeout(() => { if (!ws.user) ws.close(4002, 'auth timeout'); }, AUTH_TIMEOUT_MS);

    ws.on('message', raw => {
        if (!rateOk(ws)) return;

        let data;
        try { data = JSON.parse(raw); } catch (e) { return; }
        if (!data || typeof data.type !== 'string') return;

        if (data.type === 'auth') return handleAuth(ws, data);
        if (!ws.user) return;

        try {
            if (data.type === 'heartbeat') {
                ws.lastBeat = Date.now();
                sendTo(ws, { type: 'heartbeat_ack' });
            } else if (CALL_TYPES.includes(data.type)) {
                handleCallSignal(ws, data);
            } else if (data.type === 'ft_join') {
                ftJoin(ws);
            } else if (data.type === 'ft_skip') {
                ftSkip(ws);
            } else if (data.type === 'ft_leave') {
                ftLeave(ws);
            } else if (data.type === 'ft_signal') {
                ftSignal(ws, data);
            } else if (data.type === 'ft_block') {
                ftBlock(ws);
            } else if (CHAT_TYPES.includes(data.type)) {
                handleChatSignal(ws, data);
            } else if (data.type === 'chat_watch') {
                chatWatch(ws, data);
            }
            // The old 'offline' message is ignored on purpose: the close event plus the grace period handles it.
        } catch (err) {
            console.error('Message handling error:', err);
        }
    });

    ws.on('close', () => {
        unwatch(ws);
        clearTimeout(ws.authTimer);
        removeFromQueue(ws);
        if (ws.user && ws.ft.state === 'matched') endSession(ws, { requeueSelf: false, reason: 'disconnected' });
        if (ws.user) {
            const set = sockets.get(ws.user.uid);
            if (set) {
                set.delete(ws);
                                if (set.size === 0) {
                    sockets.delete(ws.user.uid);
                    const partner = clearCall(ws.user.uid);
                    if (partner) sendToUser(partner, { type: 'call_ended', fromUserId: ws.user.uid });
                }
            }
            scheduleOffline(ws.user.uid);
        }
    });

    ws.on('error', () => { /* close event follows */ });
});

// Drop sockets that stopped sending heartbeats
setInterval(() => {
    const now = Date.now();
    wss.clients.forEach(ws => {
        if (ws.user && now - ws.lastBeat > HEARTBEAT_TIMEOUT_MS) ws.terminate();
    });
}, 15000);

process.on('uncaughtException', err => console.error('Uncaught exception:', err));
process.on('unhandledRejection', err => console.error('Unhandled rejection:', err));

server.listen(PORT, () => {
    console.log(`Unispark realtime server running on port ${PORT}`);
    console.log(`Facetime: ${FACETIME_OPEN ? 'open to everyone' : 'testers only (' + [...FACETIME_TESTERS].join(', ') + ')'}`);
});