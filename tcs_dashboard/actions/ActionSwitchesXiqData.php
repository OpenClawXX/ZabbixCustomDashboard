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
        $ret = $this->validateInput(['switchid' => 'required|string']);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        $hostid = (string) $this->getInput('switchid');

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

        $token = $this->xiqToken();
        if ($token === null) {
            $payload['reason'] = 'no_token';
            $this->respond($payload);
            return;
        }

        $hostMeta = $this->collectHostMeta($hostid);
        if ($hostMeta === null) {
            $payload['reason'] = 'unknown_host';
            $this->respond($payload);
            return;
        }
        $payload['host'] = $hostMeta;

        try {
            $fleet  = XIQFleetClient::fromToken($token);
            $device = $fleet->findDevice($hostMeta['hostname'], $hostMeta['serial']);
            $payload['rateLimit'] = [
                'remaining' => $fleet->getRateLimitRemaining(),
                'reset'     => $fleet->getRateLimitReset()
            ];
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq lookup failed: ' . $e->getMessage());
            $payload['reason'] = 'lookup_failed';
            $this->respond($payload);
            return;
        }

        if (!$device || empty($device['id'])) {
            $payload['reason'] = 'not_in_xiq';
            $this->respond($payload);
            return;
        }

        $payload['device'] = $this->shapeDevice($device);
        $deviceId = (int) $device['id'];

        // Clients + events use the per-device XIQClient since it already
        // normalises the shapes the dashboard wants. The two calls are
        // independent — order them so the cheaper /alarms scan doesn't sit
        // behind /clients on a busy switch.
        $client = XIQClient::fromToken($token);

        try {
            $payload['events'] = $client->getDeviceAlarms($deviceId, 100, 168);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq alarms failed: ' . $e->getMessage());
        }

        try {
            $clients = $client->getClients($deviceId);
            $payload['clients'] = array_map(fn($c) => $this->shapeClient($c), $clients);
        } catch (\Throwable $e) {
            // Many switches report zero wireless clients — non-fatal.
            error_log('[tcs_dashboard] xiq clients failed: ' . $e->getMessage());
        }

        try {
            $payload['alerts'] = $this->collectAlerts($fleet, $hostMeta['hostname']);
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] xiq alerts failed: ' . $e->getMessage());
        }

        $payload['ok'] = true;
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
