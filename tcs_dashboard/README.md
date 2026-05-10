# TCS Dashboard — Zabbix Frontend Module

Three custom-skinned operations pages that live inside Zabbix:

| Page                | URL                                              | Status                       |
| ------------------- | ------------------------------------------------ | ---------------------------- |
| AP Detail (Wireless)| `zabbix.php?action=tcs.dashboard.view&hostid=N`  | Wired to live Zabbix data    |
| Surveillance NOC    | `zabbix.php?action=tcs.surveillance.view`        | Mock data (Milestone wiring) |
| Switch Port Status  | `zabbix.php?action=tcs.switches.view`            | Mock data (SNMP wiring)      |

All three share a common sidebar that cross-links between them and back to
the default Zabbix UI (`zabbix.php?action=dashboard.view`).

```
ui/modules/tcs_dashboard/
├── manifest.json
├── Module.php                         menu registration
├── actions/
│   ├── ActionDashboard.php            AP Detail controller (live data)
│   ├── ActionDashboardData.php        AP Detail JSON refresh endpoint
│   ├── ActionSurveillance.php         Surveillance controller (mock data)
│   └── ActionSwitches.php             Switches controller (mock data)
├── views/
│   ├── dashboard.view.php
│   ├── surveillance.view.php
│   └── switches.view.php
└── assets/
    ├── styles.css                     shared design tokens
    ├── primitives.jsx                 shared (Icon, SourceBadge, etc.)
    ├── tweaks-panel.jsx               shared (settings flyout)
    ├── tabs.jsx                       AP Detail tabs
    ├── shell.jsx                      AP Detail sidebar + topbar
    ├── app.jsx                        AP Detail entry point
    ├── data-bridge.jsx                AP Detail server↔client adapter
    ├── nvr-shell.jsx                  Surveillance/Switches shared sidebar
    ├── nvr-data.jsx                   Surveillance mock data
    ├── nvr-overview.jsx               Surveillance overview view
    ├── nvr-app.jsx                    Surveillance entry point
    ├── surveillance.css               Surveillance-specific styles
    ├── switches-data.jsx              Switches mock data
    ├── switches-widgets.jsx           Switches port-grid widgets
    ├── switches-app.jsx               Switches entry point
    └── switches.css                   Switches-specific styles
```

## Install

1. Copy this whole folder to your Zabbix UI's modules directory:

   ```
   <zabbix-ui-root>/modules/tcs_dashboard/
   ```

   On a typical Linux install that path is `/usr/share/zabbix/modules/`.
   The folder name **must** match the `id` in `manifest.json` (`tcs_dashboard`).

2. In the Zabbix web UI go to **Administration → General → Modules**, click
   **Scan directory**, find "TCS Dashboard" in the list, and toggle it to
   **Enabled**.

3. The new entry appears under the **Monitoring** menu. Or hit it directly:

   ```
   /zabbix.php?action=tcs.dashboard.view&hostid=10847
   ```

   Without `hostid` you'll see the empty-state ("Select a host"). For now the
   easiest pattern is to link to it from a Zabbix host map / inventory page
   with the hostid baked in. A host-picker dropdown is a 30-line addition to
   `app.jsx` if you want one.

## Cross-page navigation

Every sidebar has the same top-level structure:

- **Default Zabbix Dashboard** (small pill above the brand) — exits the
  custom UI and returns to standard Zabbix.
- **Wireless APs** — links to `tcs.dashboard.view` (AP Detail).
- **Switches** — links to `tcs.switches.view`.
- **Surveillance** — links to `tcs.surveillance.view` (only present in the
  AP Detail sidebar; the Surveillance/Switches sidebars dedicate their second
  section to the surveillance subpages: NOC Overview, Cameras, Recording
  Servers).

The URLs live in one place — `window.TCS_NAV` at the top of `shell.jsx` and
mirrored in `nvr-shell.jsx`. If you change a route or rename an action,
update both.

The "Wireless APs" link from the surveillance/switches pages goes to
`tcs.dashboard.view` without a `hostid`, which lands on the empty-state
("Select a host"). If you want it to deep-link to a specific host, change
`window.TCS_NAV.apDetail` to include the hostid you want.

## Map your real item keys

The PHP controller has placeholder item keys in `ActionDashboard::collectItems()`:

```php
$key_map = [
    'cpu'     => 'system.cpu.util',
    'memory'  => 'vm.memory.utilization',
    'temp'    => 'sensor.temp.value[CPU]',
    'poeDraw' => 'extreme.ap.poe.draw',
    ...
];
```

Edit the right-hand side to match the keys actually defined on your Extreme
AP / ICMP / PacketFence templates. If a key isn't found on the host the
frontend renders a `—` and a "missing" badge — useful while you're wiring
things up.

A quick way to dump the actual keys on a host:

```bash
curl -sS http://<zabbix>/api_jsonrpc.php \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"item.get","params":{"output":["key_","name"],"hostids":["10847"]},"auth":"<token>","id":1}' \
  | jq -r '.result[] | "\(.key_)\t\(.name)"'
```

## PacketFence data

PacketFence is a separate system, not Zabbix. The boot payload exposes
`pfClients` and `pfAuthFails` arrays but they're empty by default. To fill
them in:

1. Open `actions/ActionDashboard.php`.
2. Implement `collectPacketFence($hostid)` to call your PF instance's REST
   API (`/api/v1/nodes`, `/api/v1/reports/...`).
3. Uncomment `$boot['pfClients'] = $this->collectPacketFence($hostid);` in
   `doAction()`.

Cache aggressively — PF queries are slow and the page polls every 30s.

## Live refresh

`data-bridge.jsx` polls `tcs.dashboard.data` every 30 seconds and updates the
window globals. If you want push updates instead, swap the `setInterval` for
a Server-Sent Events stream — Zabbix's frontend supports streaming responses
from a controller, you'd just emit `text/event-stream` from `doAction()`.

## Auth and permissions

Both controllers require `USER_TYPE_ZABBIX_USER` or higher. If you want to
restrict to a specific user group / role, change `checkPermissions()` in
both action classes:

```php
protected function checkPermissions(): bool {
    if ($this->getUserType() < USER_TYPE_ZABBIX_USER) return false;
    return in_array(CWebUser::$data['roleid'] ?? 0, [/* allowed role ids */]);
}
```

Host-level visibility is automatic — the API calls run as the logged-in user,
so they only return hosts that user has read access to.

## Air-gapped installs

The view loads React, ReactDOM, and Babel-standalone from `unpkg.com`. If
your Zabbix server can't reach the internet:

1. Download those three files into `assets/`.
2. Update the `<script src="...">` tags in `views/dashboard.view.php`.
3. Consider doing a real build step (esbuild / vite) so you can drop
   Babel-standalone — it's the heaviest of the three by far.

## Version notes

Tested patterns: Zabbix **6.0 LTS**, **6.4**, **7.0**.

- The menu registration in `Module.php` uses `APP::Component()->get('menu.main')`,
  which exists from 6.0+. For 5.x, the menu API was different (and there was
  no `Zabbix\Core\CModule` namespace) — you'd downgrade `manifest_version` to
  `1.0` and use the older `\Modules\TcsDashboard\Module extends \CModule`
  signature.
- `layout.htmlpage` and `layout.json` exist on 6.0+. On older majors,
  substitute `layout.htmlpage` with whatever the minimal layout was (often
  just omitting `layout` from the action config).
- The `<style>` block at the top of `dashboard.view.php` hides Zabbix's own
  header/sidebar so the dashboard takes the full viewport. The selectors are
  reasonable but Zabbix occasionally renames its top-level chrome elements
  between majors. If after upgrade you see Zabbix's nav reappearing above
  your dashboard, inspect the DOM and update those selectors.
- `select_acknowledges`, the `proxy_hostid` field, and a few other
  `host.get` / `event.get` parameters were renamed in 7.0+. If you upgrade,
  check the API changelog for the methods used in `ActionDashboard.php`.

## What this scaffold deliberately doesn't do

- **No host picker on AP Detail.** Pass `?hostid=` in the URL. Add a
  dropdown in `app.jsx` if you want one — the host list comes from
  `host.get` with no filter.
- **No write paths.** All actions are read-only. If you want acknowledge /
  problem-suppress / config-push buttons in the UI, add a write-action
  controller (e.g. `tcs.dashboard.action`) with CSRF enabled and a method
  whitelist.
- **No real data on Surveillance / Switches yet.** Both pages render their
  mockups (`nvr-data.jsx`, `switches-data.jsx`). The controllers are
  scaffolded so that wiring real data is a parallel job to what's already
  done for AP Detail:
  - **Surveillance:** Milestone XProtect's REST API (`/api/rest/v1/`) for
    server health and recording state, optionally Zabbix items templated
    against the recording servers themselves. Build `surveillance-bridge.jsx`
    parallel to `data-bridge.jsx`, mapping the API response into
    `window.MILESTONE` / `window.SITES` / etc.
  - **Switches:** Zabbix host.get + item.get against your switch templates.
    Port operational status comes from `ifOperStatus[<index>]` on the
    Generic SNMP template; PoE state from POWER-ETHERNET-MIB
    (`pethPsePortDetectionStatus`). Build `switches-bridge.jsx` mapping
    those into `window.SWITCH_SITES` / `window.ARC_MDF_STACK` /
    `window.makePortDetail`.
