'use strict';

const http = require('http');
const { URL } = require('url');
const Redis = require('ioredis');

// ─── Configuration ───────────────────────────────────────────────────────────
const PORT = process.env.PORT || 3000;
const REDIS_SOCKET = process.env.REDIS_SOCKET || null;
const REDIS_PASSWORD = process.env.REDIS_PASSWORD && process.env.REDIS_PASSWORD !== 'null'
    ? process.env.REDIS_PASSWORD : null;
const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379';
const LARAVEL_API_URL = (process.env.LARAVEL_API_URL || 'http://localhost:8000/api/v1').replace(/\/$/, '');
const HEARTBEAT_INTERVAL_MS = parseInt(process.env.HEARTBEAT_INTERVAL_MS, 10) || 15000;

// Security: connection limits
const MAX_CONNECTIONS = parseInt(process.env.MAX_CONNECTIONS, 10) || 1000;
const PER_IP_LIMIT = parseInt(process.env.PER_IP_LIMIT, 10) || 10;

// Security: CORS — restrict to the main website origin
const ALLOWED_ORIGIN = process.env.ALLOWED_ORIGIN || 'https://unitygametranslator.asymptomatikgames.com';

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
const ipConnections = new Map(); // Track connections per IP

// Redis client for GET/SET operations (checking stored results)
const redis = createRedisClient({ lazyConnect: true });

redis.on('error', (err) => {
    console.error('[Redis] Connection error:', err.message);
});

redis.on('connect', () => {
    console.log('[Redis] Connected');
});

// ─── Security Helpers ───────────────────────────────────────────────────────

/**
 * Extract client IP from request (supports X-Forwarded-For behind proxy).
 */
function getClientIp(req) {
    const forwarded = req.headers['x-forwarded-for'];
    if (forwarded) {
        return forwarded.split(',')[0].trim();
    }
    return req.socket.remoteAddress || '0.0.0.0';
}

/**
 * Check rate limits (global + per-IP). Returns error message or null if OK.
 */
function checkRateLimit(req) {
    if (activeConnections >= MAX_CONNECTIONS) {
        return 'Server at capacity';
    }
    const ip = getClientIp(req);
    const count = ipConnections.get(ip) || 0;
    if (count >= PER_IP_LIMIT) {
        return 'Too many connections from this IP';
    }
    return null;
}

/**
 * Track a new connection for rate limiting.
 * Returns a cleanup function to call when the connection closes.
 */
function trackConnection(req) {
    const ip = getClientIp(req);
    activeConnections++;
    ipConnections.set(ip, (ipConnections.get(ip) || 0) + 1);

    return () => {
        activeConnections--;
        const count = (ipConnections.get(ip) || 1) - 1;
        if (count <= 0) {
            ipConnections.delete(ip);
        } else {
            ipConnections.set(ip, count);
        }
    };
}

/**
 * Add CORS headers to a response.
 */
function setCorsHeaders(res) {
    res.setHeader('Access-Control-Allow-Origin', ALLOWED_ORIGIN);
    res.setHeader('Access-Control-Allow-Credentials', 'false');
}

// ─── SSE Helpers ─────────────────────────────────────────────────────────────

function setupSSE(res) {
    res.writeHead(200, {
        'Content-Type': 'text/event-stream',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Connection': 'keep-alive',
        'X-Accel-Buffering': 'no',
        'Access-Control-Allow-Origin': ALLOWED_ORIGIN,
        'Access-Control-Allow-Credentials': 'false',
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
    res.writeHead(statusCode, {
        'Content-Type': 'text/event-stream',
        'Cache-Control': 'no-cache',
        'Access-Control-Allow-Origin': ALLOWED_ORIGIN,
    });
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

    const response = await fetch(url, { headers, signal: AbortSignal.timeout(10000) });
    const body = await response.json();
    return { status: response.status, body };
}

// ─── Route: Device Flow SSE ─────────────────────────────────────────────────

async function handleDeviceFlow(req, res, deviceCode) {
    // Check if already authorized (late-connecting client)
    try {
        const stored = await redis.get(`sse:device:${deviceCode}:result`);
        if (stored) {
            const parsed = JSON.parse(stored);
            setupSSE(res);
            emitEvent(res, 1, parsed.event, parsed.data);
            res.end();
            return;
        }
    } catch (e) {
        console.error('[DeviceFlow] Redis GET error:', e.message);
    }

    // Subscribe to Redis channel
    const sub = createSubscriber();
    let eventId = 0;
    let closed = false;
    const releaseConnection = trackConnection(req);

    const cleanup = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timeoutId);
        clearInterval(heartbeatId);
        sub.unsubscribe().catch(() => {});
        sub.quit().catch(() => {});
        releaseConnection();
    };

    setupSSE(res);

    sub.subscribe(`sse:device:${deviceCode}`, (err) => {
        if (err) {
            console.error('[DeviceFlow] Subscribe error:', err.message);
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
            console.error('[DeviceFlow] Message parse error:', e.message);
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
    // Extract Bearer token
    const authHeader = req.headers['authorization'] || '';
    const bearerToken = authHeader.startsWith('Bearer ') ? authHeader.slice(7) : null;
    if (!bearerToken) {
        emitError(res, 401, 'Authorization required');
        return;
    }

    // Validate token via Laravel (1 HTTP call per SSE connection)
    let userId;
    try {
        const authResult = await fetchFromLaravel('/me', bearerToken);
        if (authResult.status === 401 || authResult.status === 403) {
            emitError(res, 401, 'Invalid or expired token');
            return;
        }
        if (authResult.status !== 200) {
            emitError(res, 502, 'Auth service unavailable');
            return;
        }
        userId = authResult.body.id;
    } catch (e) {
        console.error('[Sync] Auth validation error:', e.message);
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
            emitError(res, 502, 'State service unavailable');
            return;
        }
        state = stateResult.body;
    } catch (e) {
        console.error('[Sync] State fetch error:', e.message);
        emitError(res, 502, 'State service unavailable');
        return;
    }

    // Setup SSE and emit initial state
    const sub = createSubscriber();
    let eventId = 0;
    let closed = false;
    const releaseConnection = trackConnection(req);

    const cleanup = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timeoutId);
        clearInterval(heartbeatId);
        sub.unsubscribe().catch(() => {});
        sub.quit().catch(() => {});
        releaseConnection();
    };

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
            console.error('[Sync] Subscribe error:', err.message);
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
            console.error('[Sync] Message handler error:', e.message);
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
        console.error('[Merge] Redis GET error:', e.message);
    }

    // Subscribe to Redis channel
    const sub = createSubscriber();
    let eventId = 0;
    let closed = false;
    const releaseConnection = trackConnection(req);

    const cleanup = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timeoutId);
        clearInterval(heartbeatId);
        sub.unsubscribe().catch(() => {});
        sub.quit().catch(() => {});
        releaseConnection();
    };

    setupSSE(res);

    sub.subscribe(`sse:merge:${token}`, (err) => {
        if (err) {
            console.error('[Merge] Subscribe error:', err.message);
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
            console.error('[Merge] Message parse error:', e.message);
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

    // CORS preflight
    if (method === 'OPTIONS') {
        res.writeHead(204, {
            'Access-Control-Allow-Origin': ALLOWED_ORIGIN,
            'Access-Control-Allow-Methods': 'GET',
            'Access-Control-Allow-Headers': 'Authorization',
            'Access-Control-Max-Age': '86400',
        });
        res.end();
        return;
    }

    // Health check (also serves as root for cPanel Passenger availability check)
    if (method === 'GET' && (pathname === '/health' || pathname === '/')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok' }));
        return;
    }

    // Rate limiting check for SSE endpoints
    const rateLimitError = checkRateLimit(req);
    if (rateLimitError) {
        const statusCode = rateLimitError.includes('capacity') ? 503 : 429;
        setCorsHeaders(res);
        emitError(res, statusCode, rateLimitError);
        return;
    }

    // Device Flow SSE: GET /auth/device/:code/stream
    // Input validation: device codes are alphanumeric with hyphens, max 128 chars
    const deviceMatch = pathname.match(/^\/auth\/device\/([A-Za-z0-9_-]{1,128})\/stream$/);
    if (method === 'GET' && deviceMatch) {
        await handleDeviceFlow(req, res, deviceMatch[1]);
        return;
    }

    // Sync SSE: GET /sync/stream?uuid=xxx&hash=yyy
    if (method === 'GET' && pathname === '/sync/stream') {
        const uuid = parsedUrl.searchParams.get('uuid');
        if (!uuid || uuid.length > 256) {
            emitError(res, 400, 'uuid parameter required');
            return;
        }
        const hash = parsedUrl.searchParams.get('hash');
        if (hash && hash.length > 256) {
            emitError(res, 400, 'Invalid hash parameter');
            return;
        }
        await handleSync(req, res, uuid, hash);
        return;
    }

    // Merge SSE: GET /merge-preview/:token/stream
    // Input validation: merge tokens are alphanumeric, max 128 chars
    const mergeMatch = pathname.match(/^\/merge-preview\/([A-Za-z0-9_-]{1,128})\/stream$/);
    if (method === 'GET' && mergeMatch) {
        await handleMerge(req, res, mergeMatch[1]);
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
        console.log(`[SSE Server] Listening on port ${PORT}`);
        console.log(`[SSE Server] Redis: ${REDIS_SOCKET ? `socket ${REDIS_SOCKET}` : REDIS_URL}`);
        console.log(`[SSE Server] Laravel API: ${LARAVEL_API_URL}`);
        console.log(`[SSE Server] CORS origin: ${ALLOWED_ORIGIN}`);
        console.log(`[SSE Server] Max connections: ${MAX_CONNECTIONS} (${PER_IP_LIMIT}/IP)`);
    });
}

start().catch((err) => {
    console.error('[SSE Server] Fatal startup error:', err);
    process.exit(1);
});

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[SSE Server] SIGTERM received, shutting down...');
    server.close(() => {
        redis.quit().then(() => process.exit(0));
    });
});

process.on('SIGINT', () => {
    console.log('[SSE Server] SIGINT received, shutting down...');
    server.close(() => {
        redis.quit().then(() => process.exit(0));
    });
});
