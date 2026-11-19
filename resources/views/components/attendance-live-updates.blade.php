<div class="d-flex justify-content-end mb-3">
    <span
        class="badge rounded-pill bg-secondary"
        data-attendance-connection-status
        role="status"
        aria-live="polite"
    >
        Menghubungkan pembaruan langsung...
    </span>
</div>

<div
    data-attendance-live-updates
    data-url="{{ $url }}"
    data-revision="{{ $revision }}"
    data-channel="{{ $channel }}"
    data-channel-prefix="{{ $channelPrefix ?? '' }}"
    hidden
></div>

@once
@push('scripts')
<script>
    (function () {
        if (document.documentElement.dataset.attendanceUpdatesInitialized === 'true') return;
        document.documentElement.dataset.attendanceUpdatesInitialized = 'true';

        const element = document.querySelector('[data-attendance-live-updates]');
        const status = document.querySelector('[data-attendance-connection-status]');
        if (!element || !status) return;

        const echo = window.Echo;
        const handledEventIds = new Set();
        let channel = element.dataset.channel;
        let inFlight = false;
        let pendingSync = false;
        let reloading = false;
        let retryCount = 0;
        let retryTimer = null;
        let eventTimer = null;

        function setStatus(message, color) {
            status.textContent = message;
            status.classList.remove('bg-secondary', 'bg-success', 'bg-warning', 'bg-danger');
            status.classList.add('bg-' + color);
        }

        function scheduleRetry() {
            if (retryTimer || retryCount >= 3 || reloading) return;

            const delay = Math.min(1000 * (2 ** retryCount), 8000);
            retryCount++;
            retryTimer = window.setTimeout(() => {
                retryTimer = null;
                synchronize();
            }, delay);
        }

        async function synchronize() {
            if (reloading) return;
            if (document.hidden) {
                pendingSync = true;
                return;
            }
            if (inFlight) {
                pendingSync = true;
                return;
            }

            inFlight = true;
            pendingSync = false;

            try {
                const response = await fetch(element.dataset.url, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);

                const update = await response.json();
                if (typeof update.revision !== 'string') {
                    throw new Error('Format sinkronisasi absensi tidak valid.');
                }

                retryCount = 0;
                if (retryTimer) {
                    window.clearTimeout(retryTimer);
                    retryTimer = null;
                }

                if (update.revision !== element.dataset.revision && !reloading) {
                    reloading = true;
                    window.location.reload();
                }
            } catch (error) {
                console.error('Sinkronisasi dashboard absensi gagal.', error);
                setStatus('Gagal sinkronisasi; mencoba kembali...', 'warning');
                scheduleRetry();
            } finally {
                inFlight = false;
                if (pendingSync && !reloading && !document.hidden) {
                    pendingSync = false;
                    window.setTimeout(synchronize, 100);
                }
            }
        }

        function onAttendanceUpdate(event) {
            if (event?.event_id && handledEventIds.has(event.event_id)) return;
            if (event?.event_id) {
                handledEventIds.add(event.event_id);
                if (handledEventIds.size > 200) {
                    handledEventIds.delete(handledEventIds.values().next().value);
                }
            }

            if (document.hidden) {
                pendingSync = true;
                return;
            }
            if (eventTimer) window.clearTimeout(eventTimer);
            eventTimer = window.setTimeout(() => {
                if (document.hidden || reloading) {
                    pendingSync = true;
                    return;
                }

                reloading = true;
                window.location.reload();
            }, 250);
        }

        function subscribe() {
            if (!echo || !channel) {
                setStatus('WebSocket belum dikonfigurasi', 'danger');
                return;
            }

            echo.private(channel)
                .listen('.attendance.gate.updated', onAttendanceUpdate)
                .listen('.attendance.subject.updated', onAttendanceUpdate)
                .subscribed(() => {
                    setStatus('Pembaruan langsung aktif', 'success');
                })
                .error((error) => {
                    console.error('Gagal berlangganan kanal absensi.', error);
                    setStatus('Akses pembaruan langsung gagal', 'danger');
                });
        }

        if (!echo) {
            setStatus('WebSocket belum dikonfigurasi', 'danger');
            return;
        }

        const connection = echo.connector.pusher.connection;
        connection.bind('state_change', ({ current }) => {
            if (current === 'connected') {
                setStatus('Pembaruan langsung aktif', 'success');
                retryCount = 0;
                synchronize();
            } else if (current === 'connecting' || current === 'initialized') {
                setStatus('Menghubungkan pembaruan langsung...', 'secondary');
            } else if (current === 'disconnected' || current === 'unavailable') {
                setStatus('Koneksi terputus; menghubungkan ulang...', 'warning');
            } else if (current === 'failed') {
                setStatus('Koneksi WebSocket gagal', 'danger');
            }
        });
        connection.bind('error', (error) => {
            console.error('Koneksi WebSocket mengalami kesalahan.', error);
            setStatus('Koneksi WebSocket bermasalah', 'warning');
        });

        window.addEventListener('attendance:student-selected', (event) => {
            const studentId = Number(event.detail?.studentId);
            if (!Number.isSafeInteger(studentId) || studentId < 1 || !element.dataset.channelPrefix) return;

            if (channel) echo.leave(channel);
            channel = element.dataset.channelPrefix + studentId;
            element.dataset.channel = channel;
            if (typeof event.detail?.revision === 'string') {
                element.dataset.revision = event.detail.revision;
            }
            subscribe();
            synchronize();
        });

        window.addEventListener('online', () => setStatus('Menghubungkan ulang...', 'warning'));
        window.addEventListener('offline', () => setStatus('Perangkat sedang offline', 'danger'));
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && (pendingSync || connection.state === 'connected')) {
                synchronize();
            }
        });

        subscribe();
        if (connection.state === 'connected') synchronize();
    })();
</script>
@endpush
@endonce
