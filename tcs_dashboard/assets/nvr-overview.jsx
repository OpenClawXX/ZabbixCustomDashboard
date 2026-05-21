// Surveillance NOC Overview dashboard widgets

const FleetWidgets = () => {
  const M = window.MILESTONE;
  const H = window.FLEET_HISTORY;
  const totalCams = window.SITES.reduce((s, x) => s + x.cams, 0);
  const onlineCams = window.SITES.reduce((s, x) => s + x.online, 0);
  const warnCams = window.SITES.reduce((s, x) => s + x.warn, 0);
  const errCams = window.SITES.reduce((s, x) => s + x.err, 0);
  const storagePct = M.storageTotalTB > 0 ? (M.storageUsedTB / M.storageTotalTB) * 100 : 0;
  const licensePct = M.licenseDeviceTotal > 0 ? (M.licenseDeviceUsed / M.licenseDeviceTotal) * 100 : 0;

  // Tail-of-series helpers so spark-cells show their actual last value.
  const tail = (arr) => Array.isArray(arr) && arr.length ? arr[arr.length - 1] : 0;
  const sum  = (arr) => Array.isArray(arr) ? arr.reduce((a, b) => a + (Number(b) || 0), 0) : 0;

  // Alarm-severity breakdown from window.VMS_ALARMS instead of hardcoded.
  const alarmSev = (window.VMS_ALARMS || []).reduce((acc, a) => {
    acc[a.sev] = (acc[a.sev] || 0) + 1; return acc;
  }, {});
  const alarmSubline = [
    alarmSev.disaster ? `${alarmSev.disaster} disaster` : null,
    alarmSev.high     ? `${alarmSev.high} high`         : null,
    alarmSev.warning  ? `${alarmSev.warning} warning`   : null,
    alarmSev.info     ? `${alarmSev.info} info`         : null
  ].filter(Boolean).join(" · ") || "no active alarms";

  return (
    <div>
      {/* TOP KPI strip */}
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="stat-grid">
          <div className="stat-cell">
            <div className="lbl"><Icon name="ap" size={11}/> Cameras Online <SourceBadge src="ext" /></div>
            <div className="val">{onlineCams.toLocaleString()}<span className="u">/ {totalCams.toLocaleString()}</span></div>
            <div className="sub ok">{warnCams} warn · {errCams} offline</div>
          </div>
          <div className="stat-cell">
            <div className="lbl"><Icon name="ethernet" size={11}/> Recording Servers <SourceBadge src="zbx" /></div>
            <div className="val">{M.recordingServersOnline}<span className="u">/ {M.recordingServers}{M.failoverServers > 0 ? ` +${M.failoverServers} failover` : ""}</span></div>
            <div className={"sub " + (M.recordingServers === 0 ? "" : M.recordingServersOnline === M.recordingServers ? "ok" : "warn")}>
              {M.recordingServers === 0
                ? "no recording servers discovered"
                : M.recordingServersOnline === M.recordingServers
                  ? "all online"
                  : `${M.recordingServers - M.recordingServersOnline} offline`}
            </div>
          </div>
          <div className="stat-cell">
            <div className="lbl"><Icon name="alert" size={11}/> Active VMS Alarms <SourceBadge src="ext" /></div>
            <div className="val" style={{ color: M.activeAlarms > 0 ? "var(--err)" : "var(--ok)" }}>{M.activeAlarms}<span className="u" style={{color:"var(--muted)"}}>{M.alarmsAck > 0 ? `· ${M.alarmsAck} ack` : ""}</span></div>
            <div className={"sub " + (M.activeAlarms > 0 ? "warn" : "ok")}>{alarmSubline}</div>
          </div>
          <div className="stat-cell">
            <div className="lbl"><Icon name="user" size={11}/> Smart Client Sessions <SourceBadge src="ext" /></div>
            <div className="val">{M.smartClientSessions}<span className="u">+ {M.webClientSessions} web</span></div>
            <div className="sub">{M.evidenceLockUsed} / {M.evidenceLockSlots} evidence locks active</div>
          </div>
        </div>
      </div>

      {/* MIDDLE — Milestone summary | Storage | Live ingress chart */}
      <div className="row" style={{ gridTemplateColumns: "1.1fr 1fr 1.4fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h"><h3>Milestone XProtect</h3><SourceBadge src="ext"/><div className="h-spacer"/><span className="h-meta">{M.product}</span></div>
          <div className="card-b" style={{ display: "flex", flexDirection: "column", gap: 14 }}>
            <div>
              <div className="storage-bar">
                <div className="label-row"><span className="name">Device licenses</span><span className="pct">{M.licenseDeviceUsed} / {M.licenseDeviceTotal}</span></div>
                <div className="track"><div className={"fill " + (licensePct > 95 ? "warn" : "")} style={{ width: `${licensePct}%` }} /></div>
              </div>
            </div>
            <div className="kv tight" style={{ borderTop: "1px solid var(--line)" }}>
              <div className="k">Mgmt server</div><div className="v">{M.managementServer}</div><div className="b"><StatusDot state="ok"/></div>
              <div className="k">Recording srvs</div><div className="v">{M.recordingServersOnline} / {M.recordingServers} online</div><div className="b"><StatusDot state="ok"/></div>
              <div className="k">Failover srvs</div><div className="v">{M.failoverServers} standby</div><div className="b"><StatusDot state="ok"/></div>
              <div className="k">Mobile srv</div><div className="v">{M.mobileServers} · {M.smartClientSessions + M.webClientSessions} sessions</div><div className="b"><StatusDot state="ok"/></div>
              <div className="k">Retention</div><div className="v">{M.retentionDays} days standard</div><div className="b"><SourceBadge src="ext"/></div>
              <div className="k">Evidence lock</div><div className="v">{M.evidenceLockUsed} / {M.evidenceLockSlots} active</div><div className="b"><SourceBadge src="ext"/></div>
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-h"><h3>Recording Storage</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">{M.storageUsedTB.toFixed(1)} / {M.storageTotalTB} TB</span></div>
          <div className="card-b" style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            <div className="big-metric">
              <span className="v">{storagePct.toFixed(0)}<span style={{ fontSize: 18 }}>%</span></span>
              <span className="u">used · {(M.storageTotalTB - M.storageUsedTB).toFixed(1)} TB free</span>
            </div>
            {window.SITES.map(s => {
              const pct = (s.storageGB / s.storageCapGB) * 100;
              return (
                <div key={s.name} className="storage-bar compact">
                  <div className="label-row"><span className="name">{s.name}</span><span className="pct">{pct.toFixed(0)}%</span></div>
                  <div className="track"><div className={"fill " + (pct > 90 ? "err" : pct > 80 ? "warn" : "")} style={{ width: `${pct}%` }}/></div>
                </div>
              );
            })}
          </div>
        </div>

        <div className="card">
          <div className="card-h"><h3>Live Ingress · 24h</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">aggregated across {M.recordingServers} recording servers</span></div>
          <div className="bigchart">
            <FleetChart data={H.totalIngressGbps} label="Ingress" unit="Gbps" max={3} color="var(--zbx)" />
          </div>
          <div className="spark-strip" style={{ borderTop: "1px solid var(--line)" }}>
            <SparkCellM label="Storage Write" v={tail(H.storageWriteMBps)} unit="MB/s" data={H.storageWriteMBps} color="var(--info)" />
            <SparkCellM label="Avg CPU (rec srvs)" v={tail(H.recordingServersCpu)} unit="%" data={H.recordingServersCpu} color="var(--pf)" />
            <SparkCellM label="Cameras Online" v={onlineCams} unit="" data={H.camerasOnline} color="var(--ok)" />
            <SparkCellM label="Alarms / hr" v={sum(H.alarmsPerHour) > 0 ? (sum(H.alarmsPerHour) / 24).toFixed(1) : 0} unit="" data={H.alarmsPerHour} color="var(--warn)" />
          </div>
        </div>
      </div>

      {/* BOTTOM — Sites + Servers + Alarms */}
      <div className="row" style={{ gridTemplateColumns: "1fr 1fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h"><h3>Sites</h3><SourceBadge src="ext"/><div className="h-spacer"/><span className="h-meta">click to drill into a site</span></div>
          <div>
            {window.SITES.map(s => {
              const pct = (s.storageGB / s.storageCapGB) * 100;
              return (
                <div className="site-row" key={s.name}>
                  <div className="site-name"><StatusDot state={s.err ? "err" : s.warn ? "warn" : "ok"}/> {s.name}</div>
                  <div className="cam-counts"><span className="ok">{s.online}</span> / {s.cams}</div>
                  <div className="cam-counts">
                    {s.warn > 0 && <span className="warn">{s.warn}w </span>}
                    {s.err > 0 && <span className="err">{s.err}e</span>}
                    {s.warn === 0 && s.err === 0 && <span className="muted">no issues</span>}
                  </div>
                  <div className="storage-bar compact">
                    <div className="label-row"><span className="name muted" style={{fontFamily:"var(--mono)", fontSize:10}}>{s.server}</span><span className="pct">{pct.toFixed(0)}%</span></div>
                    <div className="track"><div className={"fill " + (pct > 90 ? "err" : pct > 80 ? "warn" : "")} style={{ width: `${pct}%` }}/></div>
                  </div>
                  <div style={{ textAlign: "right", color: "var(--muted)" }}><Icon name="chevron" size={12}/></div>
                </div>
              );
            })}
          </div>
        </div>

        <div className="card">
          <div className="card-h"><h3>Recording Servers</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">zabbix-agent2 + Milestone WMI plugin</span></div>
          <div className="stat-grid" style={{ gridTemplateColumns: "repeat(2, 1fr)" }}>
            {window.SERVERS.filter(s => s.role !== "Failover").slice(0, 6).map(s => (
              <ServerMini key={s.id} s={s} />
            ))}
          </div>
        </div>
      </div>

      {/* Alarm feed full width */}
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h">
          <h3>Active Alarm Feed</h3>
          <SourceBadge src="ext"/>
          <div className="h-spacer"/>
          <span className="h-meta">XProtect alarms + Zabbix triggers · last 24h</span>
          <span className="h-link">Open full alarm log <Icon name="external" size={11}/></span>
        </div>
        <div>
          {window.VMS_ALARMS.map((a, i) => (
            <div key={i} className={"alarm-row " + (a.ack ? "ack" : "")}>
              <div className="ts">{a.ts}</div>
              <Sev level={a.sev}/>
              <div><span className={`sev-dot ${a.sev}`}/></div>
              <div className="obj" onClick={() => { if (a.cam) location.href = `Camera Detail.html?id=${a.cam}`; if (a.srv) location.href = `Server Detail.html?id=${a.srv}`; }}>{a.cam || a.srv}</div>
              <div className="msg">{a.msg}</div>
              <div className="site">{a.site} {a.ack && <span className="muted">· ack</span>}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Camera wall */}
      {(() => {
        // Read the tweak panel's selection from the parent (NVRApp publishes
        // it onto window so widget code doesn't need the prop chain).
        // Falls back to the first discovered site.
        const wallSite = (window.TCS_WALL_SITE && window.SITES.some(s => s.name === window.TCS_WALL_SITE))
          ? window.TCS_WALL_SITE
          : (window.SITES[0] && window.SITES[0].name) || "—";
        const camsAtSite = window.CAMERAS.filter(c => c.site === wallSite);
        return (
          <div className="card">
            <div className="card-h">
              <h3>Camera Wall · {wallSite}</h3>
              <SourceBadge src="ext"/>
              <div className="h-spacer"/>
              <span className="h-meta">{camsAtSite.length.toLocaleString()} cameras at this site</span>
              <span className="h-link">Open in Smart Client <Icon name="external" size={11}/></span>
            </div>
            <div className="cam-grid">
              {camsAtSite.slice(0, 24).map(c => <CamThumb key={c.id} c={c}/>)}
            </div>
          </div>
        );
      })()}
    </div>
  );
};

// ───── Mini chart ─────
const FleetChart = ({ data, label, unit, color, max }) => {
  const w = 800, h = 160;
  const lo = 0, hi = max || Math.max(...data) * 1.1;
  const stepX = w / (data.length - 1);
  const pts = data.map((v, i) => [i * stepX, h - 20 - ((v - lo) / (hi - lo)) * (h - 40)]);
  const path = pts.map((p, i) => (i === 0 ? `M${p[0]},${p[1]}` : `L${p[0]},${p[1]}`)).join(" ");
  const fill = `${path} L${w},${h - 20} L0,${h - 20} Z`;
  return (
    <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
      {[0.25, 0.5, 0.75].map(p => (
        <line key={p} x1="0" x2={w} y1={20 + p * (h - 40)} y2={20 + p * (h - 40)} stroke="var(--line)" strokeDasharray="3 3" strokeWidth="0.5"/>
      ))}
      <path d={fill} fill={color} opacity="0.12"/>
      <path d={path} fill="none" stroke={color} strokeWidth="1.5" strokeLinejoin="round"/>
      <text x="6" y="14" fontSize="10" fontFamily="var(--mono)" fill="var(--muted)">{label} · peak {Math.max(...data)}{unit}</text>
      <text x={w-6} y="14" fontSize="10" fontFamily="var(--mono)" fill="var(--muted)" textAnchor="end">{data[data.length-1]}{unit}</text>
    </svg>
  );
};

const SparkCellM = ({ label, v, unit, data, color }) => (
  <div className="spark-cell">
    <div className="lbl">{label}</div>
    <div className="val">{v}<span className="u">{unit}</span></div>
    <Sparkline data={data} color={color} width={240} height={26} />
  </div>
);

const ServerMini = ({ s }) => (
  <a className="server-tile" href={`Server Detail.html?id=${s.id}`} style={{ textDecoration: "none", color: "inherit" }}>
    <div className="head">
      <StatusDot state={s.disk > 90 || s.cpu > 80 ? "warn" : "ok"} />
      <div className="id">{s.id}</div>
      <span className="role">{s.role.replace(" Server", "")}</span>
    </div>
    <div className="stats">
      <div>CPU<div className="v">{s.cpu}%</div></div>
      <div>Mem<div className="v">{s.mem}%</div></div>
      <div>Disk<div className="v" style={s.disk > 90 ? {color:"var(--warn)"} : {}}>{s.disk}%</div></div>
    </div>
    <div className="meta">
      <span>{s.site}</span>
      <span>{s.recording}/{s.chans} ch</span>
    </div>
  </a>
);

const CamThumb = ({ c }) => {
  const now = new Date();
  const ts = now.toISOString().replace("T", " ").substr(0, 19);
  return (
    <a className={`cam-tile ${c.state}`} href={`Camera Detail.html?id=${c.id}`} style={{textDecoration:"none"}}>
      <div className="frame"/>
      <div className="scan"/>
      <div className="id">{c.id}</div>
      <div className="ts">{ts}</div>
      <div className="meta">
        <div className="l">
          <span className="name">{c.loc}</span>
          <span style={{fontSize:9, color: "rgba(255,255,255,0.55)"}}>{c.res} · {c.fps}fps</span>
        </div>
        {c.state !== "err" && <div className="rec"><span className="red"/>REC</div>}
      </div>
    </a>
  );
};

window.FleetWidgets = FleetWidgets;
