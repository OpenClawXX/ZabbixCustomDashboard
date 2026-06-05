<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use Modules\TcsDashboard\Lib\ThreeCXClient;

/**
 * GET zabbix.php?action=tcs.voip.data
 *
 * 30-second rollup for the VoIP / 3CX dashboard. Primary data source is the
 * "3CX Phone System by HTTP" Zabbix template (KPIs, services, calls-active
 * 24h history, problems). The 3CX XAPI fills in everything the template
 * doesn't expose: per-trunk channel utilization, the per-extension
 * registration grid, queues w/ SLA, top extensions, and the MOS/jitter/loss/
 * RTT history. See notes/voip-integration-plan.md for the field-by-field
 * source split.
 *
 * The live active-calls list is served by a separate action
 * (tcs.voip.calls.data) so the bridge can poll it on a tighter cadence
 * without re-doing the full rollup every 5s.
 *
 * NOTE: This is a scaffolding stub. Step 1 of the plan — emits the empty
 * shape so the bridge can boot and the page degrades cleanly until the
 * Zabbix-only and XAPI builders are filled in.
 */
class ActionVoipData extends ActionDataBase {

    private const CACHE_TTL = 30;
    private const CACHE_KEY = 'tcs_dashboard:voip:v1';

    /** Template name the 3CX host is expected to use. */
    private const TEMPLATE_NAME = '3CX Phone System by HTTP';

    /** Override macro for primary host selection. */
    private const HOST_MACRO = '{$TCS.VOIP.HOST}';

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $payload = self::emptyPayload();

        try {
            $cached = self::cacheGet();
            if ($cached !== null) {
                $payload = $cached;
            } else {
                $payload = self::buildPayload();
                self::cacheSet($payload);
            }
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] voip.data: ' . $e->getMessage());
            $payload['error']           = 'VoIP data query failed: ' . $e->getMessage();
            $payload['sources']['zbx']  = 'error';
        }

        $payload['ts'] = time();
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        ]));
    }

    // ── Public: empty shell, used both for SSR boot and as a fallback ──────

    public static function emptyPayload(): array {
        return [
            'loading'  => true,
            'pbx'      => null,      // VOIP_PBX  — null lets the JSX mock fallback win until live
            'services' => null,      // VOIP_SERVICES
            'trunks'   => null,      // VOIP_TRUNKS
            'calls'    => null,      // VOIP_CALLS  (also refreshed by tcs.voip.calls.data)
            'top'      => null,      // VOIP_TOP
            'queues'   => null,      // VOIP_QUEUES
            'quality'  => null,      // VOIP_QUALITY
            'sites'    => null,      // VOIP_SITES
            'problems' => null,      // VOIP_PROBLEMS
            'sources'  => ['zbx' => 'unknown', '3cx' => 'unknown'],
        ];
    }

    // ── Cache ──────────────────────────────────────────────────────────────

    private static function cacheGet(): ?array {
        if (!function_exists('apcu_fetch')) return null;
        $hit = apcu_fetch(self::CACHE_KEY, $ok);
        return ($ok && is_array($hit)) ? $hit : null;
    }

    private static function cacheSet(array $payload): void {
        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $payload, self::CACHE_TTL);
        }
    }

    // ── Build ──────────────────────────────────────────────────────────────

    private static function buildPayload(): array {
        $payload = self::emptyPayload();

        // 1. Resolve the 3CX host (template match + optional macro override).
        $host = self::findVoipHost();
        if (!$host) {
            $payload['sources']['zbx'] = 'empty';
            $payload['warning'] = 'No 3CX host found. Looked for hosts using the "'
                . self::TEMPLATE_NAME . '" template.';
            return $payload;
        }

        // 2. Pull Zabbix items in one call (pattern lifted from ActionFortigateData).
        //    TODO step 2: implement collectItems() + buildPbx/buildServices/buildHistory.
        //    For now we leave the slots null so the JSX mock-fallback keeps rendering.

        // 3. Resolve 3CX XAPI macros and instantiate the client. Each XAPI
        //    call below is independently try/wrapped so a single failure
        //    only blanks its own slot; the rest of the rollup still ships.
        $cfg = self::voipMacros();
        if ($cfg['url'] !== '' && $cfg['client_id'] !== '' && $cfg['client_secret'] !== '') {
            $client = ThreeCXClient::fromMacros($cfg);
            try {
                // TODO step 3+: $payload['trunks']  = $client->trunks();
                //                $payload['sites']   = $client->extensionsBySite();
                //                $payload['queues']  = $client->queuesWithPerformance();
                //                $payload['top']     = $client->topExtensions();
                //                $payload['quality'] = $client->callQuality();
                $payload['sources']['3cx'] = 'unconfigured';
            } catch (\Throwable $e) {
                error_log('[tcs_dashboard] voip 3cx call failed: ' . $e->getMessage());
                $payload['sources']['3cx'] = 'error';
                $payload['warning'] = '3CX XAPI unreachable: ' . $e->getMessage();
            }
        } else {
            $payload['sources']['3cx'] = 'unconfigured';
        }

        // 4. Problems for the 3CX host group come from Zabbix regardless.
        //    TODO step 2: $payload['problems'] = self::buildProblems((string) $host['hostid']);

        $payload['sources']['zbx'] = 'live';
        $payload['loading']        = false;
        return $payload;
    }

    /** Locate the primary 3CX host. */
    private static function findVoipHost(): ?array {
        $templates = API::Template()->get([
            'output'      => ['templateid', 'host', 'name'],
            'search'      => ['name' => self::TEMPLATE_NAME],
            'startSearch' => true,
        ]) ?: [];
        if (!$templates) return null;

        $hosts = API::Host()->get([
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['interfaceid', 'ip', 'main', 'type'],
            'selectInventory'  => ['model', 'os_full', 'location'],
            'templateids'      => array_column($templates, 'templateid'),
        ]) ?: [];
        if (!$hosts) return null;

        // Optional override
        $override = self::globalMacro(self::HOST_MACRO);
        if ($override !== '') {
            foreach ($hosts as $h) {
                if (strcasecmp((string) $h['name'], $override) === 0 ||
                    strcasecmp((string) $h['host'], $override) === 0) {
                    return $h;
                }
            }
        }
        usort($hosts, fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        return $hosts[0];
    }

    /** @return array{url:string,client_id:string,client_secret:string,verify_ssl:bool} */
    private static function voipMacros(): array {
        return [
            'url'           => self::globalMacro('{$TCS.3CX.URL}'),
            'client_id'     => self::globalMacro('{$TCS.3CX.CLIENT_ID}'),
            'client_secret' => self::globalMacro('{$TCS.3CX.CLIENT_SECRET}'),
            'verify_ssl'    => self::globalMacro('{$TCS.3CX.VERIFY.SSL}') !== '0',
        ];
    }

    private static function globalMacro(string $name): string {
        $rows = API::UserMacro()->get([
            'output'      => ['macro', 'value'],
            'globalmacro' => true,
            'filter'      => ['macro' => $name],
        ]) ?: [];
        return trim((string) ($rows[0]['value'] ?? ''));
    }
}
