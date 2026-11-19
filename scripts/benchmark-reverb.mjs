import { createHmac, createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { performance } from 'node:perf_hooks';

const args = process.argv.slice(2);
const connectionsIndex = args.indexOf('--connections');
const connectionCount = Number(args[connectionsIndex + 1]);
const eventCount = 5;
const host = process.env.BENCH_REVERB_HOST || '127.0.0.1';
const port = Number(process.env.BENCH_REVERB_PORT || 9080);
const appId = process.env.BENCH_REVERB_APP_ID;
const appKey = process.env.BENCH_REVERB_APP_KEY;
const appSecret = process.env.BENCH_REVERB_APP_SECRET;
const channelName = 'attendance-benchmark';

if (![50, 100, 200].includes(connectionCount)) {
    throw new Error('--connections must be 50, 100, or 200.');
}
if (!appId || !appKey || !appSecret) {
    throw new Error('Benchmark Reverb credentials must be provided through BENCH_REVERB_APP_* environment variables.');
}

let syncRevision = 'initial';
let syncRequests = 0;
const syncServer = createServer((request, response) => {
    if (request.url !== '/attendance/updates') {
        response.writeHead(404).end();
        return;
    }

    syncRequests++;
    response.writeHead(200, {
        'Cache-Control': 'no-store',
        'Content-Type': 'application/json',
    });
    response.end(JSON.stringify({ revision: syncRevision }));
});

await new Promise((resolve) => syncServer.listen(0, '127.0.0.1', resolve));
const syncPort = syncServer.address().port;
const syncUrl = `http://127.0.0.1:${syncPort}/attendance/updates`;
const sockets = [];
const eventLatencies = [];
const dashboardUpdateLatencies = [];
let websocketBytesIn = 0;
let websocketBytesOut = 0;
let httpBytesIn = 0;
let httpBytesOut = 0;

function parseMessage(data) {
    return typeof data === 'string' ? data : Buffer.from(data).toString('utf8');
}

function timeoutPromise(promise, ms, label) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error(`Timed out while ${label}.`)), ms);
        }),
    ]).finally(() => clearTimeout(timer));
}

async function publish(eventId, sentAt) {
    const body = JSON.stringify({
        name: 'benchmark.attendance.updated',
        channels: [channelName],
        data: JSON.stringify({ event_id: eventId, sent_at: sentAt }),
    });
    const path = `/apps/${appId}/events`;
    const parameters = {
        auth_key: appKey,
        auth_timestamp: Math.floor(Date.now() / 1000).toString(),
        auth_version: '1.0',
        body_md5: createHash('md5').update(body).digest('hex'),
    };
    const query = new URLSearchParams(
        Object.entries(parameters).sort(([left], [right]) => left.localeCompare(right)),
    ).toString();
    const signature = createHmac('sha256', appSecret)
        .update(`POST\n${path}\n${query}`)
        .digest('hex');
    const url = `http://${host}:${port}${path}?${query}&auth_signature=${signature}`;

    httpBytesOut += Buffer.byteLength(url) + Buffer.byteLength(body);
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
    });
    const responseBody = await response.text();
    httpBytesIn += Buffer.byteLength(responseBody);
    if (!response.ok) {
        throw new Error(`Reverb publisher returned HTTP ${response.status}: ${responseBody}`);
    }
}

try {
    const clients = Array.from({ length: connectionCount }, () => ({
        established: false,
        subscribed: false,
        receivedEvents: 0,
        syncFailures: 0,
    }));
    const connectionStartedAt = performance.now();

    const subscriptionsReady = Promise.all(clients.map((client) => new Promise((resolve, reject) => {
        const socket = new WebSocket(
            `ws://${host}:${port}/app/${appKey}?protocol=7&client=js&version=8.6.0&flash=false`,
        );
        sockets.push(socket);
        const timeout = setTimeout(() => reject(new Error('WebSocket subscription timed out.')), 15000);

        socket.addEventListener('error', () => reject(new Error('A WebSocket client failed to connect.')));
        socket.addEventListener('close', () => {
            if (!client.subscribed) reject(new Error('A WebSocket closed before subscribing.'));
        });
        socket.addEventListener('message', async ({ data }) => {
            const raw = parseMessage(data);
            websocketBytesIn += Buffer.byteLength(raw);
            let message;
            try {
                message = JSON.parse(raw);
            } catch {
                reject(new Error('Reverb returned an invalid WebSocket frame.'));
                return;
            }

            if (message.event === 'pusher:connection_established' && !client.subscribed) {
                client.established = true;
                const subscribeFrame = JSON.stringify({
                    event: 'pusher:subscribe',
                    data: { channel: channelName },
                });
                websocketBytesOut += Buffer.byteLength(subscribeFrame);
                socket.send(subscribeFrame);
            } else if (message.event === 'pusher_internal:subscription_succeeded') {
                client.subscribed = true;
                clearTimeout(timeout);
                resolve();
            } else if (message.event === 'pusher:ping') {
                const pongFrame = JSON.stringify({ event: 'pusher:pong', data: {} });
                websocketBytesOut += Buffer.byteLength(pongFrame);
                socket.send(pongFrame);
            } else if (message.event === 'benchmark.attendance.updated') {
                const payload = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;
                client.receivedEvents++;
                eventLatencies.push(Date.now() - payload.sent_at);
                syncRevision = payload.event_id;
                httpBytesOut += Buffer.byteLength('GET /attendance/updates');
                try {
                    const response = await fetch(syncUrl, { cache: 'no-store' });
                    const body = await response.text();
                    httpBytesIn += Buffer.byteLength(body);
                    dashboardUpdateLatencies.push(Date.now() - payload.sent_at);
                    if (!response.ok) client.syncFailures++;
                } catch {
                    client.syncFailures++;
                }
            }
        });
    })));

    await timeoutPromise(subscriptionsReady, 20000, 'waiting for clients to subscribe');
    const connectionTimeMs = performance.now() - connectionStartedAt;
    const activeConnections = sockets.filter((socket) => socket.readyState === WebSocket.OPEN).length;

    for (let index = 0; index < eventCount; index++) {
        await publish(`benchmark-${connectionCount}-${index}`, Date.now());
        await new Promise((resolve) => setTimeout(resolve, 300));
    }
    await new Promise((resolve) => setTimeout(resolve, 1200));

    const stableConnections = sockets.filter((socket) => socket.readyState === WebSocket.OPEN).length;
    const expectedEvents = connectionCount * eventCount;
    const receivedEvents = clients.reduce((sum, client) => sum + client.receivedEvents, 0);
    const stable = stableConnections === connectionCount
        && receivedEvents === expectedEvents
        && clients.every((client) => client.syncFailures === 0);

    function percentile(values, percentileValue) {
        if (values.length === 0) return null;
        const ordered = [...values].sort((a, b) => a - b);
        return ordered[Math.min(ordered.length - 1, Math.ceil(percentileValue * ordered.length) - 1)];
    }

    console.log(JSON.stringify({
        connections_requested: connectionCount,
        connections_active: activeConnections,
        connections_stable_at_end: stableConnections,
        connection_time_ms: Math.round(connectionTimeMs),
        events_published: eventCount,
        event_deliveries_expected: expectedEvents,
        event_deliveries_received: receivedEvents,
        event_latency_ms: {
            median: percentile(eventLatencies, 0.5),
            p95: percentile(eventLatencies, 0.95),
            max: percentile(eventLatencies, 1),
        },
        dashboard_sync_latency_ms: {
            median: percentile(dashboardUpdateLatencies, 0.5),
            p95: percentile(dashboardUpdateLatencies, 0.95),
            max: percentile(dashboardUpdateLatencies, 1),
        },
        sync_http_requests: syncRequests,
        bandwidth_application_bytes: {
            websocket_in: websocketBytesIn,
            websocket_out: websocketBytesOut,
            http_in: httpBytesIn,
            http_out: httpBytesOut,
            total: websocketBytesIn + websocketBytesOut + httpBytesIn + httpBytesOut,
        },
        mysql_queries: 0,
        mysql_note: 'Synthetic synchronization endpoint; it does not connect to MySQL.',
        stable,
    }));

    if (!stable) process.exitCode = 1;
} finally {
    for (const socket of sockets) {
        if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
            socket.close();
        }
    }
    await new Promise((resolve) => syncServer.close(resolve));
}
