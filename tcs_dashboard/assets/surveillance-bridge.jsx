// surveillance-bridge.jsx
//
// Live-data bridge for the Surveillance NOC view. Reads
// window.SURVEILLANCE_BOOT (server-collected by ActionSurveillanceData)
// and *overlays* the matching fields onto the window globals that
// nvr-overview.jsx already consumes (MILESTONE, SITES, SERVERS, CAMERAS,
// VMS_ALARMS). Anything the backend doesn't supply yet (storage TB,
// Smart Client sessions, camera FPS / bitrate, …) is left alone so the
// mock baseline from nvr-data.jsx keeps the UI rendering while the
// templates grow.
//
// IMPORTANT: this file must be loaded AFTER nvr-data.jsx so the mock
// values are already on window.* and we can overlay onto them.

(function () {
    const isNum = (v) => typeof v === "number" && !Number.isNaN(v);
    const overlay = (base, fresh) => {
        // Only copy non-null / non-undefined keys from fresh onto base.
        // null in the boot payload means "backend doesn't know yet —
        // keep the mock value" rather than "force the field to null".
        if (!fresh || typeof fresh !== "object") return base;
        const out = Object.assign({}, base || {});
        for (const k of Object.keys(fresh)) {
            const v = fresh[k];
            if (v === null || v === undefined) continue;
            out[k] = v;
        }
        return out;
    };

    const applyBoot = (boot) => {
        if (!boot || typeof boot !== "object") return;

        // ── MILESTONE summary ─────────────────────────────────────────
        if (boot.milestone) {
            window.MILESTONE = overlay(window.MILESTONE, boot.milestone);
        }

        // ── SITES ─────────────────────────────────────────────────────
        if (Array.isArray(boot.sites) && boot.sites.length) {
            // Replace wholesale — site identity comes from the backend now.
            // Carry storageGB / storageCapGB defaults from mock so the
            // Recording Storage tile still has bars to render.
            const mock_by_name = {};
            for (const s of (window.SITES || [])) mock_by_name[s.name] = s;
            window.SITES = boot.sites.map(s => {
                const fallback = mock_by_name[s.name] || {};
                return {
                    name:         s.name,
                    cams:         isNum(s.cams)   ? s.cams   : (fallback.cams   || 0),
                    online:       isNum(s.online) ? s.online : (fallback.online || 0),
                    warn:         isNum(s.warn)   ? s.warn   : (fallback.warn   || 0),
                    err:          isNum(s.err)    ? s.err    : (fallback.err    || 0),
                    server:       s.server  || fallback.server  || "—",
                    storageGB:    isNum(s.storageGB)    ? s.storageGB    : (fallback.storageGB    || 0),
                    storageCapGB: isNum(s.storageCapGB) ? s.storageCapGB : (fallback.storageCapGB || 1)
                };
            });
        }

        // ── SERVERS (recording servers) ───────────────────────────────
        if (Array.isArray(boot.servers) && boot.servers.length) {
            // Same overlay approach — keep mock fields (cpu/mem/disk %)
            // until the RS template grows host-level OS items.
            const mock_by_id = {};
            for (const s of (window.SERVERS || [])) mock_by_id[s.id] = s;
            window.SERVERS = boot.servers.map(s => {
                const fallback = mock_by_id[s.id] || {};
                return Object.assign({}, fallback, {
                    id:        s.id,
                    site:      s.site || fallback.site,
                    role:      s.role || fallback.role || "Recording Server",
                    state:     s.state || (fallback.state || "ok"),
                    handshakeAge: s.handshakeAge,
                    // Numeric perf fields: keep mock until templated.
                    cpu:       isNum(s.cpu)  ? s.cpu  : (fallback.cpu  || 0),
                    mem:       isNum(s.mem)  ? s.mem  : (fallback.mem  || 0),
                    disk:      isNum(s.disk) ? s.disk : (fallback.disk || 0),
                    chans:     isNum(s.chans)     ? s.chans     : (fallback.chans     || 0),
                    recording: isNum(s.recording) ? s.recording : (fallback.recording || 0)
                });
            });
        }

        // ── CAMERAS ───────────────────────────────────────────────────
        if (Array.isArray(boot.cameras) && boot.cameras.length) {
            // The mock list is small (~15 rows) but real installs have
            // 2,500+. Replace wholesale, but limit the camera-wall
            // render to the active site (the JSX already filters).
            window.CAMERAS = boot.cameras.map(c => ({
                id:        c.id,
                site:      c.site,
                loc:       c.loc || c.name || c.id,
                model:     c.model || "—",
                res:       c.res       || "—",
                fps:       isNum(c.fps)     ? c.fps     : 0,
                bitrate:   isNum(c.bitrate) ? c.bitrate : 0,
                codec:     c.codec     || "—",
                recording: c.recording || "—",
                state:     mapCamState(c.state),
                ip:        c.ip   || "",
                mac:       c.mac  || "",
                poe:       isNum(c.poe) ? c.poe : 0,
                server:    c.server || "",
                motion12h: isNum(c.motion12h) ? c.motion12h : 0,
                hostid:    c.hostid || null,
                warnMsg:   c.warnMsg || null,
                errMsg:    c.errMsg  || null
            }));
        }

        // ── VMS_ALARMS ────────────────────────────────────────────────
        if (Array.isArray(boot.alarms)) {
            // Replace — boot.alarms is the authoritative open-problem
            // list from Zabbix. Empty array is a valid "all clear".
            window.VMS_ALARMS = boot.alarms.map(a => ({
                ts:   a.ts,
                sev:  a.sev,
                cam:  a.cam,
                msg:  a.msg,
                site: a.site || "",
                ack:  !!a.ack
            }));
        }
    };

    // Camera-state mapping: the JSX expects "ok" / "warn" / "err".
    // ActionSurveillanceData emits "ok" / "warn" / "err" / "disabled" /
    // "unknown" — fold disabled+unknown into "err" so the offline tint
    // shows for anything that isn't actively recording.
    const mapCamState = (s) => {
        if (s === "ok" || s === "warn" || s === "err") return s;
        if (s === "disabled" || s === "unknown") return "err";
        return "ok";
    };

    applyBoot(window.SURVEILLANCE_BOOT);

    const REFRESH_MS = 30_000;
    const url = window.TCS_SURVEILLANCE_DATA_URL;
    if (!url) return;

    const tick = async () => {
        try {
            const resp = await fetch(url, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!resp.ok) return;
            const fresh = await resp.json();
            applyBoot(fresh);
            window.dispatchEvent(new CustomEvent("tcs:surveillance-data", { detail: fresh }));
        } catch (e) {
            console.warn("[tcs] surveillance refresh failed:", e);
        }
    };

    window.tcsSurveillanceRefresh = tick;
    setInterval(tick, REFRESH_MS);
})();
