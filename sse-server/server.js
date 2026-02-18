'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { URL } = require('url');
const Redis = require('ioredis');

// ─── File Logging ────────────────────────────────────────────────────────────
const LOG_FILE = path.join(__dirname, 'sse-server.log');
const MAX_LOG_SIZE = 5 * 1024 * 1024; // 5 MB

function log(level, message) {
    const line = `[${new Date().toISOString()}] [${level}] ${message}\n`;
    try {
        const stats = fs.statSync(LOG_FILE);
        if (stats.size > MAX_LOG_SIZE) {
            fs.renameSync(LOG_FILE, LOG_FILE + '.old');
        }
    } catch (_) { /* file doesn't exist yet */ }
    fs.appendFileSync(LOG_FILE, line);
}

// ─── Configuration ───────────────────────────────────────────────────────────
const PORT = process.env.PORT || 3000;
const REDIS_SOCKET = process.env.REDIS_SOCKET || null;
const REDIS_PASSWORD = process.env.REDIS_PASSWORD && process.env.REDIS_PASSWORD !== 'null'
    ? process.env.REDIS_PASSWORD : null;
const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379';
const LARAVEL_API_URL = (process.env.LARAVEL_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');
const HEARTBEAT_INTERVAL_MS = parseInt(process.env.HEARTBEAT_INTERVAL_MS, 10) || 15000;

const DEVICE_FLOW_TIMEOUT_MS = 15 * 60 * 1000;  // 15 min
const SYNC_TIMEOUT_MS = 60 * 60 * 1000;          // 1 hour
const MERGE_TIMEOUT_MS = 15 * 60 * 1000;         // 15 min

/**
 * Build ioredis client with Unix socket or TCP URL.
 * When REDIS_SOCKET is set, uses { path, password } (ignores REDIS_URL).
 */
function createRedisClient(extraOpts = {}) {
    const opts = { maxRetriesPerRequest: 3, ...extraOpts };
    if (REDIS_SOCKET) {
        opts.path = REDIS_SOCKET;
        if (REDIS_PASSWORD) opts.password = REDIS_PASSWORD;
        return new Redis(opts);
    }
    return new Redis(REDIS_URL, opts);
}

// ─── State ───────────────────────────────────────────────────────────────────
let activeConnections = 0;

// Redis client for GET/SET operations (checking stored results)
const redis = createRedisClient({ lazyConnect: true });

redis.on('error', (err) => {
    log('ERROR', `[Redis] Connection error: ${err.message}`);
});

redis.on('connect', () => {
    log('INFO', '[Redis] Connected');
});

// ─── SSE Helpers ─────────────────────────────────────────────────────────────

function setupSSE(res) {
    res.writeHead(200, {
        'Content-Type': 'text/event-stream',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Connection': 'keep-alive',
        'X-Accel-Buffering': 'no',
    });
    res.write('retry: 3000\n\n');
}

function emitEvent(res, id, event, data) {
    if (res.writableEnded) return;
    res.write(`id: ${id}\n`);
    res.write(`event: ${event}\n`);
    res.write(`data: ${JSON.stringify(data)}\n\n`);
}

function emitError(res, statusCode, message) {
    res.writeHead(statusCode, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache' });
    res.write(`event: error\ndata: ${JSON.stringify({ error: message })}\n\n`);
    res.end();
}

/**
 * Create a per-connection Redis subscriber.
 * In ioredis, a client in subscribe mode cannot run regular commands,
 * so each SSE connection gets its own subscriber.
 */
function createSubscriber() {
    return createRedisClient({ lazyConnect: false });
}

/**
 * Forward an HTTP request to the Laravel API.
 * Used for auth validation (GET /me) and state fetching (GET /sync/state).
 */
async function fetchFromLaravel(path, bearerToken) {
    const url = `${LARAVEL_API_URL}${path}`;
    const headers = {
        'Accept': 'application/json',
        'User-Agent': 'UnityGameTranslator-SSE/1.0',
    };
    if (bearerToken) {
        headers['Authorization'] = `Bearer ${bearerToken}`;
    }

    log('INFO', `[Laravel] Fetching ${url}`);
    const response = await fetch(url, { headers, signal: AbortSignal.timeout(10000) });
    log('INFO', `[Laravel] Response: ${response.status} ${response.statusText}`);
    const body = await response.json();
    return { status: response.status, body };
}

// ─── Route: Device Flow SSE ─────────────────────────────────────────────────

async function handleDeviceFlow(req, res, deviceCode) {
    log('INFO', `[DeviceFlow] New connection for code: ${deviceCode}`);

    // Check if already authorized (late-connecting client)
    try {
        const stored = await redis.get(`sse:device:${deviceCode}:result`);
        if (stored) {
            const parsed = JSON.parse(stored);
            setupSSE(res);
            emitEvent(res, 1, parsed.event, parsed.data);
            res.end();
            log('INFO', `[DeviceFlow] Served stored result for code: ${deviceCode}`);
            return;
        }
    } catch (e) {
        log('ERROR', `[DeviceFlow] Redis GET error: ${e.message}`);
    }

    // Subscribe to Redis channel
    const sub = createSubscriber();
    let eventId = 0;
    let closed = false;

    const cleanup = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timeoutId);
        clearInterval(heartbeatId);
        sub.unsubscribe().catch(() => {});
        sub.quit().catch(() => {});
        activeConnections--;
    };

    activeConnections++;
    setupSSE(res);

    sub.subscribe(`sse:device:${deviceCode}`, (err) => {
        if (err) {
            log('ERROR', `[DeviceFlow] Subscribe error: ${err.message}`);
            emitEvent(res, ++eventId, 'error', { error: 'Internal error' });
            res.end();
            cleanup();
        }
    });

    sub.on('message', (channel, message) => {
        try {
            const parsed = JSON.parse(message);
            log('INFO', `[DeviceFlow] Received event: ${parsed.event} for code: ${deviceCode}`);
            emitEvent(res, ++eventId, parsed.event, parsed.data);
            res.end();
            cleanup();
        } catch (e) {
            log('ERROR', `[DeviceFlow] Message parse error: ${e.message}`);
        }
    });

    const heartbeatId = setInterval(() => {
        if (!res.writableEnded) res.write(': heartbeat\n\n');
    }, HEARTBEAT_INTERVAL_MS);

    const timeoutId = setTimeout(() => {
        emitEvent(res, ++eventId, 'expired', { error: 'Timeout' });
        res.end();
        cleanup();
    }, DEVICE_FLOW_TIMEOUT_MS);

    res.on('close', cleanup);
}

// ─── Route: Sync SSE ─────────────────────────────────────────────────────────

async function handleSync(req, res, uuid, clientHash) {
    log('INFO', `[Sync] New connection for UUID: ${uuid}`);

    // Extract Bearer token
    const authHeader = req.headers['authorization'] || '';
    const bearerToken = authHeader.startsWith('Bearer ') ? authHeader.slice(7) : null;
    if (!bearerToken) {
        log('WARN', '[Sync] Missing Authorization header');
        emitError(res, 401, 'Authorization required');
        return;
    }

    // Validate token via Laravel (1 HTTP call per SSE connection)
    let userId;
    try {
        const authResult = await fetchFromLaravel('/me', bearerToken);
        if (authResult.status === 401 || authResult.status === 403) {
            log('WARN', `[Sync] Auth rejected: ${authResult.status}`);
            emitError(res, 401, 'Invalid or expired token');
            return;
        }
        if (authResult.status !== 200) {
            log('ERROR', `[Sync] Auth unexpected status: ${authResult.status} body=${JSON.stringify(authResult.body)}`);
            emitError(res, 502, 'Auth service unavailable');
            return;
        }
        userId = authResult.body.id;
        log('INFO', `[Sync] Auth OK, userId=${userId}`);
    } catch (e) {
        log('ERROR', `[Sync] Auth validation error: ${e.message}`);
        emitError(res, 502, 'Auth service unavailable');
        return;
    }

    // Fetch initial state from Laravel
    let state;
    try {
        const hashParam = clientHash ? `&hash=${encodeURIComponent(clientHash)}` : '';
        const stateResult = await fetchFromLaravel(
            `/sync/state?uuid=${encodeURIComponent(uuid)}${hashParam}`,
            bearerToken
        );
        if (stateResult.status !== 200) {
            log('ERROR', `[Sync] State unexpected status: ${stateResult.status} body=${JSON.stringify(stateResult.body)}`);
            emitError(res, 502, 'State service unavailable');
            return;
        }
        state = stateResult.body;
        log('INFO', `[Sync] State fetched: exists=${state.exists}, role=${state.role}`);
    } catch (e) {
        log('ERROR', `[Sync] State fetch error: ${e.message}`);
        emitError(res, 502, 'State service unavailable');
        return;
    }

    // Setup SSE and emit initial state
    const sub = createSubscriber();
    let eventId = 0;
    let closed = false;

    const cleanup = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timeoutId);
        clearInterval(heartbeatId);
        sub.unsubscribe().catch(() => {});
        sub.quit().catch(() => {});
        activeConnections--;
    };

    activeConnections++;
    setupSSE(res);
    emitEvent(res, ++eventId, 'state', state);

    // Determine channels to subscribe to
    const translationId = state.translation?.id || state.main?.id || null;
    const channels = [`sse:uuid:${uuid}`];
    if (translationId) {
        channels.push(`sse:translation:${translationId}`);
    }

    sub.subscribe(...channels, (err) => {
        if (err) {
            log('ERROR', `[Sync] Subscribe error: ${err.message}`);
            emitEvent(res, ++eventId, 'error', { error: 'Internal error' });
            res.end();
            cleanup();
        }
    });

    sub.on('message', async (channel, message) => {
        if (closed) return;
        try {
            const parsed = JSON.parse(message);

            if (parsed.event === 'uuid_changed') {
                // UUID lineage changed — re-fetch full state
                const hashParam = clientHash ? `&hash=${encodeURIComponent(clientHash)}` : '';
                const stateResult = await fetchFromLaravel(
                    `/sync/state?uuid=${encodeURIComponent(uuid)}${hashParam}`,
                    bearerToken
                );
                if (stateResult.status === 200) {
                    emitEvent(res, ++eventId, 'state', stateResult.body);
                }
            } else if (parsed.event === 'translation_updated') {
                // Same translation updated — emit lightweight update
                emitEvent(res, ++eventId, 'translation_updated', parsed.data);
            }
        } catch (e) {
            log('ERROR', `[Sync] Message handler error: ${e.message}`);
        }
    });

    const heartbeatId = setInterval(() => {
        if (!res.writableEnded) res.write(': heartbeat\n\n');
    }, HEARTBEAT_INTERVAL_MS);

    const timeoutId = setTimeout(() => {
        if (!res.writableEnded) res.end();
        cleanup();
    }, SYNC_TIMEOUT_MS);

    res.on('close', cleanup);
}

// ─── Route: Merge SSE ────────────────────────────────────────────────────────

async function handleMerge(req, res, token) {
    log('INFO', `[Merge] New connection for token: ${token.substring(0, 8)}...`);

    // Check if already completed (late-connecting client)
    try {
        const stored = await redis.get(`sse:merge:${token}:result`);
        if (stored) {
            const parsed = JSON.parse(stored);
            setupSSE(res);
            emitEvent(res, 1, parsed.event, parsed.data);
            res.end();
            return;
        }
    } catch (e) {
        log('ERROR', `[Merge] Redis GET error: ${e.message}`);
    }

    // Subscribe to Redis channel
    const sub = createSubscriber();
    let eventId = 0;
    let closed = false;

    const cleanup = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timeoutId);
        clearInterval(heartbeatId);
        sub.unsubscribe().catch(() => {});
        sub.quit().catch(() => {});
        activeConnections--;
    };

    activeConnections++;
    setupSSE(res);

    sub.subscribe(`sse:merge:${token}`, (err) => {
        if (err) {
            log('ERROR', `[Merge] Subscribe error: ${err.message}`);
            emitEvent(res, ++eventId, 'error', { error: 'Internal error' });
            res.end();
            cleanup();
        }
    });

    sub.on('message', (channel, message) => {
        try {
            const parsed = JSON.parse(message);
            emitEvent(res, ++eventId, parsed.event, parsed.data);
            res.end();
            cleanup();
        } catch (e) {
            log('ERROR', `[Merge] Message parse error: ${e.message}`);
        }
    });

    const heartbeatId = setInterval(() => {
        if (!res.writableEnded) res.write(': heartbeat\n\n');
    }, HEARTBEAT_INTERVAL_MS);

    const timeoutId = setTimeout(() => {
        if (!res.writableEnded) res.end();
        cleanup();
    }, MERGE_TIMEOUT_MS);

    res.on('close', cleanup);
}

// ─── HTTP Server ─────────────────────────────────────────────────────────────

const server = http.createServer(async (req, res) => {
    const parsedUrl = new URL(req.url, `http://localhost:${PORT}`);
    const pathname = parsedUrl.pathname;
    const method = req.method;

    // Health check (also serves as root for cPanel Passenger availability check)
    if (method === 'GET' && (pathname === '/health' || pathname === '/')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok', connections: activeConnections }));
        return;
    }

    // Logs endpoint — view recent logs for debugging
    if (method === 'GET' && pathname === '/logs') {
        try {
            const content = fs.readFileSync(LOG_FILE, 'utf8');
            const lines = content.split('\n').filter(Boolean);
            const last100 = lines.slice(-100).join('\n');
            res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end(last100);
        } catch (e) {
            res.writeHead(200, { 'Content-Type': 'text/plain' });
            res.end('No logs yet');
        }
        return;
    }

    // Device Flow SSE: GET /auth/device/:code/stream
    const deviceMatch = pathname.match(/^\/auth\/device\/([^/]+)\/stream$/);
    if (method === 'GET' && deviceMatch) {
        await handleDeviceFlow(req, res, decodeURIComponent(deviceMatch[1]));
        return;
    }

    // Sync SSE: GET /sync/stream?uuid=xxx&hash=yyy
    if (method === 'GET' && pathname === '/sync/stream') {
        const uuid = parsedUrl.searchParams.get('uuid');
        if (!uuid) {
            emitError(res, 400, 'uuid parameter required');
            return;
        }
        const hash = parsedUrl.searchParams.get('hash');
        await handleSync(req, res, uuid, hash);
        return;
    }

    // Merge SSE: GET /merge-preview/:token/stream
    const mergeMatch = pathname.match(/^\/merge-preview\/([^/]+)\/stream$/);
    if (method === 'GET' && mergeMatch) {
        await handleMerge(req, res, decodeURIComponent(mergeMatch[1]));
        return;
    }

    // 404
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Not found' }));
});

// ─── Startup ─────────────────────────────────────────────────────────────────

async function start() {
    await redis.connect();
    server.listen(PORT, () => {
        log('INFO', `[SSE Server] Listening on port ${PORT}`);
        log('INFO', `[SSE Server] Redis: ${REDIS_SOCKET ? `socket ${REDIS_SOCKET}` : REDIS_URL}`);
        log('INFO', `[SSE Server] Laravel API: ${LARAVEL_API_URL}`);
    });
}

start().catch((err) => {
    log('ERROR', `[SSE Server] Fatal startup error: ${err}`);
    process.exit(1);
});

// Graceful shutdown
process.on('SIGTERM', () => {
    log('INFO', '[SSE Server] SIGTERM received, shutting down...');
    server.close(() => {
        redis.quit().then(() => process.exit(0));
    });
});

process.on('SIGINT', () => {
    log('INFO', '[SSE Server] SIGINT received, shutting down...');
    server.close(() => {
        redis.quit().then(() => process.exit(0));
    });
});
