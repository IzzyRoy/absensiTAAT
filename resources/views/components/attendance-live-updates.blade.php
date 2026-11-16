<div
    data-attendance-live-updates
    data-url="{{ $url }}"
    data-revision="{{ $revision }}"
    hidden
></div>

@once
@push('scripts')
<script>
    (function () {
        if (document.documentElement.dataset.attendanceUpdatesInitialized === 'true') return;
        document.documentElement.dataset.attendanceUpdatesInitialized = 'true';

        const element = document.querySelector('[data-attendance-live-updates]');
        if (!element) return;

        const interval = 30000;
        let revision = element.dataset.revision;
        let inFlight = false;
        let reloading = false;

        async function checkForUpdates() {
            if (inFlight || reloading || document.hidden) return;

            inFlight = true;
            try {
                const response = await fetch(element.dataset.url, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);

                const update = await response.json();
                if (update.event !== 'attendance.gate.updated' || typeof update.revision !== 'string') {
                    throw new Error('Format pembaruan absensi tidak valid.');
                }

                if (update.revision !== revision) {
                    reloading = true;
                    window.location.reload();
                    return;
                }
            } catch (error) {
                console.warn('Pembaruan dashboard absensi gagal; akan dicoba kembali.', error);
            } finally {
                inFlight = false;
            }
        }

        window.setInterval(checkForUpdates, interval);
        document.addEventListener('visibilitychange', checkForUpdates);
    })();
</script>
@endpush
@endonce
