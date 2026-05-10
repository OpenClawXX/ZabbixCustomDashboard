// data-bridge.jsx
//
// Replaces the original mock-data data.jsx. Reads window.ZBX_BOOT (inlined by
// the PHP view) and exposes the same window globals the rest of the app
// already consumes. Then sets up a polling loop against TCS_DATA_URL so the
// values refresh without a full page reload.
//
// If ZBX_BOOT is null (no hostid was passed in the URL), we fall back to a
// tiny stub so the UI still renders an empty-state instead of crashing.

(function () {
    const STUB_HOST = {
        hostid: "",
        host: "—",
        visible_name: "Select a host (?hostid=NNNN)",
        ip: "",
        status: "unknown",
        available: 2,
        maintenance: 0,
        proxy: "",
        templates: [],
        groups: [],
        uptime: 0,
        lastSeen: "—"
    };

    const STUB_ITEMS = {};

    function applyBoot(boot) {
        if (!boot) {
            window.ZBX_HOST = STUB_HOST;
            window.ZBX_ITEMS = STUB_ITEMS;
            window.SYSTEM_INFO = [];
            window.NETWORK_INFO = [];
            window.PF_CLIENTS = [];
            window.PF_AUTH_FAILS = [];
            window.ZBX_EVENTS = [];
            window.ALERTS_SUMMARY = {
                associationFailures: 0, authFailures: 0,
                networkIssues: 0, packetLoss: 0,
                totalClients: 0, activeClients: 0
            };
            window.WIRED_PORTS = [];
            return;
        }

        window.ZBX_HOST      = boot.host        || STUB_HOST;
        window.ZBX_ITEMS     = boot.items       || STUB_ITEMS;
        window.SYSTEM_INFO   = boot.systemInfo  || [];
        window.NETWORK_INFO  = boot.networkInfo || [];
        window.PF_CLIENTS    = boot.pfClients   || [];
        window.PF_AUTH_FAILS = boot.pfAuthFails || [];
        window.ZBX_EVENTS    = boot.events      || [];
        window.ALERTS_SUMMARY = boot.alerts || {
            associationFailures: 0, authFailures: 0,
            networkIssues: 0, packetLoss: 0,
            totalClients: 0, activeClients: 0
        };
        window.WIRED_PORTS   = boot.wiredPorts  || [];
    }

    // Initial paint comes from the server-inlined snapshot.
    applyBoot(window.ZBX_BOOT);

    // Tweak defaults expected by app.jsx — kept here so we don't have to
    // touch app.jsx at all.
    window.TWEAK_DEFAULTS = window.TWEAK_DEFAULTS || {
        accent: "#d92929",
        fontMono: "JetBrains Mono",
        density: "comfortable",
        showSourceBadges: true,
        showSidecar: true
    };

    // ----- Live refresh ------------------------------------------------------
    // Poll the JSON endpoint every 30s and merge updates into the globals.
    // The existing components read window.ZBX_ITEMS each render, so updating
    // the global is enough to refresh on the next setState. If you'd rather
    // wire real reactivity, expose a tiny event bus instead and dispatch
    // 'tcs:data' on every fetch.

    const REFRESH_MS = 30_000;
    const hostid = window.ZBX_HOST && window.ZBX_HOST.hostid;
    const url = window.TCS_DATA_URL;

    if (hostid && url) {
        const tick = async () => {
            try {
                const resp = await fetch(`${url}&hostid=${encodeURIComponent(hostid)}`, {
                    credentials: "same-origin",
                    headers: { "Accept": "application/json" }
                });
                if (!resp.ok) return;
                const fresh = await resp.json();
                applyBoot({
                    ...window.ZBX_BOOT,
                    host:       fresh.host       ?? window.ZBX_HOST,
                    items:      fresh.items      ?? window.ZBX_ITEMS,
                    events:     fresh.events     ?? window.ZBX_EVENTS,
                    alerts:     fresh.alerts     ?? window.ALERTS_SUMMARY,
                    wiredPorts: fresh.wiredPorts ?? window.WIRED_PORTS
                });
                window.dispatchEvent(new CustomEvent("tcs:data", { detail: fresh }));
            } catch (e) {
                console.warn("[tcs] data refresh failed:", e);
            }
        };
        setInterval(tick, REFRESH_MS);
    }
})();
