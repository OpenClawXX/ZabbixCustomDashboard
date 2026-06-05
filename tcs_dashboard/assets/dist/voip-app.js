// VoIP / 3CX monitoring dashboard
// Single-page Zabbix-style view of the TCS 3CX phone system.

const {
  useState: useStateVP,
  useEffect: useEffectVP,
  useMemo: useMemoVP
} = React;

// ═══════════════════════════════════════════════════════════════
// DATA — fictional, modeled on a typical school-district 3CX v20 deploy
// ═══════════════════════════════════════════════════════════════

// 24h concurrent-calls history (15-min buckets, 96 samples)
const _concur24h = (() => {
  // school-day shape: ramp 7am, peak ~10-11 and 1-2pm, drop after 4pm
  const out = [];
  for (let i = 0; i < 96; i++) {
    const hr = i / 4;
    let base;
    if (hr < 6) base = 2 + Math.sin(hr) * 1;else if (hr < 7.5) base = 4 + (hr - 6) * 6;else if (hr < 11) base = 22 + (hr - 7.5) * 5 + Math.sin(hr * 2) * 4;else if (hr < 12) base = 38 - (hr - 11) * 6;else if (hr < 14) base = 32 + Math.sin((hr - 12) * 3) * 8;else if (hr < 16) base = 30 - (hr - 14) * 6;else if (hr < 18) base = 16 - (hr - 16) * 4;else base = 6 + Math.sin(hr) * 2;
    out.push(Math.max(0, Math.round(base + i % 7 * 0.4)));
  }
  return out;
})();
const _inbound24h = _concur24h.map((v, i) => Math.round(v * (0.55 + Math.sin(i * 0.3) * 0.05)));
const _outbound24h = _concur24h.map((v, i) => v - _inbound24h[i]);
window.VOIP_PBX = {
  fqdn: "pbx.tcs.local",
  ip: "10.10.5.20",
  version: "20.0 U4 (Build 4.2.0.1)",
  edition: "Enterprise · 256 SC",
  uptime: "47d 14h 22m",
  region: "Tuscaloosa · Main Data Center (Arc-DC)",
  activeNow: _concur24h[_concur24h.length - 1],
  capacity: 256,
  peakToday: Math.max(..._concur24h.slice(28)),
  callsToday: 4187,
  callsInbound: 2392,
  callsOutbound: 1411,
  callsInternal: 384,
  registeredExt: 198,
  totalExt: 214,
  avgMos: 4.32,
  asr: 96.4,
  // answer-seizure ratio
  acd: "3m 41s",
  // average call duration
  history: {
    concur: _concur24h,
    inbound: _inbound24h,
    outbound: _outbound24h
  }
};

// Services (3CX components + supporting infra)
window.VOIP_SERVICES = [{
  name: "3CX Phone System",
  status: "running",
  uptime: "47d",
  sub: "core call manager · v20 U4",
  load: "42%"
}, {
  name: "3CX Media Server",
  status: "running",
  uptime: "47d",
  sub: "G.711, G.722, OPUS · 28 active",
  load: "31%"
}, {
  name: "3CX Web Server (Nginx)",
  status: "running",
  uptime: "47d",
  sub: "TLS 1.3 · sbc.tcs.org",
  load: "9%"
}, {
  name: "PostgreSQL 14",
  status: "running",
  uptime: "47d",
  sub: "CDR · 2.4 GB · 14k rows/day",
  load: "12%"
}, {
  name: "3CX SBC · arc-sbc-01",
  status: "running",
  uptime: "12d",
  sub: "Arcadia DMZ · 5060/UDP, 5061/TLS",
  load: "ok"
}, {
  name: "3CX SBC · bhs-sbc-01",
  status: "running",
  uptime: "9d 06h",
  sub: "Bryant DMZ · TLS",
  load: "ok"
}, {
  name: "3CX SBC · chs-sbc-01",
  status: "degraded",
  uptime: "2h 14m",
  sub: "Central DMZ · jitter > 25ms upstream",
  load: "warn"
}, {
  name: "RTP relay (proxy)",
  status: "running",
  uptime: "47d",
  sub: "10000-20000/UDP",
  load: "ok"
}, {
  name: "Voicemail / FAX2Email",
  status: "running",
  uptime: "47d",
  sub: "32 mailboxes · 84 new today",
  load: "ok"
}, {
  name: "Backup agent",
  status: "running",
  uptime: "47d",
  sub: "Daily 02:00 → s3://tcs-pbx-bk",
  load: "ok"
}];

// SIP Trunks
window.VOIP_TRUNKS = [{
  name: "Bandwidth.com SIP — Main DID Block",
  provider: "bandwidth.com",
  host: "siptrunk.bandwidth.com:5060",
  status: "reg",
  chTotal: 64,
  chIn: 14,
  chOut: 9,
  asr: 97.2,
  mos: 4.41,
  errors: 0,
  did: "+1 205-759-3500"
}, {
  name: "Bandwidth.com SIP — E911",
  provider: "bandwidth.com",
  host: "e911.bandwidth.com:5060",
  status: "reg",
  chTotal: 8,
  chIn: 0,
  chOut: 0,
  asr: 100,
  mos: 4.50,
  errors: 0,
  did: "E911 only"
}, {
  name: "AT&T BVoIP — Failover PRI",
  provider: "att.com",
  host: "10.10.5.40 (Audiocodes)",
  status: "reg",
  chTotal: 23,
  chIn: 2,
  chOut: 1,
  asr: 95.8,
  mos: 4.28,
  errors: 0,
  did: "+1 205-507-2200"
}, {
  name: "Twilio Elastic — Outbound (campus calls)",
  provider: "twilio.com",
  host: "tcs.pstn.twilio.com:5061",
  status: "reg",
  chTotal: 32,
  chIn: 0,
  chOut: 7,
  asr: 94.1,
  mos: 4.36,
  errors: 0,
  did: "outbound"
}, {
  name: "Flowroute — Conf Bridges",
  provider: "flowroute.com",
  host: "us-west-or.sip.flowroute.com",
  status: "dgr",
  chTotal: 16,
  chIn: 3,
  chOut: 2,
  asr: 91.4,
  mos: 4.04,
  errors: 12,
  did: "conf · 5500-5599"
}, {
  name: "Internal — TCTA Legacy Avaya bridge",
  provider: "internal",
  host: "10.60.5.5:5060",
  status: "unreg",
  chTotal: 8,
  chIn: 0,
  chOut: 0,
  asr: 0,
  mos: 0,
  errors: 47,
  did: "x6000-6099"
}];

// Live active calls (a snapshot — these are happening right now)
window.VOIP_CALLS = [{
  dir: "in",
  from: "+1 205-759-3500",
  fromSub: "Bandwidth · DID 3500",
  to: "1042 — Auto-attendant",
  toSub: "→ x1042 Reception",
  dur: "0:14",
  codec: "OPUS",
  trunk: "BW-Main",
  mos: 4.42,
  q: "good"
}, {
  dir: "in",
  from: "+1 334-887-1102",
  fromSub: "Parent · Montgomery, AL",
  to: "1108 — J. Hartwell",
  toSub: "Counseling · BHS",
  dur: "2:41",
  codec: "G.722",
  trunk: "BW-Main",
  mos: 4.38,
  q: "good"
}, {
  dir: "out",
  from: "1213 — A. Whitley",
  fromSub: "Principal · CHS",
  to: "+1 205-561-8893",
  toSub: "Tuscaloosa City Hall",
  dur: "11:08",
  codec: "G.711u",
  trunk: "Twilio",
  mos: 4.21,
  q: "good"
}, {
  dir: "in",
  from: "+1 205-242-9001",
  fromSub: "Vendor · SchoolDude",
  to: "1300 — Facilities Queue",
  toSub: "Hold 0:22 · 1 waiting",
  dur: "0:54",
  codec: "G.711u",
  trunk: "BW-Main",
  mos: 4.34,
  q: "good"
}, {
  dir: "int",
  from: "1207 — Nurse (ARC)",
  fromSub: "Arcadia ES",
  to: "1402 — Admin Office",
  toSub: "→ x1402",
  dur: "0:38",
  codec: "G.722",
  trunk: "internal",
  mos: 4.48,
  q: "good"
}, {
  dir: "in",
  from: "+1 205-462-2210",
  fromSub: "Unknown",
  to: "1500 — Conf Bridge",
  toSub: "Flowroute · 5 in bridge",
  dur: "23:14",
  codec: "OPUS",
  trunk: "Flowroute",
  mos: 3.81,
  q: "fair"
}, {
  dir: "out",
  from: "1108 — J. Hartwell",
  fromSub: "Counseling · BHS",
  to: "+1 334-844-1009",
  toSub: "AL DHR · Tuscaloosa",
  dur: "0:09",
  codec: "G.711u",
  trunk: "Twilio",
  mos: 4.40,
  q: "good"
}, {
  dir: "q",
  from: "+1 205-345-7711",
  fromSub: "Parent · Tuscaloosa",
  to: "1300 — Facilities Queue",
  toSub: "Position 1 · waiting 0:08",
  dur: "0:08",
  codec: "—",
  trunk: "BW-Main",
  mos: 0,
  q: "good"
}, {
  dir: "in",
  from: "+1 256-555-2940",
  fromSub: "Vendor · Apple Edu",
  to: "1019 — IT Help Desk",
  toSub: "→ x1019",
  dur: "4:22",
  codec: "G.722",
  trunk: "BW-Main",
  mos: 3.42,
  q: "poor"
}, {
  dir: "int",
  from: "1018 — D. Brewer (IT)",
  fromSub: "Tech Services · NRH",
  to: "1002 — Network Ops",
  toSub: "→ x1002",
  dur: "1:55",
  codec: "G.722",
  trunk: "internal",
  mos: 4.50,
  q: "good"
}];

// Top extensions by call count today
window.VOIP_TOP = [{
  ext: "1042",
  name: "Reception · Arc Admin",
  site: "ARC",
  calls: 187,
  mins: 412,
  role: "front-desk"
}, {
  ext: "1300",
  name: "Facilities Help Queue",
  site: "DIST",
  calls: 142,
  mins: 287,
  role: "queue"
}, {
  ext: "1019",
  name: "IT Help Desk Queue",
  site: "DIST",
  calls: 118,
  mins: 614,
  role: "queue"
}, {
  ext: "1108",
  name: "J. Hartwell · Counseling",
  site: "BHS",
  calls: 84,
  mins: 198,
  role: "counsel"
}, {
  ext: "1207",
  name: "Nurse · Arcadia ES",
  site: "ARC",
  calls: 61,
  mins: 92,
  role: "health"
}, {
  ext: "1402",
  name: "Admin Office · CHS",
  site: "CHS",
  calls: 58,
  mins: 132,
  role: "admin"
}];

// Queues (snapshot)
window.VOIP_QUEUES = [{
  name: "IT Help Desk",
  ext: "1019",
  agents: 4,
  agentsOn: 3,
  waiting: 2,
  sla: 88,
  abandon: 4,
  ans: 116,
  slaSec: 30
}, {
  name: "Facilities",
  ext: "1300",
  agents: 3,
  agentsOn: 3,
  waiting: 1,
  sla: 94,
  abandon: 2,
  ans: 139,
  slaSec: 30
}, {
  name: "Transportation",
  ext: "1320",
  agents: 5,
  agentsOn: 4,
  waiting: 0,
  sla: 97,
  abandon: 1,
  ans: 71,
  slaSec: 30
}, {
  name: "Attendance · BHS",
  ext: "1110",
  agents: 2,
  agentsOn: 2,
  waiting: 0,
  sla: 92,
  abandon: 3,
  ans: 54,
  slaSec: 45
}];

// Call quality 24h history (sample every 30min, 48 samples)
window.VOIP_QUALITY = {
  mos: Array.from({
    length: 48
  }, (_, i) => 4.3 + Math.sin(i * 0.4) * 0.08 + (i === 24 ? -0.4 : 0) + (i === 25 ? -0.3 : 0)),
  jitter: Array.from({
    length: 48
  }, (_, i) => 6 + Math.abs(Math.sin(i * 0.3)) * 4 + (i === 24 ? 22 : 0) + (i === 25 ? 14 : 0)),
  loss: Array.from({
    length: 48
  }, (_, i) => 0.05 + Math.abs(Math.sin(i * 0.5)) * 0.3 + (i === 24 ? 1.4 : 0) + (i === 25 ? 0.8 : 0)),
  rtt: Array.from({
    length: 48
  }, (_, i) => 22 + Math.sin(i * 0.25) * 4 + (i === 24 ? 38 : 0))
};

// Extensions — fictional list, grouped by site
function _genExt(site, base, count, opts = {}) {
  let x = base * 7919;
  const rnd = () => {
    x = (x * 9301 + 49297) % 233280;
    return x / 233280;
  };
  const firstNames = ["A. Bates", "J. Hartwell", "R. Tate", "P. Cobb", "M. Lewis", "S. Knox", "D. Brewer", "K. Pierce", "L. Hayes", "T. Ortiz", "N. Frost", "E. Marsh", "C. Boyd", "V. Yu", "O. Park", "H. Reeves", "I. Garcia", "B. Stokes", "W. Lin", "F. Akin", "G. Dewey", "Q. Mead", "Z. Bell", "Y. Cruz", "X. Vega", "U. Owen", "R. Doss", "P. Wade", "M. Joffe", "J. Cope", "H. Voss"];
  const out = [];
  for (let i = 0; i < count; i++) {
    const r = rnd();
    let state;
    if (i === (opts.alertAt || -99)) state = "alert";else if (r < 0.08) state = "unreg";else if (r < 0.16) state = "call";else if (r < 0.20) state = "dnd";else state = "reg";
    const n = firstNames[Math.floor(rnd() * firstNames.length)];
    out.push({
      ext: String(base + i),
      name: n,
      site,
      state
    });
  }
  return out;
}
window.VOIP_SITES = [{
  id: "ARC",
  name: "Arcadia Elementary",
  expanded: true,
  ext: _genExt("ARC", 1200, 36, {
    alertAt: 11
  })
}, {
  id: "BHS",
  name: "Bryant High School",
  expanded: true,
  ext: _genExt("BHS", 1300, 56)
}, {
  id: "CHS",
  name: "Central High School",
  expanded: true,
  ext: _genExt("CHS", 1400, 48, {
    alertAt: 22
  })
}, {
  id: "NRH",
  name: "Northridge High School",
  expanded: true,
  ext: _genExt("NRH", 1500, 32)
}, {
  id: "TCTA",
  name: "Tuscaloosa Career & Tech",
  expanded: true,
  ext: _genExt("TCTA", 1600, 18)
}, {
  id: "DIST",
  name: "District Office & Queues",
  expanded: true,
  ext: _genExt("DIST", 1000, 24)
}];

// Problems
window.VOIP_PROBLEMS = [{
  ts: "09:14:22",
  sev: "warning",
  host: "chs-sbc-01",
  trig: "Upstream jitter > 25ms (Flowroute trunk)",
  age: "00:24",
  ack: false
}, {
  ts: "08:42:08",
  sev: "high",
  host: "TCTA-Avaya",
  trig: "Internal SIP trunk x6000-6099 NOT REGISTERED",
  age: "00:56",
  ack: false
}, {
  ts: "08:11:55",
  sev: "warning",
  host: "Flowroute",
  trig: "12 SIP 503 errors in 5m on conf-bridge trunk",
  age: "01:27",
  ack: false
}, {
  ts: "07:33:14",
  sev: "info",
  host: "pbx.tcs.local",
  trig: "Daily CDR archive rotated · 14,217 rows",
  age: "02:05",
  ack: true
}, {
  ts: "Yesterday",
  sev: "warning",
  host: "ext 1019",
  trig: "Polycom VVX-450 firmware out of date (6.4.4)",
  age: "16:30",
  ack: true
}];

// ═══════════════════════════════════════════════════════════════
// WIDGETS
// ═══════════════════════════════════════════════════════════════

// ── Concurrent-calls 24h area chart ──
const ConcurrencyChart = () => {
  const data = window.VOIP_PBX.history;
  const W = 720,
    H = 168,
    PAD_L = 30,
    PAD_R = 14,
    PAD_T = 14,
    PAD_B = 22;
  const innerW = W - PAD_L - PAD_R;
  const innerH = H - PAD_T - PAD_B;
  const max = 80;
  const n = data.concur.length;
  const x = i => PAD_L + i / (n - 1) * innerW;
  const y = v => PAD_T + innerH - Math.min(1, v / max) * innerH;
  const areaPath = arr => {
    const pts = arr.map((v, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(v)}`).join(" ");
    return `${pts} L${x(n - 1)},${PAD_T + innerH} L${x(0)},${PAD_T + innerH} Z`;
  };
  const linePath = arr => arr.map((v, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(v)}`).join(" ");
  const ticks = [0, 20, 40, 60, 80];
  const hours = [0, 6, 9, 12, 15, 18, 23];
  return /*#__PURE__*/React.createElement("div", {
    className: "card concur-card"
  }, /*#__PURE__*/React.createElement("div", {
    className: "card-h"
  }, /*#__PURE__*/React.createElement("h3", null, "Concurrent Calls \xB7 24h"), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "3cx"
  }), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "zbx"
  }), /*#__PURE__*/React.createElement("div", {
    className: "h-spacer"
  }), /*#__PURE__*/React.createElement("span", {
    className: "h-meta"
  }, "15-min buckets \xB7 live")), /*#__PURE__*/React.createElement("div", {
    className: "concur-meta"
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    className: "cm-lbl"
  }, "Active right now"), /*#__PURE__*/React.createElement("div", {
    className: "cm-now"
  }, window.VOIP_PBX.activeNow, /*#__PURE__*/React.createElement("span", {
    className: "u"
  }, "/ ", window.VOIP_PBX.capacity, " SC"))), /*#__PURE__*/React.createElement("div", {
    className: "cm-kv"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "Peak today"), /*#__PURE__*/React.createElement("span", {
    className: "v warn"
  }, window.VOIP_PBX.peakToday, " @ 10:45")), /*#__PURE__*/React.createElement("div", {
    className: "cm-kv"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "Calls today"), /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, window.VOIP_PBX.callsToday.toLocaleString())), /*#__PURE__*/React.createElement("div", {
    className: "cm-kv"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "ACD"), /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, window.VOIP_PBX.acd)), /*#__PURE__*/React.createElement("div", {
    className: "cm-kv"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "ASR"), /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, window.VOIP_PBX.asr, "%")), /*#__PURE__*/React.createElement("div", {
    className: "cm-spacer"
  }), /*#__PURE__*/React.createElement("div", {
    className: "cm-cap"
  }, /*#__PURE__*/React.createElement("b", null, window.VOIP_PBX.callsInbound.toLocaleString()), " in \xB7 ", /*#__PURE__*/React.createElement("b", null, window.VOIP_PBX.callsOutbound.toLocaleString()), " out \xB7 ", /*#__PURE__*/React.createElement("b", null, window.VOIP_PBX.callsInternal), " internal")), /*#__PURE__*/React.createElement("div", {
    className: "concur-chart-wrap"
  }, /*#__PURE__*/React.createElement("svg", {
    className: "concur-svg",
    viewBox: `0 0 ${W} ${H}`,
    preserveAspectRatio: "none"
  }, ticks.map(t => /*#__PURE__*/React.createElement("g", {
    key: t
  }, /*#__PURE__*/React.createElement("line", {
    className: "grid-line",
    x1: PAD_L,
    x2: W - PAD_R,
    y1: y(t),
    y2: y(t)
  }), /*#__PURE__*/React.createElement("text", {
    className: "axis-lbl",
    x: PAD_L - 6,
    y: y(t) + 3,
    textAnchor: "end"
  }, t))), /*#__PURE__*/React.createElement("line", {
    className: "peak-line",
    x1: PAD_L,
    x2: W - PAD_R,
    y1: y(window.VOIP_PBX.peakToday),
    y2: y(window.VOIP_PBX.peakToday)
  }), /*#__PURE__*/React.createElement("path", {
    className: "area-fill",
    fill: "var(--info)",
    d: areaPath(data.outbound)
  }), /*#__PURE__*/React.createElement("path", {
    className: "area-fill",
    fill: "var(--cx)",
    d: areaPath(data.concur)
  }), /*#__PURE__*/React.createElement("path", {
    className: "area-line",
    stroke: "var(--cx)",
    d: linePath(data.concur)
  }), /*#__PURE__*/React.createElement("path", {
    className: "area-line",
    stroke: "var(--info)",
    strokeOpacity: "0.7",
    d: linePath(data.outbound),
    strokeDasharray: "3 2"
  }), hours.map(h => /*#__PURE__*/React.createElement("text", {
    key: h,
    className: "axis-lbl",
    x: PAD_L + h / 23 * innerW,
    y: H - 6,
    textAnchor: "middle"
  }, String(h).padStart(2, "0"), ":00")))), /*#__PURE__*/React.createElement("div", {
    className: "concur-legend"
  }, /*#__PURE__*/React.createElement("span", {
    className: "item"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw",
    style: {
      background: "var(--cx)"
    }
  }), " Total concurrent"), /*#__PURE__*/React.createElement("span", {
    className: "item"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw",
    style: {
      background: "var(--info)",
      opacity: 0.7
    }
  }), " Outbound only"), /*#__PURE__*/React.createElement("span", {
    className: "item"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw",
    style: {
      background: "var(--warn)",
      height: 2,
      marginBottom: 3
    }
  }), " Today's peak (", window.VOIP_PBX.peakToday, ")")));
};

// ── Services / health panel ──
const ServicesPanel = () => /*#__PURE__*/React.createElement("div", {
  className: "card"
}, /*#__PURE__*/React.createElement("div", {
  className: "card-h"
}, /*#__PURE__*/React.createElement("h3", null, "System Services"), /*#__PURE__*/React.createElement(SourceBadge, {
  src: "zbx"
}), /*#__PURE__*/React.createElement(SourceBadge, {
  src: "3cx"
}), /*#__PURE__*/React.createElement("div", {
  className: "h-spacer"
}), /*#__PURE__*/React.createElement("span", {
  className: "h-meta"
}, window.VOIP_PBX.uptime, " up")), /*#__PURE__*/React.createElement("div", {
  className: "svc-list"
}, window.VOIP_SERVICES.map((s, i) => {
  const cls = s.status === "running" ? "" : s.status === "degraded" ? "warn" : "err";
  const lbl = s.status === "running" ? "OK" : s.status === "degraded" ? "DEGR" : "DOWN";
  return /*#__PURE__*/React.createElement("div", {
    key: i,
    className: "svc-row"
  }, /*#__PURE__*/React.createElement("span", {
    className: "svc-led " + cls
  }), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    className: "svc-name"
  }, s.name), /*#__PURE__*/React.createElement("div", {
    className: "svc-sub"
  }, s.sub)), /*#__PURE__*/React.createElement("div", {
    className: "svc-load"
  }, typeof s.load === "string" && s.load.endsWith("%") ? s.load : ""), /*#__PURE__*/React.createElement("span", {
    className: "svc-pill " + cls
  }, lbl));
})), /*#__PURE__*/React.createElement("div", {
  className: "svc-foot"
}, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
  className: "k"
}, "PBX FQDN"), /*#__PURE__*/React.createElement("div", {
  className: "v"
}, window.VOIP_PBX.fqdn)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
  className: "k"
}, "License"), /*#__PURE__*/React.createElement("div", {
  className: "v"
}, window.VOIP_PBX.edition)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
  className: "k"
}, "Version"), /*#__PURE__*/React.createElement("div", {
  className: "v"
}, window.VOIP_PBX.version)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
  className: "k"
}, "Region"), /*#__PURE__*/React.createElement("div", {
  className: "v",
  style: {
    whiteSpace: "normal",
    lineHeight: 1.3
  }
}, window.VOIP_PBX.region))));

// ── KPI strip across top ──
const VoipKpis = () => {
  const p = window.VOIP_PBX;
  return /*#__PURE__*/React.createElement("div", {
    className: "card",
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "swstat-strip"
  }, /*#__PURE__*/React.createElement("div", {
    className: "swstat-cell"
  }, /*#__PURE__*/React.createElement("div", {
    className: "lbl"
  }, "Active Calls"), /*#__PURE__*/React.createElement("div", {
    className: "val",
    style: {
      color: "var(--cx)"
    }
  }, p.activeNow, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: "var(--muted)",
      fontWeight: 500
    }
  }, " / ", p.capacity)), /*#__PURE__*/React.createElement(Sparkline, {
    data: p.history.concur.slice(-24),
    color: "var(--cx)",
    width: 120,
    height: 20
  })), /*#__PURE__*/React.createElement("div", {
    className: "swstat-cell"
  }, /*#__PURE__*/React.createElement("div", {
    className: "lbl"
  }, "Calls Today"), /*#__PURE__*/React.createElement("div", {
    className: "val"
  }, p.callsToday.toLocaleString()), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 10,
      color: "var(--muted)",
      fontFamily: "var(--mono)"
    }
  }, p.callsInbound.toLocaleString(), " in \xB7 ", p.callsOutbound.toLocaleString(), " out")), /*#__PURE__*/React.createElement("div", {
    className: "swstat-cell"
  }, /*#__PURE__*/React.createElement("div", {
    className: "lbl"
  }, "Registered Phones"), /*#__PURE__*/React.createElement("div", {
    className: "val ok"
  }, p.registeredExt, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: "var(--muted)",
      fontWeight: 500
    }
  }, " / ", p.totalExt)), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 10,
      color: "var(--warn)",
      fontFamily: "var(--mono)"
    }
  }, "\u25CF ", p.totalExt - p.registeredExt, " unreg")), /*#__PURE__*/React.createElement("div", {
    className: "swstat-cell"
  }, /*#__PURE__*/React.createElement("div", {
    className: "lbl"
  }, "Avg MOS \xB7 1h"), /*#__PURE__*/React.createElement("div", {
    className: "val ok"
  }, p.avgMos.toFixed(2)), /*#__PURE__*/React.createElement(Sparkline, {
    data: window.VOIP_QUALITY.mos.slice(-24),
    color: "var(--ok)",
    width: 120,
    height: 20
  })), /*#__PURE__*/React.createElement("div", {
    className: "swstat-cell"
  }, /*#__PURE__*/React.createElement("div", {
    className: "lbl"
  }, "ASR (Answer)"), /*#__PURE__*/React.createElement("div", {
    className: "val ok"
  }, p.asr, "%"), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 10,
      color: "var(--muted)",
      fontFamily: "var(--mono)"
    }
  }, "ACD ", p.acd)), /*#__PURE__*/React.createElement("div", {
    className: "swstat-cell"
  }, /*#__PURE__*/React.createElement("div", {
    className: "lbl"
  }, "SIP Trunks"), /*#__PURE__*/React.createElement("div", {
    className: "val warn"
  }, "5", /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: "var(--muted)",
      fontWeight: 500
    }
  }, " / 6 up")), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 10,
      color: "var(--err)",
      fontFamily: "var(--mono)"
    }
  }, "\u25CF 1 unreg \xB7 1 degraded"))));
};

// ── Trunks table ──
const TrunksCard = () => /*#__PURE__*/React.createElement("div", {
  className: "card"
}, /*#__PURE__*/React.createElement("div", {
  className: "card-h"
}, /*#__PURE__*/React.createElement("h3", null, "SIP Trunks \xB7 Carriers"), /*#__PURE__*/React.createElement(SourceBadge, {
  src: "3cx"
}), /*#__PURE__*/React.createElement(SourceBadge, {
  src: "zbx"
}), /*#__PURE__*/React.createElement("div", {
  className: "h-spacer"
}), /*#__PURE__*/React.createElement("span", {
  className: "h-meta"
}, "OPTIONS keepalive \xB7 60s"), /*#__PURE__*/React.createElement("span", {
  className: "h-link"
}, "Open in 3CX Mgmt ", /*#__PURE__*/React.createElement(Icon, {
  name: "external",
  size: 11
}))), /*#__PURE__*/React.createElement("table", {
  className: "trunk-tbl"
}, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
  style: {
    width: 90
  }
}, "Status"), /*#__PURE__*/React.createElement("th", null, "Trunk / Carrier"), /*#__PURE__*/React.createElement("th", {
  style: {
    width: 240
  }
}, "Channel utilization"), /*#__PURE__*/React.createElement("th", {
  style: {
    width: 70,
    textAlign: "right"
  }
}, "In"), /*#__PURE__*/React.createElement("th", {
  style: {
    width: 70,
    textAlign: "right"
  }
}, "Out"), /*#__PURE__*/React.createElement("th", {
  style: {
    width: 64,
    textAlign: "right"
  }
}, "ASR"), /*#__PURE__*/React.createElement("th", {
  style: {
    width: 60,
    textAlign: "right"
  }
}, "MOS"), /*#__PURE__*/React.createElement("th", {
  style: {
    width: 60,
    textAlign: "right"
  }
}, "Err 5m"))), /*#__PURE__*/React.createElement("tbody", null, window.VOIP_TRUNKS.map((t, i) => {
  const used = t.chIn + t.chOut;
  const freePct = (t.chTotal - used) / t.chTotal * 100;
  const inPct = t.chIn / t.chTotal * 100;
  const outPct = t.chOut / t.chTotal * 100;
  return /*#__PURE__*/React.createElement("tr", {
    key: i
  }, /*#__PURE__*/React.createElement("td", null, /*#__PURE__*/React.createElement("span", {
    className: "tk-status " + t.status
  }, t.status === "reg" ? "REG" : t.status === "dgr" ? "DEGR" : "UNREG")), /*#__PURE__*/React.createElement("td", null, /*#__PURE__*/React.createElement("div", {
    className: "tk-name"
  }, t.name), /*#__PURE__*/React.createElement("div", {
    className: "tk-host"
  }, t.host, " \xB7 ", t.did)), /*#__PURE__*/React.createElement("td", null, /*#__PURE__*/React.createElement("div", {
    className: "ch-bar"
  }, /*#__PURE__*/React.createElement("i", {
    className: "in",
    style: {
      width: inPct + "%"
    }
  }), /*#__PURE__*/React.createElement("i", {
    className: "out",
    style: {
      width: outPct + "%"
    }
  }), /*#__PURE__*/React.createElement("i", {
    className: "free",
    style: {
      width: freePct + "%"
    }
  }), /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, used, "/", t.chTotal))), /*#__PURE__*/React.createElement("td", {
    className: "mono",
    style: {
      textAlign: "right",
      color: "var(--cx)"
    }
  }, t.chIn), /*#__PURE__*/React.createElement("td", {
    className: "mono",
    style: {
      textAlign: "right",
      color: "var(--info)"
    }
  }, t.chOut), /*#__PURE__*/React.createElement("td", {
    className: "mono",
    style: {
      textAlign: "right",
      color: t.asr === 0 ? "var(--muted)" : t.asr < 92 ? "var(--warn)" : "var(--fg-2)"
    }
  }, t.asr > 0 ? t.asr.toFixed(1) + "%" : "—"), /*#__PURE__*/React.createElement("td", {
    className: "mono",
    style: {
      textAlign: "right",
      color: t.mos === 0 ? "var(--muted)" : t.mos < 4.1 ? "var(--warn)" : "var(--ok)"
    }
  }, t.mos > 0 ? t.mos.toFixed(2) : "—"), /*#__PURE__*/React.createElement("td", {
    className: "mono",
    style: {
      textAlign: "right",
      color: t.errors > 0 ? "var(--warn)" : "var(--muted)"
    }
  }, t.errors));
}))));

// ── Active calls list ──
const ActiveCallsCard = () => {
  const dirLbl = {
    in: "INBOUND",
    out: "OUTBOUND",
    int: "INTERNAL",
    q: "QUEUED"
  };
  return /*#__PURE__*/React.createElement("div", {
    className: "card"
  }, /*#__PURE__*/React.createElement("div", {
    className: "card-h"
  }, /*#__PURE__*/React.createElement("h3", null, "Active Calls \xB7 live"), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "3cx"
  }), /*#__PURE__*/React.createElement("div", {
    className: "h-spacer"
  }), /*#__PURE__*/React.createElement("span", {
    className: "h-meta"
  }, window.VOIP_CALLS.length, " ongoing \xB7 2s refresh")), /*#__PURE__*/React.createElement("div", {
    className: "calls-list"
  }, window.VOIP_CALLS.map((c, i) => {
    const onBars = c.q === "good" ? 4 : c.q === "fair" ? 2 : 1;
    return /*#__PURE__*/React.createElement("div", {
      key: i,
      className: "call-row"
    }, /*#__PURE__*/React.createElement("span", {
      className: "c-dir " + c.dir
    }, dirLbl[c.dir]), /*#__PURE__*/React.createElement("div", {
      className: "c-leg"
    }, /*#__PURE__*/React.createElement("div", {
      className: "who"
    }, c.from), /*#__PURE__*/React.createElement("div", {
      className: "sub"
    }, c.fromSub)), /*#__PURE__*/React.createElement("div", {
      className: "c-leg"
    }, /*#__PURE__*/React.createElement("div", {
      className: "who"
    }, c.to), /*#__PURE__*/React.createElement("div", {
      className: "sub"
    }, c.toSub)), /*#__PURE__*/React.createElement("div", {
      className: "c-dur"
    }, c.dur), /*#__PURE__*/React.createElement("div", {
      className: "c-tech"
    }, /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("b", null, c.codec)), /*#__PURE__*/React.createElement("span", null, "via ", c.trunk)), /*#__PURE__*/React.createElement("div", {
      className: "c-q " + c.q
    }, c.mos > 0 ? /*#__PURE__*/React.createElement("span", {
      className: "mos " + c.q
    }, c.mos.toFixed(2)) : /*#__PURE__*/React.createElement("span", {
      className: "mos",
      style: {
        color: "var(--muted)"
      }
    }, "\u2014"), /*#__PURE__*/React.createElement("span", {
      className: "bars"
    }, [0, 1, 2, 3].map(b => /*#__PURE__*/React.createElement("i", {
      key: b,
      className: b < onBars ? "on" : ""
    })))));
  })));
};

// ── Call quality card ──
const CallQualityCard = () => {
  const q = window.VOIP_QUALITY;
  const mosNow = q.mos[q.mos.length - 1];
  const jitNow = q.jitter[q.jitter.length - 1];
  const lossNow = q.loss[q.loss.length - 1];
  const rttNow = q.rtt[q.rtt.length - 1];
  const cls = (good, fair, val, inv) => {
    if (inv) return val <= good ? "ok" : val <= fair ? "warn" : "err";
    return val >= good ? "ok" : val >= fair ? "warn" : "err";
  };
  return /*#__PURE__*/React.createElement("div", {
    className: "card"
  }, /*#__PURE__*/React.createElement("div", {
    className: "card-h"
  }, /*#__PURE__*/React.createElement("h3", null, "Call Quality \xB7 24h"), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "3cx"
  }), /*#__PURE__*/React.createElement("div", {
    className: "h-spacer"
  }), /*#__PURE__*/React.createElement("span", {
    className: "h-meta"
  }, "RTCP-XR \xB7 30m")), /*#__PURE__*/React.createElement("div", {
    className: "cq-rows"
  }, /*#__PURE__*/React.createElement("div", {
    className: "cq-row"
  }, /*#__PURE__*/React.createElement("div", {
    className: "cq-lbl"
  }, /*#__PURE__*/React.createElement("span", {
    className: "name"
  }, "MOS"), /*#__PURE__*/React.createElement("span", {
    className: "sub"
  }, "target \u2265 4.0")), /*#__PURE__*/React.createElement("div", {
    className: "cq-spark"
  }, /*#__PURE__*/React.createElement(Sparkline, {
    data: q.mos,
    color: "var(--ok)",
    width: 300,
    height: 32,
    threshold: 4.0
  })), /*#__PURE__*/React.createElement("div", {
    className: "cq-val"
  }, /*#__PURE__*/React.createElement("div", {
    className: "v " + cls(4.2, 4.0, mosNow)
  }, mosNow.toFixed(2)), /*#__PURE__*/React.createElement("div", {
    className: "u"
  }, "score"))), /*#__PURE__*/React.createElement("div", {
    className: "cq-row"
  }, /*#__PURE__*/React.createElement("div", {
    className: "cq-lbl"
  }, /*#__PURE__*/React.createElement("span", {
    className: "name"
  }, "Jitter"), /*#__PURE__*/React.createElement("span", {
    className: "sub"
  }, "target \u2264 20ms")), /*#__PURE__*/React.createElement("div", {
    className: "cq-spark"
  }, /*#__PURE__*/React.createElement(Sparkline, {
    data: q.jitter,
    color: "var(--warn)",
    width: 300,
    height: 32,
    threshold: 20
  })), /*#__PURE__*/React.createElement("div", {
    className: "cq-val"
  }, /*#__PURE__*/React.createElement("div", {
    className: "v " + cls(15, 20, jitNow, true)
  }, jitNow.toFixed(1)), /*#__PURE__*/React.createElement("div", {
    className: "u"
  }, "ms"))), /*#__PURE__*/React.createElement("div", {
    className: "cq-row"
  }, /*#__PURE__*/React.createElement("div", {
    className: "cq-lbl"
  }, /*#__PURE__*/React.createElement("span", {
    className: "name"
  }, "Packet loss"), /*#__PURE__*/React.createElement("span", {
    className: "sub"
  }, "target \u2264 0.5%")), /*#__PURE__*/React.createElement("div", {
    className: "cq-spark"
  }, /*#__PURE__*/React.createElement(Sparkline, {
    data: q.loss,
    color: "var(--pf)",
    width: 300,
    height: 32,
    threshold: 0.5
  })), /*#__PURE__*/React.createElement("div", {
    className: "cq-val"
  }, /*#__PURE__*/React.createElement("div", {
    className: "v " + cls(0.3, 0.5, lossNow, true)
  }, lossNow.toFixed(2)), /*#__PURE__*/React.createElement("div", {
    className: "u"
  }, "%"))), /*#__PURE__*/React.createElement("div", {
    className: "cq-row"
  }, /*#__PURE__*/React.createElement("div", {
    className: "cq-lbl"
  }, /*#__PURE__*/React.createElement("span", {
    className: "name"
  }, "Round-trip"), /*#__PURE__*/React.createElement("span", {
    className: "sub"
  }, "target \u2264 50ms")), /*#__PURE__*/React.createElement("div", {
    className: "cq-spark"
  }, /*#__PURE__*/React.createElement(Sparkline, {
    data: q.rtt,
    color: "var(--info)",
    width: 300,
    height: 32,
    threshold: 50
  })), /*#__PURE__*/React.createElement("div", {
    className: "cq-val"
  }, /*#__PURE__*/React.createElement("div", {
    className: "v " + cls(30, 50, rttNow, true)
  }, rttNow.toFixed(0)), /*#__PURE__*/React.createElement("div", {
    className: "u"
  }, "ms")))));
};

// ── Extension grid by site ──
const ExtensionGrid = () => {
  const [sites] = useStateVP(window.VOIP_SITES);
  const totals = useMemoVP(() => {
    const t = {
      reg: 0,
      unreg: 0,
      call: 0,
      dnd: 0,
      alert: 0,
      total: 0
    };
    sites.forEach(s => s.ext.forEach(e => {
      t[e.state]++;
      t.total++;
    }));
    return t;
  }, [sites]);
  return /*#__PURE__*/React.createElement("div", {
    className: "card ext-card"
  }, /*#__PURE__*/React.createElement("div", {
    className: "card-h"
  }, /*#__PURE__*/React.createElement("h3", null, "Extensions \xB7 Registration Status"), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "3cx"
  }), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "pf"
  }), /*#__PURE__*/React.createElement("div", {
    className: "h-spacer"
  }), /*#__PURE__*/React.createElement("span", {
    className: "h-meta"
  }, totals.total, " extensions \xB7 last poll 8s")), /*#__PURE__*/React.createElement("div", {
    className: "ext-toolbar"
  }, /*#__PURE__*/React.createElement("div", {
    className: "legend"
  }, /*#__PURE__*/React.createElement("span", {
    className: "it"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw reg"
  }), " Registered (", totals.reg, ")"), /*#__PURE__*/React.createElement("span", {
    className: "it"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw call"
  }), " On call (", totals.call, ")"), /*#__PURE__*/React.createElement("span", {
    className: "it"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw dnd"
  }), " DND (", totals.dnd, ")"), /*#__PURE__*/React.createElement("span", {
    className: "it"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw alert"
  }), " Alert (", totals.alert, ")"), /*#__PURE__*/React.createElement("span", {
    className: "it"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sw unreg"
  }), " Unregistered (", totals.unreg, ")")), /*#__PURE__*/React.createElement("span", {
    className: "spacer"
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--mono)",
      fontSize: 11,
      color: "var(--muted)"
    }
  }, "filter:"), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--mono)",
      fontSize: 11,
      color: "var(--fg-2)",
      background: "var(--bg-2)",
      border: "1px solid var(--line)",
      padding: "3px 8px",
      borderRadius: 3
    }
  }, "all sites")), sites.map(site => {
    const counts = site.ext.reduce((a, e) => {
      a[e.state] = (a[e.state] || 0) + 1;
      return a;
    }, {});
    return /*#__PURE__*/React.createElement("div", {
      key: site.id,
      className: "ext-site"
    }, /*#__PURE__*/React.createElement("div", {
      className: "ext-site-head"
    }, /*#__PURE__*/React.createElement("span", {
      className: "name"
    }, site.name), /*#__PURE__*/React.createElement("span", {
      className: "stat"
    }, /*#__PURE__*/React.createElement("b", {
      className: "ok"
    }, counts.reg || 0, " reg"), " \xB7 ", /*#__PURE__*/React.createElement("b", {
      className: "ok"
    }, counts.call || 0, " call"), counts.dnd ? /*#__PURE__*/React.createElement(React.Fragment, null, " \xB7 ", /*#__PURE__*/React.createElement("b", {
      className: "warn"
    }, counts.dnd, " dnd")) : null, counts.alert ? /*#__PURE__*/React.createElement(React.Fragment, null, " \xB7 ", /*#__PURE__*/React.createElement("b", {
      className: "err"
    }, counts.alert, " alert")) : null, counts.unreg ? /*#__PURE__*/React.createElement(React.Fragment, null, " \xB7 ", counts.unreg, " unreg") : null, /*#__PURE__*/React.createElement("span", {
      style: {
        marginLeft: 8,
        color: "var(--muted-2)"
      }
    }, "\xB7 ", site.ext.length, " total"))), /*#__PURE__*/React.createElement("div", {
      className: "ext-grid"
    }, site.ext.map(e => /*#__PURE__*/React.createElement("div", {
      key: e.ext,
      className: "ext-cell " + e.state,
      title: `x${e.ext} · ${e.name} · ${e.state}`
    }, /*#__PURE__*/React.createElement("div", {
      className: "ec-num"
    }, "x", e.ext), /*#__PURE__*/React.createElement("div", {
      className: "ec-name"
    }, e.name), /*#__PURE__*/React.createElement("span", {
      className: "ec-led"
    })))));
  }));
};

// ── Top extensions / talkers ──
const TopTalkers = () => {
  const max = Math.max(...window.VOIP_TOP.map(t => t.calls));
  return /*#__PURE__*/React.createElement("div", {
    className: "card"
  }, /*#__PURE__*/React.createElement("div", {
    className: "card-h"
  }, /*#__PURE__*/React.createElement("h3", null, "Top Extensions \xB7 Today"), /*#__PURE__*/React.createElement(SourceBadge, {
    src: "3cx"
  }), /*#__PURE__*/React.createElement("div", {
    className: "h-spacer"
  }), /*#__PURE__*/React.createElement("span", {
    className: "h-meta"
  }, "by call volume")), window.VOIP_TOP.map((t, i) => /*#__PURE__*/React.createElement("div", {
    key: t.ext,
    className: "tt-row"
  }, /*#__PURE__*/React.createElement("span", {
    className: "tt-rank"
  }, i + 1), /*#__PURE__*/React.createElement("div", {
    className: "tt-name"
  }, /*#__PURE__*/React.createElement("div", {
    className: "who"
  }, /*#__PURE__*/React.createElement("span", {
    className: "ext"
  }, "x", t.ext), t.name), /*#__PURE__*/React.createElement("div", {
    className: "sub"
  }, t.mins, " min talk \xB7 ", t.site)), /*#__PURE__*/React.createElement("span", {
    className: "tt-bar"
  }, /*#__PURE__*/React.createElement("i", {
    style: {
      width: t.calls / max * 100 + "%"
    }
  })), /*#__PURE__*/React.createElement("span", {
    className: "tt-cnt"
  }, t.calls))));
};

// ── Queues panel ──
const QueuesCard = () => /*#__PURE__*/React.createElement("div", {
  className: "card"
}, /*#__PURE__*/React.createElement("div", {
  className: "card-h"
}, /*#__PURE__*/React.createElement("h3", null, "Call Queues"), /*#__PURE__*/React.createElement(SourceBadge, {
  src: "3cx"
}), /*#__PURE__*/React.createElement("div", {
  className: "h-spacer"
}), /*#__PURE__*/React.createElement("span", {
  className: "h-meta"
}, "SLA = answered within target")), /*#__PURE__*/React.createElement("div", {
  className: "q-grid"
}, window.VOIP_QUEUES.map(q => /*#__PURE__*/React.createElement("div", {
  key: q.ext,
  className: "q-cell"
}, /*#__PURE__*/React.createElement("div", {
  className: "q-head"
}, /*#__PURE__*/React.createElement("span", {
  className: "name"
}, q.name), /*#__PURE__*/React.createElement("span", {
  className: "ext"
}, "x", q.ext)), /*#__PURE__*/React.createElement("div", {
  className: "q-stats"
}, /*#__PURE__*/React.createElement("div", {
  className: "q-stat"
}, /*#__PURE__*/React.createElement("span", {
  className: "k"
}, "Agents"), /*#__PURE__*/React.createElement("span", {
  className: "v"
}, q.agentsOn, "/", q.agents)), /*#__PURE__*/React.createElement("div", {
  className: "q-stat"
}, /*#__PURE__*/React.createElement("span", {
  className: "k"
}, "Waiting"), /*#__PURE__*/React.createElement("span", {
  className: "v " + (q.waiting > 2 ? "warn" : "")
}, q.waiting)), /*#__PURE__*/React.createElement("div", {
  className: "q-stat"
}, /*#__PURE__*/React.createElement("span", {
  className: "k"
}, "SLA ", q.slaSec, "s"), /*#__PURE__*/React.createElement("span", {
  className: "v " + (q.sla < 90 ? "warn" : "")
}, q.sla, "%")), /*#__PURE__*/React.createElement("div", {
  className: "q-stat"
}, /*#__PURE__*/React.createElement("span", {
  className: "k"
}, "Abandon"), /*#__PURE__*/React.createElement("span", {
  className: "v " + (q.abandon > 3 ? "warn" : "")
}, q.abandon))), /*#__PURE__*/React.createElement("div", {
  className: "q-bar"
}, /*#__PURE__*/React.createElement("i", {
  className: "ans",
  style: {
    width: q.ans / (q.ans + q.abandon) * 100 + "%"
  }
}), /*#__PURE__*/React.createElement("i", {
  className: "aban",
  style: {
    width: q.abandon / (q.ans + q.abandon) * 100 + "%"
  }
}))))));

// ── Problems ──
const VoipProblems = () => /*#__PURE__*/React.createElement("div", {
  className: "card"
}, /*#__PURE__*/React.createElement("div", {
  className: "card-h"
}, /*#__PURE__*/React.createElement("h3", null, "Problems"), /*#__PURE__*/React.createElement(SourceBadge, {
  src: "zbx"
}), /*#__PURE__*/React.createElement("div", {
  className: "h-spacer"
}), /*#__PURE__*/React.createElement(Icon, {
  name: "filter",
  size: 12
}), /*#__PURE__*/React.createElement(Icon, {
  name: "more",
  size: 14
})), /*#__PURE__*/React.createElement("div", {
  style: {
    padding: "8px 14px 6px",
    fontSize: 11,
    color: "var(--muted)",
    letterSpacing: 0.4,
    textTransform: "uppercase",
    borderBottom: "1px solid var(--line)"
  }
}, "Triggers \xB7 last 24h \xB7 VoIP host group"), window.VOIP_PROBLEMS.map((p, i) => /*#__PURE__*/React.createElement("div", {
  key: i,
  className: "problem-row " + (p.ack ? "ack" : "")
}, /*#__PURE__*/React.createElement("div", {
  className: "top"
}, /*#__PURE__*/React.createElement(Sev, {
  level: p.sev
}), /*#__PURE__*/React.createElement("span", {
  className: "host"
}, p.host), /*#__PURE__*/React.createElement("span", {
  className: "age"
}, p.age)), /*#__PURE__*/React.createElement("div", {
  className: "trig"
}, p.trig), /*#__PURE__*/React.createElement("div", {
  className: "ts"
}, p.ts, p.ack && " · ack"))));

// ═══════════════════════════════════════════════════════════════
// APP
// ═══════════════════════════════════════════════════════════════

const TWEAK_DEFAULTS_VP = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#2bd6c0",
  "showSourceBadges": true,
  "showInternalCalls": true
} /*EDITMODE-END*/;
const VoipApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_VP);
  useEffectVP(() => {
    document.documentElement.style.setProperty("--cx", t.accent);
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.accent, t.showSourceBadges]);
  const densityVar = t.density === "spacious" ? 1.15 : t.density === "dense" ? 0.85 : 1;
  const p = window.VOIP_PBX;
  return /*#__PURE__*/React.createElement("div", {
    className: "app",
    "data-density": t.density,
    style: {
      fontSize: `${13 * densityVar}px`
    }
  }, /*#__PURE__*/React.createElement(GlobalSidebar, {
    active: "voip"
  }), /*#__PURE__*/React.createElement("div", {
    className: "main"
  }, /*#__PURE__*/React.createElement(GlobalTopbar, {
    crumb: ["Voice", "3CX Phone System", p.fqdn],
    search: "Find extension, DID, caller\u2026"
  }), /*#__PURE__*/React.createElement("div", {
    className: "page-header"
  }, /*#__PURE__*/React.createElement("div", {
    className: "icon-btn",
    style: {
      marginTop: 4
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "back"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "host-title"
  }, /*#__PURE__*/React.createElement("h1", null, "3CX Phone System"), /*#__PURE__*/React.createElement("span", {
    className: "ip"
  }, p.fqdn), /*#__PURE__*/React.createElement("span", {
    className: "role-tag voip",
    style: {
      fontSize: 10,
      padding: "1px 8px"
    }
  }, "3CX \xB7 ", p.version)), /*#__PURE__*/React.createElement("div", {
    className: "host-meta voip-meta-bar"
  }, /*#__PURE__*/React.createElement("span", {
    className: "pill"
  }, /*#__PURE__*/React.createElement("span", {
    className: "dot",
    style: {
      background: "var(--ok)"
    }
  }), " Phone System online"), /*#__PURE__*/React.createElement("span", {
    className: "pill"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "IP"), " ", /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, p.ip)), /*#__PURE__*/React.createElement("span", {
    className: "pill"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "License"), " ", /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, p.edition)), /*#__PURE__*/React.createElement("span", {
    className: "pill"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "Uptime"), " ", /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, p.uptime)), /*#__PURE__*/React.createElement("span", {
    className: "pill"
  }, /*#__PURE__*/React.createElement("span", {
    className: "lbl"
  }, "Region"), " ", /*#__PURE__*/React.createElement("span", {
    className: "v"
  }, "Arc-DC")), /*#__PURE__*/React.createElement("span", {
    className: "pill"
  }, /*#__PURE__*/React.createElement("span", {
    className: "dot",
    style: {
      background: "var(--warn)"
    }
  }), " 1 trunk degraded \xB7 1 unreg"))), /*#__PURE__*/React.createElement("div", {
    className: "timerange"
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "calendar"
  }), /*#__PURE__*/React.createElement("span", {
    className: "range-val"
  }, "May 13 09:42 \u2014 May 14 09:42"), /*#__PURE__*/React.createElement(Icon, {
    name: "chevron"
  }))), /*#__PURE__*/React.createElement("div", {
    className: "body",
    "data-screen-label": "VoIP Dashboard"
  }, /*#__PURE__*/React.createElement(DemoBanner, {
    name: "VoIP \xB7 3CX Dashboard"
  }), /*#__PURE__*/React.createElement(VoipKpis, null), /*#__PURE__*/React.createElement("div", {
    className: "voip-row-2col",
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "voip-stack"
  }, /*#__PURE__*/React.createElement(ConcurrencyChart, null), /*#__PURE__*/React.createElement(CallQualityCard, null)), /*#__PURE__*/React.createElement(ServicesPanel, null)), /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement(TrunksCard, null)), /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement(ActiveCallsCard, null)), /*#__PURE__*/React.createElement("div", {
    className: "voip-row-2col-wide",
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement(QueuesCard, null), /*#__PURE__*/React.createElement(TopTalkers, null)), /*#__PURE__*/React.createElement("div", {
    style: {
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement(ExtensionGrid, null)), /*#__PURE__*/React.createElement(VoipProblems, null))), /*#__PURE__*/React.createElement(TweaksPanel, {
    title: "Tweaks"
  }, /*#__PURE__*/React.createElement(TweakSection, {
    title: "Layout"
  }, /*#__PURE__*/React.createElement(TweakRadio, {
    label: "Density",
    value: t.density,
    options: [{
      value: "spacious",
      label: "Spacious"
    }, {
      value: "balanced",
      label: "Balanced"
    }, {
      value: "dense",
      label: "Dense"
    }],
    onChange: v => setTweak("density", v)
  })), /*#__PURE__*/React.createElement(TweakSection, {
    title: "Visual"
  }, /*#__PURE__*/React.createElement(TweakColor, {
    label: "3CX accent",
    value: t.accent,
    options: ["#2bd6c0", "#34d399", "#5b8cff", "#7c5cff", "#f5b300", "#d92929"],
    onChange: v => setTweak("accent", v)
  }), /*#__PURE__*/React.createElement(TweakToggle, {
    label: "Show data-source badges",
    value: t.showSourceBadges,
    onChange: v => setTweak("showSourceBadges", v)
  }))));
};
ReactDOM.createRoot(document.getElementById("root")).render(/*#__PURE__*/React.createElement(VoipApp, null));