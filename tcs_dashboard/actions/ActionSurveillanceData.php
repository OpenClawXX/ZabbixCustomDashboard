<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.surveillance.data
 *
 * Live snapshot for the Surveillance / Milestone XProtect NOC view. Reads
 * items off hosts running the "Milestone XProtect by HTTP" template
 * (one host per XProtect site) plus the per-camera Zabbix hosts the Site
 * template's host_prototype creates (Discovered hosts/Milestone Cameras,
 * each running "Milestone Camera by Direct Polling").
 *
 * Output shape mirrors the window globals that nvr-overview.jsx already
 * consumes (MILESTONE, SITES, SERVERS, CAMERAS, VMS_ALARMS, FLEET_HISTORY);
 * surveillance-bridge.jsx is responsible for the actual window.* assignment.
 *
 * This is a first wiring pass — fields the template doesn't yet supply
 * (storage TB, evidence locks, Smart Client sessions, archive lag) are
 * returned as null so the bridge can fall through to mock values.
 */
class ActionSurveillanceData extends ActionDataBase {

    /** Template name that marks a Milestone XProtect site host. */
    private const SITE_TEMPLATE = 'Milestone XProtect by HTTP';

    /** Template name on each per-camera Zabbix host. */
    private const CAMERA_TEMPLATE = 'Milestone Camera by Direct Polling';

    /** Optional site host-group prefix (matches ActionGlobalData). */
    private const SITE_GROUP_PREFIX = 'Site/';

    /** Item keys we read directly off the Milestone site host. */
    private const SITE_KEYS = [
        'siteName'      => 'milestone.site.name',
        'siteVersion'   => 'milestone.site.version',
        'physicalMem'   => 'milestone.site.physicalmemory',
        'handshakeAge'  => 'milestone.site.handshake.age',
        'lastHandshake' => 'milestone.site.lasthandshake',
        'licenseRaw'    => 'milestone.license.get'
    ];

    protected function checkInput(): bool {
        $ret = $this->validateInput(['hostid' => 'string']);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $payload = $this->collect($this->getInput('hostid', ''));
        $this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload)]));
    }

    /**
     * Build the full surveillance boot payload.
     */
    public function collect(string $active_hostid = ''): array {
        $site_hosts = $this->findSiteHosts();
        if (!$site_hosts) {
            return $this->emptyPayload();
        }

        $site_host_ids = array_keys($site_hosts);

        // ── Site-host items (license, RS LLD, camera LLD per-field) ──
        $site_items = $this->collectSiteItems($site_host_ids);

        // ── Per-camera Zabbix hosts (ICMP, vendor SNMP) ──
        $cam_hosts = $this->findCameraHosts();

        // ── Open problems on the whole fleet (site hosts + per-camera hosts) ──
        $all_host_ids = array_merge($site_host_ids, array_keys($cam_hosts));
        $problems     = $this->collectProblems($all_host_ids);

        $milestone = $this->buildMilestoneSummary($site_hosts, $site_items, $cam_hosts, $problems);
        $sites     = $this->buildSites($site_hosts, $site_items, $cam_hosts, $problems);
        $servers   = $this->buildServers($site_hosts, $site_items);
        $cameras   = $this->buildCameras($site_hosts, $site_items, $cam_hosts);
        $alarms    = $this->buildAlarms($problems);
        $history   = $this->buildFleetHistory($all_host_ids, $cameras);

        return [
            'milestone'    => $milestone,
            'sites'        => $sites,
            'servers'      => $servers,
            'cameras'      => $cameras,
            'alarms'       => $alarms,
            'fleetHistory' => $history,
            'ts'           => time()
        ];
    }

    private function emptyPayload(): array {
        return [
            'milestone'    => null,
            'sites'        => [],
            'servers'      => [],
            'cameras'      => [],
            'alarms'       => [],
            'fleetHistory' => null,
            'ts'           => time()
        ];
    }

    /* --------------------------------------------------------------------- */

    /** Hosts that link the Milestone XProtect site template. */
    private function findSiteHosts(): array {
        $tpls = $this->safeGet(fn() => API::Template()->get([
            'output' => ['templateid', 'host'],
            'filter' => ['host' => [self::SITE_TEMPLATE]]
        ]));
        if (!$tpls) return [];

        return $this->safeGet(fn() => API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['ip', 'main', 'available'],
            'selectHostGroups' => ['groupid', 'name'],
            'templateids'      => array_column($tpls, 'templateid'),
            'monitored_hosts'  => true,
            'preservekeys'     => true
        ]));
    }

    /** Per-camera Zabbix hosts (one per Camera-{HW.ID} host_prototype). */
    private function findCameraHosts(): array {
        $tpls = $this->safeGet(fn() => API::Template()->get([
            'output' => ['templateid', 'host'],
            'filter' => ['host' => [self::CAMERA_TEMPLATE]]
        ]));
        if (!$tpls) return [];

        return $this->safeGet(fn() => API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectInterfaces' => ['ip', 'main', 'available'],
            'selectTags'       => 'extend',
            'templateids'      => array_column($tpls, 'templateid'),
            'monitored_hosts'  => true,
            'preservekeys'     => true
        ]));
    }

    /**
     * Pull every Milestone item on the given site hosts in one call. Returns
     *   [hostid => [
     *       site:    [logical => lastvalue, ...],
     *       rs:      [rsId => [field => value, ...]],
     *       cam:     [camId => [field => value, ...]],
     *   ]]
     */
    private function collectSiteItems(array $host_ids): array {
        $items = $this->safeGet(fn() => API::Item()->get([
            'output'  => ['itemid', 'hostid', 'key_', 'lastvalue', 'value_type', 'lastclock'],
            'hostids' => $host_ids,
            'search'  => ['key_' => 'milestone.'],
            'startSearch' => true,
            'monitored' => true,
            'webitems' => false
        ]));

        $out = [];
        $key_to_site_logical = array_flip(self::SITE_KEYS);

        foreach ($items as $it) {
            $hid = (string) $it['hostid'];
            $key = (string) $it['key_'];
            $val = $it['lastvalue'] ?? '';
            $out[$hid] ??= ['site' => [], 'rs' => [], 'cam' => []];

            // Direct site-level scalars
            if (isset($key_to_site_logical[$key])) {
                $out[$hid]['site'][$key_to_site_logical[$key]] = $val;
                continue;
            }

            // Per-RS items: milestone.rs.<field>[<rsId>]
            if (preg_match('/^milestone\.rs\.([a-z.]+)\[([^\]]+)\]$/i', $key, $m)) {
                $field = $m[1]; $rs_id = trim($m[2], '"\'');
                $out[$hid]['rs'][$rs_id][$field] = $val;
                continue;
            }

            // Per-camera items: milestone.cam.<field>[<camId>] (incl. ess.*)
            if (preg_match('/^milestone\.cam\.([a-z.]+)\[([^\]]+)\]$/i', $key, $m)) {
                $field = $m[1]; $cam_id = trim($m[2], '"\'');
                $out[$hid]['cam'][$cam_id][$field] = $val;
                continue;
            }
        }
        return $out;
    }

    /* --------------------------------------------------------------------- */

    /**
     * Top-of-page summary tile (MILESTONE global). At single-site installs
     * the fields are pulled off the one site host. Multi-site installs roll
     * up across all hosts (license/storage counts not yet implemented —
     * those need history.get over the licenseOverviewAll item).
     */
    private function buildMilestoneSummary(array $site_hosts, array $site_items, array $cam_hosts, array $problems): array {
        // Pick the first site host as the "primary" (Milestone is typically
        // single-mgmt-server per environment). If multiple, the rest still
        // count toward server / camera totals below.
        $primary_hid = (string) array_key_first($site_hosts);
        $primary     = $site_hosts[$primary_hid] ?? [];
        $primary_si  = $site_items[$primary_hid]['site'] ?? [];

        // Roll up RS counts across all site hosts.
        $rs_total = 0; $rs_online = 0;
        foreach ($site_items as $si) {
            foreach ($si['rs'] ?? [] as $rs) {
                $rs_total++;
                $enabled = strtolower((string) ($rs['enabled'] ?? ''));
                $age     = (int) ($rs['handshake.age'] ?? 0);
                if ($enabled === 'true' && $age > 0 && $age < 300) $rs_online++;
            }
        }

        // Cameras: count enabled and worst-state.
        $cam_total = 0; $cam_used = 0;
        foreach ($site_items as $si) {
            foreach ($si['cam'] ?? [] as $cam) {
                $cam_total++;
                if (strtolower((string) ($cam['enabled'] ?? '')) === 'true') $cam_used++;
            }
        }

        // Active VMS alarms = open problems on site hosts (camera-host
        // problems show in the per-site / per-camera rollups).
        $active_alarms = 0; $ack = 0;
        foreach ($problems as $p) {
            $active_alarms++;
            if ((int) $p['acknowledged'] === 1) $ack++;
        }

        // License JSON parse — fields { totalLicenses, activatedLicenses,
        // licensedHardwareDeviceCount, ... } per Milestone REST shape.
        $license = $this->parseLicense($primary_si['licenseRaw'] ?? '');

        return [
            'product'              => $license['product'] ?? 'XProtect',
            'version'              => $primary_si['siteVersion'] ?? '—',
            'managementServer'     => $primary_si['siteName'] ?? ($primary['name'] ?? '—'),
            'smtpRouted'           => null,
            'licenseDeviceTotal'   => $license['totalDevices']   ?? $cam_total,
            'licenseDeviceUsed'    => $license['activatedDevices'] ?? $cam_used,
            'licenseHwTotal'       => $license['totalHardware']  ?? null,
            'recordingServers'     => $rs_total,
            'recordingServersOnline' => $rs_online,
            'failoverServers'      => null,
            'mobileServers'        => null,
            'smartClientSessions'  => null,
            'webClientSessions'    => null,
            'activeAlarms'         => $active_alarms,
            'alarmsAck'            => $ack,
            'retentionDays'        => null,
            'storageTotalTB'       => null,
            'storageUsedTB'        => null,
            'evidenceLockSlots'    => null,
            'evidenceLockUsed'     => null
        ];
    }

    /** Best-effort license overview parse. Returns [] on garbage / empty. */
    private function parseLicense(string $raw): array {
        if ($raw === '') return [];
        $blob = json_decode($raw, true);
        if (!is_array($blob)) return [];
        // The REST shape is { array: [ { ... } ] }. Take the first row.
        $row = $blob['array'][0] ?? $blob;
        return [
            'product'           => $row['productDisplayName'] ?? null,
            'totalDevices'      => isset($row['totalLicensesForDeviceLicense']) ? (int) $row['totalLicensesForDeviceLicense'] : null,
            'activatedDevices'  => isset($row['activatedLicensesForDeviceLicense']) ? (int) $row['activatedLicensesForDeviceLicense'] : null,
            'totalHardware'     => isset($row['licensedHardwareDeviceCount']) ? (int) $row['licensedHardwareDeviceCount'] : null
        ];
    }

    /* --------------------------------------------------------------------- */

    /**
     * Per-site rollup for the dashboard's SITES tile. Bucket by site host —
     * each XProtect "site" in the template = one Zabbix host running the
     * site template. Storage capacity isn't templated yet so storageGB /
     * storageCapGB stay null.
     */
    private function buildSites(array $site_hosts, array $site_items, array $cam_hosts, array $problems): array {
        // Cross-reference camera Zabbix hosts back to their site via the
        // cam_id tag stamped by the host_prototype. cam_id matches the
        // Milestone camera GUID, so we can use it to attribute each
        // discovered host to its site host.
        $camid_to_site = [];
        foreach ($site_items as $site_hid => $bundle) {
            foreach ($bundle['cam'] ?? [] as $cam_id => $_) {
                $camid_to_site[$cam_id] = $site_hid;
            }
        }

        $problems_by_host = [];
        foreach ($problems as $p) {
            foreach ($p['hosts'] ?? [] as $h) {
                $problems_by_host[$h['hostid']] = ($problems_by_host[$h['hostid']] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($site_hosts as $hid => $h) {
            $bundle = $site_items[$hid] ?? ['site' => [], 'rs' => [], 'cam' => []];
            $name   = $bundle['site']['siteName'] ?? ($h['name'] ?: $h['host']);

            $cams = 0; $online = 0; $warn = 0; $err = 0;
            foreach ($bundle['cam'] ?? [] as $cam_id => $cam) {
                $cams++;
                $enabled = strtolower((string) ($cam['enabled'] ?? ''));
                if ($enabled !== 'true') continue;
                $status = (int) ($cam['status'] ?? 0);
                // 0 OK, 1 ESS fault, 2 ping down, 3 offline (both), -1 disabled
                if ($status === 0)      $online++;
                elseif ($status === 1)  { $online++; $warn++; }
                elseif ($status >= 2)   { $err++; }
                else                    $online++;
            }

            // Primary recording server (first RS) for the server label.
            $primary_rs = null;
            foreach ($bundle['rs'] ?? [] as $rs_id => $rs) {
                $primary_rs = $rs['hostname'] ?? $rs_id;
                break;
            }

            $out[] = [
                'name'         => $name,
                'hostid'       => $hid,
                'cams'         => $cams,
                'online'       => $online,
                'warn'         => $warn,
                'err'          => $err,
                'server'       => $primary_rs ?? '—',
                'storageGB'    => null,
                'storageCapGB' => null,
                'problems'     => $problems_by_host[$hid] ?? 0
            ];
        }

        usort($out, fn($a, $b) => $b['err'] <=> $a['err'] ?: $b['warn'] <=> $a['warn'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /* --------------------------------------------------------------------- */

    /** Recording-server tiles — one row per discovered RS across all sites. */
    private function buildServers(array $site_hosts, array $site_items): array {
        $out = [];
        foreach ($site_hosts as $hid => $h) {
            $bundle = $site_items[$hid] ?? [];
            $site_label = $bundle['site']['siteName'] ?? ($h['name'] ?: $h['host']);
            foreach ($bundle['rs'] ?? [] as $rs_id => $rs) {
                $enabled = strtolower((string) ($rs['enabled'] ?? ''));
                $age     = (int) ($rs['handshake.age'] ?? 0);
                $stale   = $age > 300;
                $out[] = [
                    'id'           => $rs['hostname'] ?? $rs_id,
                    'rsid'         => $rs_id,
                    'site'         => $site_label,
                    'role'         => 'Recording Server',
                    'os'           => null,
                    'cpu'          => null,
                    'mem'          => null,
                    'disk'         => null,
                    'raid'         => null,
                    'chans'        => null,
                    'recording'    => null,
                    'archiveLagH'  => null,
                    'agent'        => null,
                    'ip'           => null,
                    'uptimeD'      => null,
                    'lastBackup'   => null,
                    'state'        => $enabled !== 'true' ? 'err' : ($stale ? 'warn' : 'ok'),
                    'handshakeAge' => $age
                ];
            }
        }
        return $out;
    }

    /* --------------------------------------------------------------------- */

    /**
     * Camera list — one row per LLD-discovered camera. State derives from
     * milestone.cam.status[id] (0 OK / 1 ESS fault / 2 ping down / 3 both /
     * -1 disabled).
     */
    private function buildCameras(array $site_hosts, array $site_items, array $cam_hosts): array {
        // Per-Camera Zabbix host lookup by cam_id tag.
        $cam_host_by_id = [];
        foreach ($cam_hosts as $ch) {
            foreach ($ch['tags'] ?? [] as $t) {
                if (($t['tag'] ?? '') === 'cam_id' && ($t['value'] ?? '') !== '') {
                    $cam_host_by_id[$t['value']] = $ch;
                    break;
                }
            }
        }

        $out = [];
        foreach ($site_hosts as $hid => $h) {
            $bundle = $site_items[$hid] ?? [];
            $site_label = $bundle['site']['siteName'] ?? ($h['name'] ?: $h['host']);
            foreach ($bundle['cam'] ?? [] as $cam_id => $cam) {
                $status = isset($cam['status']) ? (int) $cam['status'] : null;
                $state = match (true) {
                    $status === null    => 'unknown',
                    $status === -1      => 'disabled',
                    $status === 0       => 'ok',
                    $status === 1       => 'warn',
                    $status >= 2        => 'err',
                    default             => 'unknown'
                };
                $cam_host = $cam_host_by_id[$cam_id] ?? null;
                $ip = $cam['address'] ?? '';
                if (!$ip && $cam_host) {
                    foreach ($cam_host['interfaces'] ?? [] as $i) {
                        if ((int) ($i['main'] ?? 0) === 1) { $ip = $i['ip']; break; }
                    }
                }
                $out[] = [
                    'id'        => $cam_id,
                    'name'      => $cam_host['name'] ?? ($cam['hwname'] ?? $cam_id),
                    'site'      => $site_label,
                    'loc'       => $cam['hwname'] ?? '',
                    'model'     => $cam['hwmodel'] ?? '—',
                    'res'       => null,
                    'fps'       => null,
                    'bitrate'   => null,
                    'codec'     => null,
                    'recording' => null,
                    'state'     => $state,
                    'ip'        => $ip ?: null,
                    'mac'       => $cam['mac'] ?? null,
                    'poe'       => null,
                    'server'    => null,
                    'motion12h' => null,
                    'hostid'    => $cam_host['hostid'] ?? null
                ];
            }
        }
        return $out;
    }

    /* --------------------------------------------------------------------- */

    /* --------------------------------------------------------------------- */

    /**
     * 24h sparkline arrays for the Overview "Live Ingress" tile. Returns
     * keys the bridge will overlay onto window.FLEET_HISTORY — any key
     * left null keeps the mock series so the chart still renders.
     *
     * Backed by what the templates actually expose today:
     *   - alarmsPerHour: real 30-min bucket counts from event.get on the
     *     Milestone fleet hosts (TRIGGER_VALUE_TRUE events / bucket).
     *   - camerasOnline: flat baseline at the current online count so
     *     the line isn't dead at zero. Real per-camera trend would cost
     *     2500 history.get calls; defer until we have a templated
     *     aggregate item.
     *   - Everything else (ingress Gbps, storage write MB/s, RS CPU,
     *     archive lag): null — needs OS-level items on the recording-
     *     server Windows hosts that aren't part of the Milestone HTTP
     *     template.
     *
     * @param array $host_ids   site + per-camera Zabbix hostids
     * @param array $cameras    rows from buildCameras() — used to count
     *                          current online for the camerasOnline line
     */
    private function buildFleetHistory(array $host_ids, array $cameras): array {
        $bucket_count = 48;            // 30-min buckets across 24h
        $window_secs  = 24 * 3600;
        $bucket_secs  = (int) ($window_secs / $bucket_count);

        $alarms_per_hour = array_fill(0, $bucket_count, 0);
        if ($host_ids) {
            $events = $this->safeGet(fn() => API::Event()->get([
                'output'    => ['eventid', 'clock', 'value'],
                'source'    => EVENT_SOURCE_TRIGGERS,
                'object'    => EVENT_OBJECT_TRIGGER,
                'hostids'   => $host_ids,
                'time_from' => time() - $window_secs,
                'sortfield' => ['eventid'],
                'sortorder' => 'ASC',
                'limit'     => 10000
            ]));
            $start = time() - $window_secs;
            foreach ($events as $e) {
                if ((int) $e['value'] !== TRIGGER_VALUE_TRUE) continue;
                $b = (int) (((int) $e['clock'] - $start) / $bucket_secs);
                if ($b >= 0 && $b < $bucket_count) $alarms_per_hour[$b]++;
            }
        }

        // Current online count — anything not in err state.
        $online_now = 0;
        foreach ($cameras as $c) {
            $s = $c['state'] ?? '';
            if ($s === 'ok' || $s === 'warn') $online_now++;
        }
        $cameras_online = $online_now > 0
            ? array_fill(0, $bucket_count, $online_now)
            : null;  // empty fleet → keep mock so the chart isn't a flat zero

        return [
            'totalIngressGbps'    => null,
            'storageWriteMBps'    => null,
            'recordingServersCpu' => null,
            'camerasOnline'       => $cameras_online,
            'alarmsPerHour'       => $alarms_per_hour,
            'archiveLagMin'       => null
        ];
    }

    /* --------------------------------------------------------------------- */

    /** Open problems across the Milestone fleet → VMS_ALARMS rows. */
    private function collectProblems(array $host_ids): array {
        if (!$host_ids) return [];
        $problems = $this->safeGet(fn() => API::Problem()->get([
            'output'    => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'r_eventid'],
            'recent'    => false,
            'suppressed'=> false,
            'hostids'   => $host_ids,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit'     => 100
        ]));
        $problems = array_values(array_filter(
            $problems,
            fn($p) => empty($p['r_eventid']) || (int) $p['r_eventid'] === 0
        ));

        $trigger_ids = array_unique(array_column($problems, 'objectid'));
        $trigger_hosts = $this->resolveTriggerHosts($trigger_ids);
        foreach ($problems as &$p) {
            $p['hosts'] = $trigger_hosts[$p['objectid']] ?? [];
        }
        unset($p);
        return $problems;
    }

    private function buildAlarms(array $problems): array {
        $sev_label = [0 => 'info', 1 => 'info', 2 => 'warning', 3 => 'warning', 4 => 'high', 5 => 'disaster'];
        $out = [];
        foreach ($problems as $p) {
            $h = $p['hosts'][0] ?? null;
            $host_label = $h['name'] ?? ($h['host'] ?? '—');
            $out[] = [
                'ts'   => date('H:i:s', (int) $p['clock']),
                'sev'  => $sev_label[(int) $p['severity']] ?? 'info',
                'cam'  => $host_label,
                'msg'  => $p['name'],
                'site' => '',
                'ack'  => (int) $p['acknowledged'] === 1
            ];
        }
        return $out;
    }

    /** triggerid → [{hostid, host, name}, ...] via one trigger.get. */
    private function resolveTriggerHosts(array $trigger_ids): array {
        if (!$trigger_ids) return [];
        $triggers = $this->safeGet(fn() => API::Trigger()->get([
            'output'      => ['triggerid'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'triggerids'  => array_values($trigger_ids)
        ]));
        $out = [];
        foreach ($triggers as $t) {
            $out[(string) $t['triggerid']] = $t['hosts'] ?? [];
        }
        return $out;
    }

    /** Coerce any API::*->get() result to an array, swallowing exceptions. */
    private function safeGet(callable $fn): array {
        try {
            $r = $fn();
            return is_array($r) ? $r : [];
        } catch (\Throwable $e) {
            error_log('[tcs] Surveillance API call failed: '.$e->getMessage());
            return [];
        }
    }
}
