// voip-bridge.jsx
//
// Data layer for the VoIP / 3CX page. ActionVoip embeds an SSR boot snapshot
// in window.VOIP_BOOT — this bridge unpacks it into the window.VOIP_* globals
// voip-app.jsx reads (VOIP_PBX / VOIP_SERVICES / VOIP_TRUNKS / VOIP_CALLS /
// VOIP_TOP / VOIP_QUEUES / VOIP_QUALITY / VOIP_SITES / VOIP_PROBLEMS), then
// polls tcs.voip.data every 30s. The live-active-calls table refreshes on a
// tighter 5s cadence via tcs.voip.calls.data so we don't re-do the full
// rollup that often.
//
// Mirrors the fortigate-bridge pattern. While the data action is still a
// stub, every payload slot comes back as null and the JSX-side mock-data
// fallback takes over — the page never goes blank.

(function () {
    // Payload field → window global.
    const KEYS = [
        ["pbx",      "VOIP_PBX"],
        ["services", "VOIP_SERVICES"],
        ["trunks",   "VOIP_TRUNKS"],
        ["calls",    "VOIP_CALLS"],
        ["top",      "VOIP_TOP"],
        ["queues",   "VOIP_QUEUES"],
        ["quality",  "VOIP_QUALITY"],
        ["sites",    "VOIP_SITES"],
        ["problems", "VOIP_PROBLEMS"],
    ];

    function apply(payload, opts) {
        if (!payload || typeof payload !== "object") return;
        const only = (opts && opts.onlyKeys) ? new Set(opts.onlyKeys) : null;
        for (const [src, dst] of KEYS) {
            if (only && !only.has(src)) continue;
            const v = payload[src];
            // null/undefined means the data action couldn't fetch this slot
            // (XAPI failure, etc.) — keep whatever's currently on screen.
            // An explicit [] / {} means "fetched, currently empty" — apply
            // it so the page reflects reality.
            if (v === null || v === undefined) continue;
            window[dst] = v;
        }
        if (payload.sources)  window.VOIP_SOURCES = payload.sources;
        if (payload.loading !== undefined) window.VOIP_LOADING = !!payload.loading;
        window.VOIP_BANNER = payload.error
            ? { kind: "error",   msg: payload.error }
            : payload.warning
            ? { kind: "warning", msg: payload.warning }
            : null;
        window.dispatchEvent(new CustomEvent("tcs:voip-data", {
            detail: { ts: payload.ts || Date.now(), keys: opts?.onlyKeys || null }
        }));
    }

    // First paint: unpack SSR boot synchronously. With the data action still
    // a stub this is mostly nulls — the JSX file's `window.VOIP_X = ... || mock`
    // guards then seed the mock fallback.
    apply(window.VOIP_BOOT || {});

    const URL_DATA  = window.TCS_VOIP_DATA_URL       || "zabbix.php?action=tcs.voip.data";
    const URL_CALLS = window.TCS_VOIP_CALLS_DATA_URL || "zabbix.php?action=tcs.voip.calls.data";

    async function fetchData() {
        try {
            const resp = await fetch(URL_DATA, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" },
            });
            if (!resp.ok) throw new Error("HTTP " + resp.status);
            const j = await resp.json();
            apply(j || {});
            return j;
        } catch (e) {
            console.error("[tcs] voip fetch failed:", e, "url:", URL_DATA);
            return null;
        }
    }

    async function fetchCalls() {
        try {
            const resp = await fetch(URL_CALLS, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" },
            });
            if (!resp.ok) throw new Error("HTTP " + resp.status);
            const j = await resp.json();
            apply(j || {}, { onlyKeys: ["calls"] });
            return j;
        } catch (e) {
            console.error("[tcs] voip calls fetch failed:", e, "url:", URL_CALLS);
            return null;
        }
    }

    console.info("[tcs] fetching VoIP snapshot…");
    fetchData();

    // Rollup: 30 s. Active calls: 5 s. Skip when the tab is hidden.
    const REFRESH_MS       = 30_000;
    const CALLS_REFRESH_MS =  5_000;
    setInterval(() => { if (document.visibilityState === "visible") fetchData();  }, REFRESH_MS);
    setInterval(() => { if (document.visibilityState === "visible") fetchCalls(); }, CALLS_REFRESH_MS);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") { fetchData(); fetchCalls(); }
    });

    // Manual refresh hook for the Tweaks "Refresh now" button.
    window.tcsVoipRefresh = () => Promise.all([fetchData(), fetchCalls()]);
})();
