<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\SwitchClient;

/**
 * GET zabbix.php?action=tcs.switches.view[&switchid=NNN]
 *
 * Collects an initial snapshot of stack / port / PoE state from the Zabbix
 * Item API (via SwitchClient) and hands it to the view as $data['boot'].
 * The view inlines this as window.SWITCH_BOOT so a future switches-bridge.jsx
 * can adapt it into window.SWITCH_SITES / window.ARC_MDF_STACK /
 * window.makePortDetail without changing the React components.
 *
 * Item keys expected on switch hosts (from the lifted EXOS template
 * templates/extreme_exos_by_snmp_with_poe.yaml):
 *   stacking.member[1..8]
 *   net.if.status[ifOperStatus.<member>.<port>]
 *   snmp.interfaces.poe.dstatus[<member>.<port>]
 *   net.if.mac[<member>.<port>]                  (FDB, if discovered)
 */
class ActionSwitches extends ActionBase {

    protected function checkInput(): bool {
        $fields = [
            'switchid' => 'string'  // hostid of the switch to focus on
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function doAction(): void {
        $switchid = $this->getInput('switchid', '');

        $boot = [
            'host'    => null,
            'members' => [],
            'ports'   => [],
            'poe'     => [],
            'fdb'     => [],
            'fleet'   => []
        ];

        // Fleet discovery powers the Host navigator. Cheap enough (3 item.get
        // calls regardless of fleet size) to run on every page load.
        try {
            $boot['fleet'] = $this->collectFleet();
        }
        catch (\Throwable $e) {
            error_log('[tcs_dashboard] collectFleet: '.$e->getMessage());
        }

        if ($switchid !== '') {
            $boot['host'] = $this->collectHost($switchid);
            try {
                $snap = (new SwitchClient())->snapshot($switchid);
                $boot = array_merge($boot, $snap);
            }
            catch (\Throwable $e) {
                error_log('[tcs_dashboard] SwitchClient: '.$e->getMessage());
            }
        }

        $data = [
            'title'    => _('TCS Switch Port Status'),
            'switchid' => $switchid,
            'boot'     => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Switch Port Status'));
        $this->setResponse($response);
    }

    private function collectHost(string $hostid): ?array {
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['ip', 'main', 'type'],
            'hostids'          => [$hostid]
        ]);
        if (!$hosts) return null;

        $h  = $hosts[0];
        $ip = '';
        foreach ($h['interfaces'] ?? [] as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1) {
                $ip = $iface['ip'];
                break;
            }
        }

        return [
            'hostid'       => $h['hostid'],
            'host'         => $h['host'],
            'visible_name' => $h['name'],
            'ip'           => $ip,
            'status'       => ((int) $h['status'] === 0) ? 'monitored' : 'not monitored',
            'maintenance'  => (int) ($h['maintenance_status'] ?? 0)
        ];
    }

    /**
     * Discover the switch fleet and roll up per-host port/PoE counters in a
     * shape the existing HostNavigator widget consumes (SWITCH_SITES schema).
     *
     * Switch identity: a host is treated as a switch iff it has at least one
     * item whose key starts with `stacking.member[` (the EXOS template's
     * discovery signature). This is cheaper and more accurate than matching
     * on template name and stays correct when templates are renamed.
     *
     * Site grouping: hosts are bucketed by their first host group whose name
     * starts with `Site/` (integration-plan §2a convention). Everything else
     * falls into a synthetic "Unsited" bucket so it still renders.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectFleet(): array {
        // Step 1: discover switch hostids via stacking.member items.
        $stackingItems = API::Item()->get([
            'output'      => ['hostid', 'key_', 'lastvalue'],
            'search'      => ['key_' => 'stacking.member['],
            'startSearch' => true
        ]) ?: [];

        if (!$stackingItems) return [];

        $hostids = [];
        $memberCount = [];
        foreach ($stackingItems as $it) {
            $hid = (string) $it['hostid'];
            $hostids[$hid] = true;
            $memberCount[$hid] = ($memberCount[$hid] ?? 0) + 1;
        }
        $hostids = array_keys($hostids);

        // Step 2: pull port + PoE state for every switch in one go each.
        $portItems = API::Item()->get([
            'output'      => ['hostid', 'key_', 'lastvalue'],
            'hostids'     => $hostids,
            'search'      => ['key_' => 'net.if.status[ifOperStatus.'],
            'startSearch' => true
        ]) ?: [];

        $poeItems = API::Item()->get([
            'output'      => ['hostid', 'key_', 'lastvalue'],
            'hostids'     => $hostids,
            'search'      => ['key_' => 'snmp.interfaces.poe.dstatus['],
            'startSearch' => true
        ]) ?: [];

        $counters = [];
        foreach ($hostids as $hid) {
            $counters[$hid] = ['ports' => 0, 'up' => 0, 'down' => 0, 'poe' => 0];
        }
        foreach ($portItems as $it) {
            $hid = (string) $it['hostid'];
            $counters[$hid]['ports']++;
            $s = (int) $it['lastvalue'];
            if ($s === 1) $counters[$hid]['up']++;
            elseif ($s === 2) $counters[$hid]['down']++;
        }
        foreach ($poeItems as $it) {
            $hid = (string) $it['hostid'];
            if ((int) $it['lastvalue'] === 3) {
                $counters[$hid]['poe']++;
            }
        }

        // Step 3: host metadata + open problem counts.
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'main'],
            'selectHostGroups' => ['name'],
            'selectInventory'  => ['model'],
            'hostids'          => $hostids,
            'preservekeys'     => true
        ]) ?: [];

        $problems = API::Problem()->get([
            'output'  => ['eventid', 'severity', 'r_eventid', 'objectid'],
            'hostids' => $hostids,
            'recent'  => false
        ]) ?: [];

        // Problems aren't reported with hostid directly — they reference
        // triggers, and a trigger can span hosts. Map back via item.get on the
        // trigger's objectid is overkill here; for the navigator badge we
        // approximate per-host counts via event.get with selectHosts.
        $problemByHost = [];
        if ($problems) {
            $eventids = array_column($problems, 'eventid');
            $events = API::Event()->get([
                'output'       => ['eventid'],
                'eventids'     => $eventids,
                'selectHosts'  => ['hostid']
            ]) ?: [];
            foreach ($events as $ev) {
                foreach ($ev['hosts'] ?? [] as $h) {
                    $hid = (string) $h['hostid'];
                    if (isset($counters[$hid])) {
                        $problemByHost[$hid] = ($problemByHost[$hid] ?? 0) + 1;
                    }
                }
            }
        }

        // Step 4: bucket by site host group, build the SWITCH_SITES payload.
        $sites = [];   // siteId => row
        foreach ($hostids as $hid) {
            $h = $hosts[$hid] ?? null;
            if (!$h) continue;

            $ip = '';
            foreach ($h['interfaces'] ?? [] as $iface) {
                if ((int) ($iface['main'] ?? 0) === 1) { $ip = $iface['ip']; break; }
            }

            $siteName = 'Unsited';
            foreach ($h['hostgroups'] ?? [] as $g) {
                if (str_starts_with((string) $g['name'], 'Site/')) {
                    $siteName = substr($g['name'], strlen('Site/'));
                    break;
                }
            }
            $siteId = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $siteName)) ?: 'unsited';

            if (!isset($sites[$siteId])) {
                $sites[$siteId] = [
                    'id'       => $siteId,
                    'name'     => $siteName,
                    'expanded' => true,
                    'problems' => 0,
                    'switches' => []
                ];
            }

            $c = $counters[$hid];
            $row = [
                'id'       => $h['host'],                 // human-readable, shown in UI
                'hostid'   => (string) $h['hostid'],      // numeric, used for navigation
                'name'     => $h['name'],
                'ip'       => $ip,
                'model'    => (string) ($h['inventory']['model'] ?? '—'),
                'members'  => max(1, (int) ($memberCount[$hid] ?? 1)),
                'ports'    => (int) $c['ports'],
                'up'       => (int) $c['up'],
                'down'     => (int) $c['down'],
                'poe'      => (int) $c['poe'],
                'cpu'      => 0,
                'mem'      => 0,
                'temp'     => 0,
                'problems' => (int) ($problemByHost[$hid] ?? 0)
            ];

            $sites[$siteId]['switches'][] = $row;
            $sites[$siteId]['problems'] += $row['problems'];
        }

        // Sort: switches alphabetically within each site, sites alphabetically
        // but pin Unsited to the bottom.
        foreach ($sites as &$site) {
            usort($site['switches'], fn($a, $b) => strcmp($a['id'], $b['id']));
        }
        unset($site);

        uasort($sites, function($a, $b) {
            if ($a['id'] === 'unsited') return 1;
            if ($b['id'] === 'unsited') return -1;
            return strcmp($a['name'], $b['name']);
        });

        return array_values($sites);
    }
}
