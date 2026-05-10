# TCS Dashboard — Zabbix Frontend Module

A custom-skinned host detail page that lives inside Zabbix. Reuses the React
mockup from `Zabbix_Extreme.zip` and replaces its mock data with calls to the
real Zabbix JSON-RPC API, server-side.

```
ui/modules/tcs_dashboard/
├── manifest.json
├── Module.php                         menu registration
├── actions/
│   ├── ActionDashboard.php            HTML page controller
│   └── ActionDashboardData.php        JSON refresh endpoint
├── views/
│   └── dashboard.view.php             page template (loads our assets)
└── assets/
    ├── styles.css                     ← from Zabbix_Extreme.zip
    ├── primitives.jsx                 ← from Zabbix_Extreme.zip
    ├── tabs.jsx                       ← from Zabbix_Extreme.zip
    ├── shell.jsx                      ← from Zabbix_Extreme.zip
    ├── tweaks-panel.jsx               ← from Zabbix_Extreme.zip
    ├── app.jsx                        ← from Zabbix_Extreme.zip
    └── data-bridge.jsx                NEW — bridges server data → window globals
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

- **No host picker.** Pass `?hostid=` in the URL. Add a dropdown in `app.jsx`
  if you want one — the host list comes from `host.get` with no filter.
- **No write paths.** All actions are read-only. If you want acknowledge /
  problem-suppress / config-push buttons in the UI, add a third action
  controller (`tcs.dashboard.action`) with CSRF enabled and a method
  whitelist.
- **No NVR / Switch dashboards yet.** The zip had three apps; this scaffold
  wires up the AP Detail one (the `Zabbix Dashboard.html` flow). The other
  two follow the same pattern — register two more actions in
  `manifest.json`, add two more controllers + views, point each at its own
  set of JSX assets.
