<script>
    (() => {
        let serverTime = @json(now()->getTimestampMs());
        let sampledAt = performance.now();
        const refreshUrl = @json($refreshClock ?? null);
        let refreshing = false;

        window.sedonaClock = {
            now: () => serverTime + Math.floor(performance.now() - sampledAt),
        };

        if (!refreshUrl) return;

        async function refresh() {
            if (refreshing || document.visibilityState === 'hidden') return;
            refreshing = true;
            try {
                const response = await fetch(refreshUrl, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                    signal: AbortSignal.timeout(5000),
                });
                if (!response.ok) return;

                const { timestamp } = await response.json();
                if (Number.isSafeInteger(timestamp) && timestamp > 0) {
                    serverTime = timestamp;
                    sampledAt = performance.now();
                }
            } catch {
                // Keep advancing from the last server sample during a connection interruption.
            } finally {
                refreshing = false;
            }
        }

        setInterval(refresh, 60000);
        document.addEventListener('visibilitychange', refresh);
        window.addEventListener('online', refresh);
        window.addEventListener('pageshow', refresh);
    })();
</script>
