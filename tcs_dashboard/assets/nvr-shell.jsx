// Shared shell for surveillance pages — sidebar + topbar that link across pages

// Read shared nav URLs (defined in shell.jsx, but this file may also be loaded
// without shell.jsx if a page only uses NVRSidebar — fall back to defaults).
window.TCS_NAV = window.TCS_NAV || {
  zabbixDefault: "zabbix.php?action=dashboard.view",
  apDetail:      "zabbix.php?action=tcs.dashboard.view",
  surveillance:  "zabbix.php?action=tcs.surveillance.view",
  switches:      "zabbix.php?action=tcs.switches.view"
};

const NVRSidebar = ({ active }) => (
  <aside className="sidebar">
    <a className="back-to-zabbix" href={window.TCS_NAV.zabbixDefault} title="Back to default Zabbix UI">
      <Icon name="back" /> <span>Default Zabbix Dashboard</span>
    </a>

    <div className="brand">
      <div className="brand-mark">Z·M</div>
      <div>
        <div className="brand-name">Zabbix · TCS</div>
        <div className="brand-sub">+ Milestone XProtect</div>
      </div>
    </div>

    <div className="nav-section">
      <div className="nav-label">Monitoring</div>
      <a className="nav-item" href={window.TCS_NAV.zabbixDefault}><Icon name="map" /> Dashboards</a>
      <a className="nav-item" href={window.TCS_NAV.apDetail}><Icon name="ap" /> Hosts <span className="nav-count">2,418</span></a>
      <a className={"nav-item" + (active === "wireless" ? " active" : "")} href={window.TCS_NAV.apDetail}><Icon name="wifi" /> Wireless APs <span className="nav-count">1,184</span></a>
      <a className={"nav-item" + (active === "switches" ? " active" : "")} href={window.TCS_NAV.switches}><Icon name="ethernet" /> Switches <span className="nav-count">312</span></a>
      <a className="nav-item"><Icon name="alert" /> Problems <span className="nav-count warn">23</span></a>
      <a className="nav-item"><Icon name="events" /> Events</a>
    </div>

    <div className="nav-section">
      <div className="nav-label">Surveillance (Milestone)</div>
      <a className={"nav-item" + (active === "overview" ? " active" : "")} href={window.TCS_NAV.surveillance}><Icon name="map" /> NOC Overview</a>
      <a className={"nav-item" + (active === "cameras"  ? " active" : "")} href={window.TCS_NAV.surveillance + "&view=cameras"}><Icon name="ap" /> Cameras <span className="nav-count">1,147</span></a>
      <a className={"nav-item" + (active === "servers"  ? " active" : "")} href={window.TCS_NAV.surveillance + "&view=servers"}><Icon name="ethernet" /> Recording Servers <span className="nav-count">8</span></a>
      <a className="nav-item"><Icon name="shield" /> Evidence Lock <span className="nav-count">7</span></a>
      <a className="nav-item"><Icon name="alert" /> VMS Alarms <span className="nav-count warn">12</span></a>
    </div>

    <div className="nav-section">
      <div className="nav-label">Sites</div>
      <a className="nav-item">Bryant High School</a>
      <a className="nav-item muted">Central High School</a>
      <a className="nav-item muted">Northridge High School</a>
      <a className="nav-item muted">+ 23 more sites</a>
    </div>

    <div className="sidebar-footer">
      <div className="row"><span>Zabbix Server</span><span className="ok">● 7.0.4</span></div>
      <div className="row"><span>XProtect Mgmt</span><span className="ok">● 24.2 R2</span></div>
      <div className="row"><span>Recording Srvs</span><span className="ok">● 6 / 6</span></div>
    </div>
  </aside>
);

const NVRTopbar = ({ crumb }) => (
  <div className="topbar">
    <div className="icon-btn"><Icon name="back" /></div>
    <div className="crumb">
      {crumb.map((c, i) => (
        <React.Fragment key={i}>
          {i > 0 && <span className="sep">/</span>}
          <span className={i === crumb.length - 1 ? "seg" : ""}>{c}</span>
        </React.Fragment>
      ))}
    </div>
    <div className="spacer" />
    <div className="search">
      <Icon name="search" />
      <input placeholder="Find camera, server, MAC, IP…" readOnly />
      <kbd>⌘K</kbd>
    </div>
    <div className="icon-btn"><Icon name="refresh" /></div>
    <div className="icon-btn"><Icon name="more" /></div>
  </div>
);

window.NVRSidebar = NVRSidebar;
window.NVRTopbar = NVRTopbar;
