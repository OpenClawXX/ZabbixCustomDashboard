// Surveillance overview app entry

const { useState: useStateOV, useEffect: useEffectOV } = React;

const TWEAK_DEFAULTS_OV = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#d92929",
  "showSourceBadges": true,
  "wallSite": ""
}/*EDITMODE-END*/;

const NVRApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_OV);
  useEffectOV(() => {
    document.documentElement.style.setProperty("--zbx", t.accent);
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.accent, t.showSourceBadges]);
  // Publish the wall-site tweak onto window so FleetWidgets (rendered
  // without prop drilling) can read it.
  useEffectOV(() => { window.TCS_WALL_SITE = t.wallSite || ""; }, [t.wallSite]);

  const densityVar = t.density === "spacious" ? 1.15 : t.density === "dense" ? 0.85 : 1;

  return (
    <div className="app" data-density={t.density} style={{ fontSize: `${13 * densityVar}px` }}>
      <NVRSidebar active="nvr-overview" />
      <div className="main">
        <NVRTopbar crumb={["Surveillance", "Milestone XProtect", "NOC Overview"]} />
        <div className="page-header">
          <div className="icon-btn" style={{ marginTop: 4 }}><Icon name="back" /></div>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>Surveillance NOC</h1>
              <span className="ip">{window.MILESTONE.managementServer}</span>
              <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>{window.MILESTONE.product}</span>
            </div>
            <div className="host-meta">
              {(() => {
                const rsOnline = window.MILESTONE.recordingServersOnline;
                const rsTotal  = window.MILESTONE.recordingServers;
                const allUp    = rsTotal > 0 && rsOnline === rsTotal;
                const color    = allUp ? "var(--ok)" : (rsOnline > 0 ? "var(--warn)" : "var(--err)");
                const label    = rsTotal === 0
                  ? "No recording servers discovered"
                  : (allUp ? "All recording servers online" : `${rsOnline} / ${rsTotal} recording servers online`);
                return <span className="pill"><span className="dot" style={{ background: color }} /> {label}</span>;
              })()}
              <span className="pill"><span className="lbl">XProtect ver</span> <span className="v">{window.MILESTONE.version}</span></span>
              <span className="pill"><span className="lbl">Cameras</span> <span className="v">{window.MILESTONE.licenseDeviceUsed.toLocaleString()} / {window.MILESTONE.licenseDeviceTotal.toLocaleString()} licensed</span></span>
              <span className="pill"><span className="lbl">Storage</span> <span className="v">{window.MILESTONE.storageUsedTB.toFixed(1)} / {window.MILESTONE.storageTotalTB} TB</span></span>
              <span className="pill"><span className="lbl">Sites</span> <span>{window.SITES.length}</span></span>
            </div>
          </div>
          <div className="timerange">
            <Icon name="calendar" />
            <span className="range-val">Last 24h · live</span>
            <Icon name="chevron" />
          </div>
        </div>

        <div className="tabs">
          <div className="tab active">Overview</div>
          <div className="tab">Sites</div>
          <div className="tab">Cameras <span className="badge">{(window.CAMERAS || []).length.toLocaleString()}</span></div>
          <div className="tab">Recording Servers <span className="badge">{(window.SERVERS || []).length}</span></div>
          <div className="tab">Alarms {window.MILESTONE.activeAlarms > 0 && <span className="badge warn">{window.MILESTONE.activeAlarms}</span>}</div>
          <div className="tab">Storage</div>
          <div className="tab">Evidence Lock</div>
          <div className="tab">Reports</div>
        </div>

        <div className="body" data-screen-label="Surveillance Overview">
          <FleetWidgets />
        </div>
      </div>

      <TweaksPanel title="Tweaks">
        <TweakSection title="Layout">
          <TweakRadio label="Density" value={t.density} options={[{value:"spacious",label:"Spacious"},{value:"balanced",label:"Balanced"},{value:"dense",label:"Dense"}]} onChange={v => setTweak("density", v)} />
        </TweakSection>
        <TweakSection title="Visual">
          <TweakColor label="Primary accent" value={t.accent} options={["#d92929","#5b8cff","#34d399","#7c5cff","#f5b300"]} onChange={v => setTweak("accent", v)} />
          <TweakToggle label="Show data-source badges" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
        </TweakSection>
        <TweakSection title="Camera wall">
          <TweakSelect label="Site to show" value={t.wallSite} options={window.SITES.map(s => ({value: s.name, label: s.name}))} onChange={v => setTweak("wallSite", v)} />
        </TweakSection>
      </TweaksPanel>
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("root")).render(<NVRApp />);
