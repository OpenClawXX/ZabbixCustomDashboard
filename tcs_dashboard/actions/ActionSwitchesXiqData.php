<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\TcsDashboard\Lib\XIQClient;
use Modules\TcsDashboard\Lib\XIQFleetClient;

/**
 * GET zabbix.php?action=tcs.switches.xiq.data&switchid=NNN
 *
 * Looks the Zabbix switch host up in ExtremeCloud IQ by hostname + serial
 * and returns the matching XIQ device's connected clients, recent
 * device-scoped alarm history, and any unacknowledged global alerts whose
 * keyword matches the switch hostname.
 *
 * Lookup strategy:
 *   1. Pull the host's technical name + inventory.serialno_a from Zabbix.
 *   2. XIQFleetClient::findDevice() — one /devices call filtered by sns
 *      (most reliable) then hostnames. Cached 5 min in APCu under a key
 *      keyed on the hostname+serial pair.
 *   3. With the XIQ deviceId in hand, fan out three XIQ calls:
 *        GET /clients/active?deviceIds=<id>      → connected clients
 *        GET /devices/<id>/alarms                → 7-day event log
 *        GET /alerts?keyword=<hostname>           → open alerts
 *
 * Response shape mirrors what the React TabXiq component renders:
 *   {
 *     ok: bool,
 *     reason?: string,            // when ok=false (e.g. "no_token")
 *     device: {id, hostname, model, mac, ip, connected, ...} | null,
 *     clients: [{mac, host, user, ssid, ...}],
 *     events:  [{id, ts, severity, category, message}],
 *     alerts:  [{id, ts, severity, source, summary, acknowledged}],
 *     rateLimit: { remaining, reset }
 *   }
 */
class ActionSwitchesXiqData extends ActionDataBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'switchid' => 'required|string',
            'debug'    => 'string'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $hostid = (string) $this->getInput('switchid');
        $debug  = $this->getInput('debug', '') !== '';

        $payload = [
            'ok'        => false,
            'reason'    => '',
            'device'    => null,
            'clients'   => [],
            'events'    => [],
            'alerts'    => [],
            'rateLimit' => ['remaining' => null, 'reset' => 0],
            'ts'        => time()
        ];
        $diag = [
            'serial_used'       => '',
            'hostname_used'     => '',
            'lookup_match_by'   => '',
            'device_raw_keys'   => [],
            'clients_total'     => null,
            'clients_returned'  => null,
            'clients_first_keys'=> [],
            'events_total'      => null,
            'alerts_total'      => null,
            'errors'            => []
        ];

        $token = $this->xiqToken();
        if ($token === null) {
            $payload['reason'] = 'no_token';
            $this->respond($payload);
            return;
        }

        $hostMeta = $this->collectHostMeta($hostid);
        if ($hostMeta === null) {
            $payload['reason'] = 'unknown_host';
            if ($debug) $payload['_debug'] = $diag;
            $this->respond($payload);
            return;
        }
        $payload['host'] = $hostMeta;
        $diag['serial_used']   = $hostMeta['serial'];
        $diag['hostname_used'] = $hostMeta['hostname'];

        try {
            $fleet  = XIQFleetClient::fromToken($token);
            $device = $fleet->findDevice($hostMeta['hostname'], $hostMeta['serial']);
            $payload['rateLimit'] = [
                'remaining' => $fleet->getRateLimitRemaining(),
                'reset'     => $fleet->getRateLimitReset()
            ];
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq lookup failed: ' . $e->getMessage());
            $diag['errors'][] = 'lookup: ' . $e->getMessage();
            $payload['reason'] = 'lookup_failed';
            if ($debug) $payload['_debug'] = $diag;
            $this->respond($payload);
            return;
        }

        if (!$device || empty($device['id'])) {
            $payload['reason'] = 'not_in_xiq';
            if ($debug) $payload['_debug'] = $diag;
            $this->respond($payload);
            return;
        }

        $payload['device'] = $this->shapeDevice($device);
        $deviceId = (int) $device['id'];
        $diag['device_raw_keys'] = array_keys($device);
        $diag['lookup_match_by'] = ($hostMeta['serial'] !== '' && isset($device['serial_number']) && (string) $device['serial_number'] === $hostMeta['serial'])
            ? 'serial'
            : 'hostname';

        $isSwitch = stripos((string) ($device['device_function'] ?? ''), 'switch') !== false;
        $payload['notes'] = [];

        // Clients. Two endpoints are in play:
        //   • /clients/active (main XIQ API) — wireless-association centric.
        //     Returns the AP-attached station list. Used for wireless
        //     devices (the original AP-detail use case).
        //   • Platform ONE /wired/grid — the wired-client equivalent, on a
        //     separate base URL with its own token scope. This is the ONLY
        //     public endpoint that returns the switch-attached station
        //     list, and it's required for switches (XIQ's wired FDB is
        //     not exposed on /clients/active even though the console
        //     shows the data).
        // We always try /clients/active first since it works for both APs
        // and returns 0 cheaply for switches, then for switches fall back
        // to /wired/grid to fill in the wired stations.
        try {
            $rawClients = $fleet->getJson('/clients/active', [
                'deviceIds' => $deviceId,
                'views'     => 'FULL',
                'page'      => 1,
                'limit'     => 100
            ]);
            $rows = $rawClients['data'] ?? (is_array($rawClients) && array_values($rawClients) === $rawClients ? $rawClients : []);
            $diag['clients_total']    = (int) ($rawClients['total_count'] ?? count(is_array($rows) ? $rows : []));
            $diag['clients_returned'] = is_array($rows) ? count($rows) : 0;
            if (is_array($rows) && $rows) {
                $diag['clients_first_keys'] = array_keys($rows[0]);
                if ($debug) $diag['clients_first_row'] = $rows[0];
            }
            $payload['clients'] = $this->shapeClientsRaw(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq clients failed: ' . $e->getMessage());
            $diag['errors'][] = 'clients: ' . $e->getMessage();
        }

        if ($isSwitch) {
            try {
                $wired = $fleet->getWiredClientsForDevice($deviceId, 100, 5);
                $diag['wired_clients_total']    = count($wired);
                $diag['wired_clients_returned'] = count($wired);
                if ($wired) $diag['wired_first_keys'] = array_keys($wired[0]);
                if ($debug && $wired) $diag['wired_first_row'] = $wired[0];
                $payload['clients'] = array_merge($payload['clients'], $this->shapeWiredClients($wired));
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '403') !== false || stripos($msg, 'AUTH_ACCESS_DENIED') !== false) {
                    $payload['notes']['clients'] = 'XIQ returned 403 on /wired/grid (Platform ONE Client service). The API token is missing the wired-client scope — add it under XIQ Administration → API Access Tokens.';
                } else {
                    error_log('[tcs_dashboard] xiq wired clients failed: ' . $msg);
                }
                $diag['errors'][] = 'wired_clients: ' . $msg;
            }
        }

        // Events: widen the window to 30 days and paginate up to 5 pages
        // (500 alarms) so we don't drop older entries. Most switches
        // generate a handful of events per day at most; 500 is a generous
        // ceiling without dragging the request into multi-second territory.
        try {
            $payload['events'] = $this->collectEventsPaged($token, $deviceId, /*windowHours*/ 720, /*pages*/ 5);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq alarms failed: ' . $e->getMessage());
            $diag['errors'][] = 'events: ' . $e->getMessage();
        }
        $diag['events_total'] = count($payload['events']);

        try {
            $payload['alerts'] = $this->collectAlerts($fleet, $hostMeta['hostname']);
            $diag['alerts_total'] = count($payload['alerts']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // /alerts is gated by the "Alert Read" token scope. Surface a
            // user-actionable note instead of just an error string so the
            // tab can explain what's missing.
            if (stripos($msg, '403') !== false || stripos($msg, 'AUTH_ACCESS_DENIED') !== false) {
                $payload['notes']['alerts'] = 'XIQ returned 403 on /alerts — the API token is missing the "Alert" read scope. Edit the token under XIQ Administration → API Access Tokens.';
            } else {
                error_log('[tcs_dashboard] xiq alerts failed: ' . $msg);
            }
            $diag['errors'][] = 'alerts: ' . $msg;
        }

        $payload['ok'] = true;
        if ($debug) $payload['_debug'] = $diag;
        $this->respond($payload);
    }

    private function respond(array $payload): void {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES)
        ]));
    }

    /**
     * Pull the hostname + inventory.serialno_a + management IP for one host.
     * Returns null when the host isn't visible to this user.
     *
     * @return array{hostid:string, hostname:string, visible_name:string, serial:string, ip:string}|null
     */
    private function collectHostMeta(string $hostid): ?array {
        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name'],
            'selectInventory'  => ['serialno_a', 'serialno_b'],
            'selectInterfaces' => ['ip', 'main', 'type'],
            'hostids'          => [$hostid]
        ]) ?: [];
        if (!$hosts) return null;
        $h = $hosts[0];

        $serial = trim((string) ($h['inventory']['serialno_a'] ?? ''));
        if ($serial === '') {
            $serial = trim((string) ($h['inventory']['serialno_b'] ?? ''));
        }

        $ip = '';
        foreach ($h['interfaces'] ?? [] as $iface) {
            if ((int) ($iface['main'] ?? 0) === 1 && ($iface['ip'] ?? '') !== '') {
                $ip = (string) $iface['ip'];
                break;
            }
        }

        return [
            'hostid'       => (string) $h['hostid'],
            'hostname'     => (string) $h['host'],
            'visible_name' => (string) $h['name'],
            'serial'       => $serial,
            'ip'           => $ip
        ];
    }

    /** XIQ device row → the slim shape TabXiq renders in its header card. */
    private function shapeDevice(array $d): array {
        // BASIC view fields per /devices?views=BASIC. Field names vary across
        // XIQ build numbers — accept the common aliases.
        $first = function (array $r, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) return $r[$k];
            }
            return $default;
        };
        $mac = (string) $first($d, ['mac_address', 'macAddress', 'mac'], '');
        if ($mac !== '' && strpos($mac, ':') === false) {
            $mac = XIQClient::macInsertColons($mac);
        }
        return [
            'id'            => (int) ($d['id'] ?? 0),
            'hostname'      => (string) $first($d, ['hostname', 'host_name', 'name']),
            'serial'        => (string) $first($d, ['serial_number', 'serialNumber', 'serial']),
            'model'         => (string) $first($d, ['product_type', 'productType', 'model']),
            'function'      => (string) $first($d, ['device_function', 'deviceFunction', 'function']),
            'firmware'      => (string) $first($d, ['software_version', 'softwareVersion', 'firmware']),
            'mac'           => $mac,
            'ip'            => (string) $first($d, ['ip_address', 'ipAddress', 'ip']),
            'connected'     => (bool)   $first($d, ['connected', 'is_connected'], false),
            'last_connect'  => (int)    $first($d, ['last_connect_time_ms', 'lastConnectTimeMs', 'last_connect'], 0),
            'policy_id'     => (int)    $first($d, ['network_policy_id', 'networkPolicyId'], 0),
            'policy_name'   => (string) $first($d, ['network_policy_name', 'networkPolicyName']),
            'site_id'       => (int)    $first($d, ['site_id', 'siteId'], 0),
            'location'      => (string) $first($d, ['location', 'location_name', 'locationName'])
        ];
    }

    /** Project raw /clients/active rows into the slim shape TabXiq renders.
     *  Tolerant of XIQ field-name churn — wired clients on a switch may
     *  use `client_mac` / `wired_mac` instead of `mac_address`, and we
     *  must not silently drop rows just because the wireless field is
     *  missing. Only rows with NO MAC under any alias are skipped.
     *
     *  @param array<int, array<string,mixed>> $rows
     *  @return array<int, array<string,mixed>>
     */
    private function shapeClientsRaw(array $rows): array {
        $first = function (array $r, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) return $r[$k];
            }
            return $default;
        };
        $out = [];
        foreach ($rows as $c) {
            if (!is_array($c)) continue;
            $macRaw = (string) $first($c, ['mac_address', 'mac', 'client_mac', 'station_mac', 'wired_mac', 'macAddress', 'clientMac']);
            if ($macRaw === '') continue;
            $mac = strpos($macRaw, ':') === false ? XIQClient::macInsertColons($macRaw) : $macRaw;
            $connType = (int) $first($c, ['client_connection_type', 'clientConnectionType', 'connection_type'], 0);
            $band = (string) $first($c, ['band', 'frequency']);
            if ($band === '') {
                $proto = strtolower((string) $first($c, ['mac_protocol', 'macProtocol', 'protocol']));
                if (strpos($proto, '2.4') !== false || strpos($proto, '2_4') !== false) $band = '2.4G';
                elseif (strpos($proto, '5') !== false) $band = '5G';
                elseif (strpos($proto, '6') !== false) $band = '6G';
            }
            $out[] = [
                'mac'      => $mac,
                'host'     => (string) $first($c, ['host_name', 'hostname', 'device_name', 'hostName']),
                'ip'       => (string) $first($c, ['ip_address', 'ipAddress', 'ip']),
                'user'     => (string) $first($c, ['user_name', 'username', 'userName']),
                'role'     => (string) $first($c, ['user_profile_name', 'user_profile', 'userProfileName', 'userProfile']),
                'ssid'     => (string) $first($c, ['ssid']),
                'vlan'     => (int)    $first($c, ['vlan', 'vlan_id', 'vlanId'], 0),
                'rssi'     => (int)    $first($c, ['rssi'], 0),
                'snr'      => (int)    $first($c, ['snr'], 0),
                'health'   => (int)    $first($c, ['client_health_status', 'client_health', 'clientHealthStatus'], 0),
                'duration' => (int)    $first($c, ['connection_duration', 'connected_seconds', 'connectionDuration'], 0),
                'os'       => trim(((string) $first($c, ['os_type', 'osType', 'os']))
                                  . ' '
                                  . ((string) $first($c, ['os_version', 'osVersion']))),
                'protocol' => (string) $first($c, ['mac_protocol', 'macProtocol', 'protocol']),
                'band'     => $band,
                'wired'    => $connType === 2,
                'port'     => (string) $first($c, ['ifname', 'port_name', 'switch_port', 'switchPort'])
            ];
        }
        return $out;
    }

    /** Project Platform ONE /wired/grid rows into the same slim shape the
     *  Clients sub-table renders. Different field names than /clients/active
     *  — see the WiredDataInner schema in the spec. */
    private function shapeWiredClients(array $rows): array {
        $out = [];
        foreach ($rows as $c) {
            if (!is_array($c)) continue;
            $macRaw = (string) ($c['mac'] ?? $c['client_mac'] ?? '');
            if ($macRaw === '') continue;
            $mac = strpos($macRaw, ':') === false ? XIQClient::macInsertColons($macRaw) : $macRaw;
            $out[] = [
                'mac'      => $mac,
                'host'     => (string) ($c['client_hostname'] ?? ''),
                'ip'       => (string) ($c['client_ip'] ?? $c['ipv4'] ?? ''),
                'user'     => (string) ($c['username'] ?? ''),
                'role'     => (string) ($c['instant_port_profile'] ?? ''),
                'ssid'     => '',
                'vlan'     => (int)    ($c['vlan'] ?? 0),
                'rssi'     => 0,
                'snr'      => 0,
                'health'   => 0,
                'duration' => 0,
                'os'       => (string) ($c['operating_system'] ?? ''),
                'protocol' => '',
                'band'     => '',
                'wired'    => true,
                'port'     => (string) ($c['port_number'] ?? ''),
                'switch'   => (string) ($c['switch_name'] ?? ''),
                'status'   => strtoupper((string) ($c['connection_status'] ?? 'CONNECTED'))
            ];
        }
        return $out;
    }

    /** Slim a normalised XIQClient::getClients() row down to the columns the
     *  Clients sub-table renders. Drops the heavy raw blob. */
    private function shapeClient(array $c): array {
        return [
            'mac'      => (string) ($c['mac']      ?? ''),
            'host'     => (string) ($c['hostname'] ?? ''),
            'ip'       => (string) ($c['ip']       ?? ''),
            'user'     => (string) ($c['username'] ?? ''),
            'role'     => (string) ($c['user_profile'] ?? ''),
            'ssid'     => (string) ($c['ssid']     ?? ''),
            'vlan'     => (int)    ($c['vlan']     ?? 0),
            'rssi'     => (int)    ($c['rssi']     ?? 0),
            'snr'      => (int)    ($c['snr']      ?? 0),
            'health'   => (int)    ($c['client_health'] ?? 0),
            'duration' => (int)    ($c['connected_seconds'] ?? 0),
            'os'       => trim(((string) ($c['os_type'] ?? '')) . ' ' . ((string) ($c['os_version'] ?? ''))),
            'protocol' => (string) ($c['protocol'] ?? ''),
            'band'     => (string) ($c['band']     ?? '')
        ];
    }

    /**
     * Paginate /devices/{id}/alarms across multiple pages so we don't drop
     * entries past the first 100. Each call goes through XIQClient so the
     * row shape stays compatible with the rest of the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectEventsPaged(string $token, int $deviceId, int $windowHours, int $maxPages): array {
        $client = XIQClient::fromToken($token);
        // XIQClient's helper handles page 1; if it returned a full 100 we
        // walk forward until either an empty page or maxPages.
        $page1 = $client->getDeviceAlarms($deviceId, 100, $windowHours);
        if (count($page1) < 100) return $page1;

        $all   = $page1;
        $endMs = (int) (microtime(true) * 1000);
        $startMs = $endMs - ($windowHours * 3600 * 1000);
        for ($p = 2; $p <= $maxPages; $p++) {
            $raw = XIQFleetClient::fromToken($token)->getJson("/devices/{$deviceId}/alarms", [
                'page'      => $p,
                'limit'     => 100,
                'startTime' => $startMs,
                'endTime'   => $endMs
            ]);
            $rows = $raw['data'] ?? (is_array($raw) && array_values($raw) === $raw ? $raw : []);
            if (!is_array($rows) || !$rows) break;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $tsRaw = $r['raised_time'] ?? $r['event_time'] ?? $r['created_time'] ?? $r['timestamp'] ?? 0;
                $ts = is_numeric($tsRaw) ? (int) $tsRaw : (int) strtotime((string) $tsRaw);
                if ($ts > 9999999999) $ts = intdiv($ts, 1000);
                $sevRaw = strtoupper((string) ($r['severity'] ?? ''));
                $sevMap = ['CRITICAL'=>'disaster','EMERGENCY'=>'disaster','ALERT'=>'disaster',
                           'MAJOR'=>'high','ERROR'=>'high','MINOR'=>'warning','WARNING'=>'warning',
                           'NOTICE'=>'info','INFO'=>'info','INFORM'=>'info'];
                $all[] = [
                    'id'       => (string) ($r['id'] ?? $r['alarm_id'] ?? ('xiq-'.$ts.'-'.md5((string) ($r['description'] ?? '')))),
                    'message'  => (string) ($r['description'] ?? $r['summary'] ?? $r['name'] ?? 'XIQ alarm'),
                    'severity' => $sevMap[$sevRaw] ?? 'warning',
                    'clock'    => $ts > 0 ? $ts : time(),
                    'value'    => 1,
                    'category' => (string) ($r['category'] ?? ''),
                    'raw'      => $r
                ];
            }
            if (count($rows) < 100) break;
        }
        return $all;
    }

    /**
     * Open XIQ alerts referencing the switch. /alerts has no deviceId param —
     * we filter by `keyword` (XIQ matches against summary / source / etc.)
     * using the host's technical name. Returns the freshest 50 from the last
     * 7 days, normalised for the React table.
     *
     * @return array<int, array<string,mixed>>
     */
    private function collectAlerts(XIQFleetClient $fleet, string $hostname): array {
        if ($hostname === '') return [];
        $end   = (int) (microtime(true) * 1000);
        $start = $end - (7 * 86400 * 1000);
        $resp  = $fleet->getJson('/alerts', [
            'page'      => 1,
            'limit'     => 50,
            'startTime' => $start,
            'endTime'   => $end,
            'keyword'   => $hostname,
            'sortField' => 'CREATE_TIME',
            'order'     => 'DESC'
        ]);
        $rows = $resp['data'] ?? (is_array($resp) && array_values($resp) === $resp ? $resp : []);
        if (!is_array($rows)) return [];

        static $sevMap = [
            1 => 'disaster',  // CRITICAL per XIQ docs
            2 => 'warning',
            3 => 'info'
        ];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $tsMs = (int) ($r['createdTime'] ?? $r['create_time'] ?? $r['timestamp'] ?? 0);
            if ($tsMs > 9999999999) $tsMs = intdiv($tsMs, 1000);
            $sevId = (int) ($r['severityId'] ?? $r['severity_id'] ?? 0);
            $out[] = [
                'id'           => (string) ($r['id'] ?? $r['alertId'] ?? ''),
                'ts'           => $tsMs > 0 ? $tsMs : time(),
                'severity'     => $sevMap[$sevId] ?? 'warning',
                'source'       => (string) ($r['source'] ?? $r['sourceName'] ?? ''),
                'category'     => (string) ($r['category'] ?? $r['categoryName'] ?? ''),
                'summary'      => (string) ($r['summary'] ?? $r['description'] ?? $r['message'] ?? ''),
                'acknowledged' => (bool)   ($r['acknowledged'] ?? false)
            ];
        }
        return $out;
    }

    /**
     * Same {$XIQ_API_TOKEN} → {$XIQ_TOKEN} fallback ActionXiqData uses.
     * Token must be a non-secret global macro so it's readable from PHP.
     */
    private function xiqToken(): ?string {
        foreach (['{$XIQ_API_TOKEN}', '{$XIQ_TOKEN}'] as $name) {
            $rows = API::UserMacro()->get([
                'output'      => ['macro', 'value'],
                'globalmacro' => true,
                'filter'      => ['macro' => $name]
            ]) ?: [];
            $v = trim((string) ($rows[0]['value'] ?? ''));
            if ($v !== '') return $v;
        }
        return null;
    }
}
