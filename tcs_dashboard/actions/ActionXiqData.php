<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;

/**
 * GET zabbix.php?action=tcs.xiq.data
 *
 * Returns the rollup payload consumed by xiq-bridge.jsx (XIQ_TOTALS, XIQ_SITES,
 * XIQ_BANDS, XIQ_SSIDS, XIQ_PROBLEM_APS, XIQ_CHANNEL_GRID, XIQ_CLIENT_MIX,
 * XIQ_THROUGHPUT, XIQ_FIRMWARE, XIQ_ROAMING, XIQ_EVENTS).
 *
 * Iteration 1: synthetic data lifted from the design bundle so the page
 * renders end-to-end. Iteration 2 will swap each section for live calls to
 * Modules\TcsDashboard\Lib\XIQClient + Zabbix API::Host/Problem joins.
 */
class ActionXiqData extends ActionDataBase {

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $payload = self::syntheticPayload() + ['ts' => time()];
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        ]));
    }

    /** Shape consumed by xiq-bridge.jsx — also used by ActionXiq for SSR boot. */
    public static function syntheticPayload(): array {
        return [
            'totals'      => self::totals(),
            'sites'       => self::sites(),
            'bands'       => self::bands(),
            'ssids'       => self::ssids(),
            'problemAps'  => self::problemAps(),
            'channelGrid' => self::channelGrid(),
            'clientMix'   => self::clientMix(),
            'throughput'  => self::throughput(),
            'firmware'    => self::firmware(),
            'roaming'     => self::roaming(),
            'events'      => self::events()
        ];
    }

    public static function totals(): array {
        return [
            'aps'         => ['total' => 1184, 'online' => 1158, 'offline' => 18, 'critical' => 4, 'idle' => 4],
            'clients'     => ['total' => 9264, 'dot11ax' => 6418, 'dot11ac' => 2310, 'legacy' => 536],
            'throughput'  => ['agg_gbps' => 14.62, 'peak_gbps' => 22.41, 'ingress_gbps' => 9.18, 'egress_gbps' => 5.44],
            'ssids'       => ['total' => 8, 'broadcast' => 6],
            'rfHealth'    => ['score' => 86, 'target' => 90],
            'firmware'    => ['compliant' => 1138, 'behind' => 41, 'ahead' => 5, 'target' => '32.7.0.5'],
            'controllers' => ['region' => 'us-east-2', 'instance' => 'xiq-tcs-prod', 'lastSync' => '12s ago']
        ];
    }

    private static function sites(): array {
        return [
            ['id' => 'BHS', 'name' => 'Bryant High School',         'aps' => 96, 'online' => 93, 'util' => 71, 'clients' => 1124, 'sev' => 'warning',  'top' => 'BHS-23-Cafe LAN down'],
            ['id' => 'CHS', 'name' => 'Central High School',        'aps' => 84, 'online' => 83, 'util' => 64, 'clients' =>  982, 'sev' => 'warning',  'top' => 'CHS-LIB-AP-12 roam failures'],
            ['id' => 'NRH', 'name' => 'Northridge High School',     'aps' => 78, 'online' => 78, 'util' => 58, 'clients' =>  844, 'sev' => 'info',     'top' => '—'],
            ['id' => 'PHS', 'name' => 'Paul W. Bryant Middle',      'aps' => 54, 'online' => 53, 'util' => 52, 'clients' =>  612, 'sev' => 'info',     'top' => 'PHS-AP-Lib-03 firmware drift'],
            ['id' => 'ECS', 'name' => 'Eastwood Middle School',     'aps' => 48, 'online' => 48, 'util' => 41, 'clients' =>  481, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'WMS', 'name' => 'Westlawn Middle School',     'aps' => 46, 'online' => 45, 'util' => 47, 'clients' =>  466, 'sev' => 'info',     'top' => '—'],
            ['id' => 'TMS', 'name' => 'Tuscaloosa Magnet Middle',   'aps' => 40, 'online' => 40, 'util' => 38, 'clients' =>  402, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'ALV', 'name' => 'Alberta Elementary',         'aps' => 32, 'online' => 32, 'util' => 44, 'clients' =>  281, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'AED', 'name' => 'Arcadia Elementary',         'aps' => 28, 'online' => 28, 'util' => 35, 'clients' =>  244, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'CRS', 'name' => 'Central Elementary',         'aps' => 32, 'online' => 21, 'util' => 18, 'clients' =>   94, 'sev' => 'disaster', 'top' => '11 APs unreachable · uplink to TCS-CO down', 'kind' => 'outage'],
            ['id' => 'MTV', 'name' => 'Martin Luther King Jr Elem', 'aps' => 26, 'online' => 26, 'util' => 32, 'clients' =>  204, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'OAK', 'name' => 'Oakdale Elementary',         'aps' => 24, 'online' => 24, 'util' => 39, 'clients' =>  198, 'sev' => 'info',     'top' => '—'],
            ['id' => 'RCK', 'name' => 'Rock Quarry Elementary',     'aps' => 26, 'online' => 26, 'util' => 36, 'clients' =>  214, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'SKL', 'name' => 'Skyland Elementary',         'aps' => 22, 'online' => 22, 'util' => 33, 'clients' =>  176, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'STA', 'name' => 'Stafford Elementary',        'aps' => 24, 'online' => 23, 'util' => 49, 'clients' =>  202, 'sev' => 'warning',  'top' => 'STA-AP-Gym-01 high 2.4 GHz noise'],
            ['id' => 'TKM', 'name' => 'Tuscaloosa Magnet Elem',     'aps' => 28, 'online' => 28, 'util' => 41, 'clients' =>  236, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'UPL', 'name' => 'University Place Elem',      'aps' => 26, 'online' => 26, 'util' => 40, 'clients' =>  208, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'VWS', 'name' => 'Verner Elementary',          'aps' => 22, 'online' => 22, 'util' => 31, 'clients' =>  172, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'WDS', 'name' => 'Woodland Forrest Elem',      'aps' => 22, 'online' => 22, 'util' => 37, 'clients' =>  184, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TCT', 'name' => 'Tuscaloosa Career & Tech',   'aps' => 42, 'online' => 42, 'util' => 48, 'clients' =>  411, 'sev' => 'info',     'top' => '—'],
            ['id' => 'AOL', 'name' => 'Tuscaloosa Online',          'aps' =>  6, 'online' =>  6, 'util' => 12, 'clients' =>   38, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'OAS', 'name' => 'Oak Hill Special Ed',        'aps' => 12, 'online' => 12, 'util' => 28, 'clients' =>   84, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TCS', 'name' => 'TCS Central Office',         'aps' => 64, 'online' => 63, 'util' => 55, 'clients' =>  584, 'sev' => 'warning',  'top' => 'Auth-server timeout (PF radius)'],
            ['id' => 'TCO', 'name' => 'Operations / Warehouse',     'aps' => 18, 'online' => 18, 'util' => 22, 'clients' =>   96, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TDC', 'name' => 'Datacenter (CO Annex)',      'aps' =>  8, 'online' =>  8, 'util' => 18, 'clients' =>   41, 'sev' => 'ok',       'top' => '—'],
            ['id' => 'TBS', 'name' => 'Bus Operations',             'aps' => 12, 'online' => 12, 'util' => 26, 'clients' =>   86, 'sev' => 'ok',       'top' => '—'],
        ];
    }

    private static function bands(): array {
        return [
            ['id' => '5',   'label' => '5 GHz',      'aps' => 1184, 'clients' => 6840, 'util' => 58, 'noise' => -91, 'saturated' => 47,  'color' => 'var(--ext)',
                'spark' => [44,46,48,52,55,58,60,61,59,58,57,56,55,57,60,62,63,61,58,55,52,50,48,46]],
            ['id' => '2_4', 'label' => '2.4 GHz',    'aps' => 1184, 'clients' => 1604, 'util' => 71, 'noise' => -84, 'saturated' => 128, 'color' => 'var(--warn)',
                'spark' => [62,64,66,69,71,73,74,75,74,72,71,70,69,71,72,74,75,73,71,68,66,64,62,60]],
            ['id' => '6',   'label' => '6 GHz (6E)', 'aps' => 286,  'clients' =>  820, 'util' => 22, 'noise' => -94, 'saturated' => 0,   'color' => 'var(--ok)',
                'spark' => [12,14,15,17,20,22,24,25,24,23,22,21,20,22,24,26,28,27,25,22,20,18,17,15]],
        ];
    }

    private static function ssids(): array {
        return [
            ['id' => 'tcs-staff',    'label' => 'tcs-staff',    'auth' => '802.1X · EAP-TLS',     'vlan' => 10, 'clients' => 2284, 'success' => 99.7, 'throughput' => 5.84, 'role' => 'faculty'],
            ['id' => 'tcs-students', 'label' => 'tcs-students', 'auth' => '802.1X · PEAP-MSCHAP', 'vlan' => 20, 'clients' => 5102, 'success' => 98.3, 'throughput' => 6.91, 'role' => 'student'],
            ['id' => 'tcs-byod',     'label' => 'tcs-byod',     'auth' => 'PSK · onboarded',      'vlan' => 50, 'clients' => 1184, 'success' => 97.1, 'throughput' => 1.62, 'role' => 'byod'],
            ['id' => 'tcs-guest',    'label' => 'tcs-guest',    'auth' => 'Captive · PF portal',  'vlan' => 60, 'clients' =>  394, 'success' => 94.6, 'throughput' => 0.71, 'role' => 'guest'],
            ['id' => 'tcs-av',       'label' => 'tcs-av',       'auth' => 'PSK · static',         'vlan' => 70, 'clients' =>  142, 'success' => 99.9, 'throughput' => 0.18, 'role' => 'av'],
            ['id' => 'tcs-voice',    'label' => 'tcs-voice',    'auth' => '802.1X · EAP-TLS',     'vlan' => 30, 'clients' =>  118, 'success' => 99.6, 'throughput' => 0.09, 'role' => 'voip'],
            ['id' => 'tcs-iot',      'label' => 'tcs-iot',      'auth' => 'PSK · scoped',         'vlan' => 80, 'clients' =>   34, 'success' => 99.1, 'throughput' => 0.02, 'role' => 'byod'],
            ['id' => 'tcs-mgmt',     'label' => 'tcs-mgmt',     'auth' => '802.1X · cert',        'vlan' =>  4, 'clients' =>    6, 'success' => 100.0,'throughput' => 0.01, 'role' => 'av', 'hidden' => true],
        ];
    }

    private static function problemAps(): array {
        return [
            ['ap' => 'BHS-23-Cafe',    'site' => 'BHS', 'model' => 'AP4000',  'reason' => 'LAN uplink down',                  'sev' => 'high',     'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:14:33'],
            ['ap' => 'CRS-01-Office',  'site' => 'CRS', 'model' => 'AP3000x', 'reason' => 'Unreachable via cloud broker',     'sev' => 'disaster', 'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:04:11'],
            ['ap' => 'CRS-04-Hall',    'site' => 'CRS', 'model' => 'AP3000x', 'reason' => 'Unreachable via cloud broker',     'sev' => 'disaster', 'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:04:11'],
            ['ap' => 'CRS-Gym-Center', 'site' => 'CRS', 'model' => 'AP4000',  'reason' => 'Unreachable via cloud broker',     'sev' => 'disaster', 'util2' => 0,  'util5' => 0,  'clients' => 0,  'age' => '00:04:11'],
            ['ap' => 'BHS-56-Hallway', 'site' => 'BHS', 'model' => 'AP4000',  'reason' => '5 GHz util > 75% (sustained 12m)', 'sev' => 'warning',  'util2' => 38, 'util5' => 81, 'clients' => 64, 'age' => '00:42:18'],
            ['ap' => 'CHS-LIB-AP-12',  'site' => 'CHS', 'model' => 'AP4000',  'reason' => 'Client roam failure rate > 4%',    'sev' => 'warning',  'util2' => 41, 'util5' => 62, 'clients' => 48, 'age' => '00:48:09'],
            ['ap' => 'STA-AP-Gym-01',  'site' => 'STA', 'model' => 'AP410C',  'reason' => '2.4 GHz noise floor -78 dBm',      'sev' => 'warning',  'util2' => 88, 'util5' => 44, 'clients' => 22, 'age' => '01:08:42'],
            ['ap' => 'PHS-AP-Lib-03',  'site' => 'PHS', 'model' => 'AP410C',  'reason' => 'Firmware drift (32.7.0.5 avail)',  'sev' => 'info',     'util2' => 32, 'util5' => 41, 'clients' => 28, 'age' => '01:38:02'],
        ];
    }

    private static function channelGrid(): array {
        return [
            'sites'    => ['BHS','CHS','NRH','PHS','TCS','TCT','CRS','STA'],
            'channels' => [36, 40, 44, 48, 52, 56, 60, 64, 100, 104, 108, 112, 149, 153, 157, 161],
            'matrix'   => [
                [62, 71, 58, 64, 48, 41, 52, 55, 28, 31, 33, 34, 67, 72, 64, 58],
                [54, 62, 51, 57, 41, 38, 44, 48, 22, 24, 26, 28, 61, 64, 58, 52],
                [44, 51, 42, 47, 33, 31, 36, 38, 18, 19, 21, 22, 49, 52, 47, 42],
                [38, 44, 36, 40, 28, 26, 31, 32, 14, 16, 18, 19, 42, 45, 40, 36],
                [48, 56, 46, 52, 38, 34, 41, 42, 19, 21, 24, 25, 54, 58, 52, 47],
                [42, 49, 40, 45, 32, 29, 35, 36, 16, 18, 20, 22, 47, 50, 45, 40],
                [ 0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0],
                [72, 68, 64, 71, 51, 44, 56, 58, 32, 34, 36, 38, 74, 78, 71, 64],
            ]
        ];
    }

    private static function clientMix(): array {
        return [
            'standards' => [
                ['id' => 'ax',     'label' => 'Wi-Fi 6 / 6E (ax)', 'count' => 6418, 'pct' => 69.3, 'color' => 'var(--ext)'],
                ['id' => 'ac',     'label' => 'Wi-Fi 5 (ac)',      'count' => 2310, 'pct' => 24.9, 'color' => 'var(--info)'],
                ['id' => 'n',      'label' => 'Wi-Fi 4 (n)',       'count' =>  428, 'pct' =>  4.6, 'color' => 'var(--warn)'],
                ['id' => 'legacy', 'label' => 'Legacy a/b/g',      'count' =>  108, 'pct' =>  1.2, 'color' => 'var(--err)'],
            ],
            'os' => [
                ['id' => 'chrome',  'label' => 'ChromeOS',  'count' => 4862, 'pct' => 52.5],
                ['id' => 'win',     'label' => 'Windows',   'count' => 1718, 'pct' => 18.5],
                ['id' => 'ios',     'label' => 'iPadOS',    'count' => 1284, 'pct' => 13.9],
                ['id' => 'macos',   'label' => 'macOS',     'count' =>  642, 'pct' =>  6.9],
                ['id' => 'android', 'label' => 'Android',   'count' =>  482, 'pct' =>  5.2],
                ['id' => 'other',   'label' => 'Other',     'count' =>  276, 'pct' =>  3.0],
            ]
        ];
    }

    private static function throughput(): array {
        return [
            2.1, 1.8, 1.4, 1.2, 1.1, 1.3, 2.6, 5.8, 11.4, 17.2, 19.8, 21.4,
            22.1, 18.6, 16.4, 19.1, 21.8, 14.8, 9.4, 6.2, 4.4, 3.6, 2.8, 2.2
        ];
    }

    private static function firmware(): array {
        return [
            'versions' => [
                ['v' => '32.7.0.7', 'count' =>   84, 'status' => 'ahead',  'note' => 'early-ring (BHS)'],
                ['v' => '32.7.0.5', 'count' => 1054, 'status' => 'target', 'note' => 'fleet target'],
                ['v' => '32.7.0.3', 'count' =>   34, 'status' => 'behind', 'note' => 'scheduled May 18'],
                ['v' => '32.6.4.1', 'count' =>    7, 'status' => 'behind', 'note' => 'needs window'],
                ['v' => '—',        'count' =>    5, 'status' => 'ahead',  'note' => 'lab / spare'],
            ]
        ];
    }

    private static function roaming(): array {
        return [
            'buckets' => [
                ['range' => '< 20 ms', 'count' => 7124, 'color' => 'var(--ok)'],
                ['range' => '20–50',   'count' => 1284, 'color' => 'var(--ok)'],
                ['range' => '50–120',  'count' =>  514, 'color' => 'var(--warn)'],
                ['range' => '120–250', 'count' =>  198, 'color' => 'var(--warn)'],
                ['range' => '250+',    'count' =>   86, 'color' => 'var(--err)'],
                ['range' => 'Failed',  'count' =>   58, 'color' => 'var(--err)'],
            ],
            'rate24h' => 0.62
        ];
    }

    private static function events(): array {
        return [
            ['ts' => '10:14:08', 'source' => 'ext', 'host' => 'BHS-23-Cafe',       'msg' => 'Device disconnected:', 'obj' => 'no LAN keepalive (12s)',           'sev' => 'high'],
            ['ts' => '10:13:51', 'source' => 'ext', 'host' => 'CRS-04-Hall',       'msg' => 'Device unreachable:',  'obj' => 'broker timeout (60s)',             'sev' => 'disaster'],
            ['ts' => '10:13:22', 'source' => 'pf',  'host' => 'F4:5C:89:0B:32:71', 'msg' => 'RADIUS reject:',       'obj' => 'unknown CA on tcs-staff',          'sev' => 'warning'],
            ['ts' => '10:12:08', 'source' => 'ext', 'host' => 'STA-AP-Gym-01',     'msg' => 'RF event:',            'obj' => '2.4 GHz noise floor -78 dBm (12m)','sev' => 'warning'],
            ['ts' => '10:11:47', 'source' => 'ext', 'host' => 'BHS-56-Hallway',    'msg' => 'Channel change:',      'obj' => '5 GHz 149 → 157 (CCA 81%)',        'sev' => 'info'],
            ['ts' => '10:10:24', 'source' => 'ext', 'host' => 'CHS-LIB-AP-12',     'msg' => 'Roam anomaly:',        'obj' => '13 clients · 4.2% fail rate',      'sev' => 'warning'],
            ['ts' => '10:09:08', 'source' => 'ext', 'host' => 'NRH-ACC-04',        'msg' => 'Client joined:',       'obj' => 'iPad · tcs-staff · -54 dBm',       'sev' => 'ok'],
            ['ts' => '10:08:13', 'source' => 'ext', 'host' => 'PHS-AP-Lib-03',     'msg' => 'Firmware drift:',      'obj' => '32.7.0.5 → 32.7.0.7 available',    'sev' => 'info'],
            ['ts' => '10:07:42', 'source' => 'ext', 'host' => 'TCS-AD-Conf-A',     'msg' => 'Capacity:',            'obj' => '76 clients on single radio (5 GHz)','sev' => 'info'],
            ['ts' => '10:06:31', 'source' => 'ext', 'host' => 'CRS-CORE-01',       'msg' => 'Upstream:',            'obj' => 'uplink Te1/49 to TCS-CO down',     'sev' => 'disaster'],
            ['ts' => '10:05:18', 'source' => 'ext', 'host' => 'BHS-Gym-N-02',      'msg' => 'Mesh formed:',         'obj' => 'backup link 5 GHz · -68 dBm',      'sev' => 'ok'],
            ['ts' => '10:04:02', 'source' => 'pf',  'host' => 'k.davis@tcs',       'msg' => 'EAP-TLS success:',     'obj' => 'BHS-56-Hallway · -52 dBm',         'sev' => 'ok'],
        ];
    }
}
