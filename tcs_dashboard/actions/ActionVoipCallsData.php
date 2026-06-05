<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use API;
use CControllerResponseData;
use Modules\TcsDashboard\Lib\ThreeCXClient;

/**
 * GET zabbix.php?action=tcs.voip.calls.data
 *
 * Fast-cadence (5 s) sub-poll for the live active-calls list. Lives in its
 * own action so the bridge can hit it every few seconds without paying for
 * the full rollup (trunks / extensions / queues / quality) on every tick.
 * Returns just { calls: [...], ts, sources }.
 *
 * Cache TTL is short (3 s) so coalesced polls from multiple operator tabs
 * still benefit from APCu, but data never feels stale.
 */
class ActionVoipCallsData extends ActionDataBase {

    private const CACHE_TTL = 3;
    private const CACHE_KEY = 'tcs_dashboard:voip:calls:v1';

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function doAction(): void {
        $payload = ['calls' => null, 'sources' => ['3cx' => 'unknown'], 'ts' => time()];

        try {
            $cached = self::cacheGet();
            if ($cached !== null) {
                $payload = $cached;
                $payload['ts'] = $payload['ts'] ?? time();
            } else {
                $cfg = [
                    'url'           => self::globalMacro('{$TCS.3CX.URL}'),
                    'client_id'     => self::globalMacro('{$TCS.3CX.CLIENT_ID}'),
                    'client_secret' => self::globalMacro('{$TCS.3CX.CLIENT_SECRET}'),
                    'verify_ssl'    => self::globalMacro('{$TCS.3CX.VERIFY.SSL}') !== '0',
                ];
                if ($cfg['url'] === '' || $cfg['client_id'] === '') {
                    $payload['sources']['3cx'] = 'unconfigured';
                } else {
                    $client            = ThreeCXClient::fromMacros($cfg);
                    $payload['calls']  = self::mapActiveCalls($client->activeCalls());
                    $payload['sources']['3cx'] = 'live';
                }
                $payload['ts'] = time();
                self::cacheSet($payload);
            }
        } catch (\Throwable $e) {
            error_log('[tcs_dashboard] voip.calls.data: ' . $e->getMessage());
            $payload['error']          = $e->getMessage();
            $payload['sources']['3cx'] = 'error';
        }

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        ]));
    }

    /** /xapi/v1/ActiveCalls rows → VOIP_CALLS shape consumed by ActiveCallsCard. */
    private static function mapActiveCalls(array $rows): array {
        $out = [];
        foreach ($rows as $c) {
            if (!is_array($c)) continue;

            $caller = (string) self::pick($c, ['Caller', 'CallerNumber', 'From'], '');
            $callee = (string) self::pick($c, ['Callee', 'CalleeNumber', 'To'], '');

            // Direction: if Caller is a 3–5 digit extension we treat as outbound,
            // an external (E.164ish) number → inbound; both internal → "int".
            $callerExt = self::looksLikeExtension($caller);
            $calleeExt = self::looksLikeExtension($callee);
            if ($callerExt && $calleeExt) $dir = 'int';
            elseif ($callerExt)           $dir = 'out';
            else                          $dir = 'in';

            $status = strtolower((string) self::pick($c, ['Status', 'State'], ''));
            if (in_array($status, ['ringing', 'queued', 'waiting'], true)) $dir = 'q';

            // Duration: ISO 8601 "00:01:23" or seconds since EstablishedAt.
            $dur = self::pick($c, ['Duration', 'TalkDuration', 'CallDuration'], null);
            if ($dur === null) {
                $est = self::pick($c, ['EstablishedAt', 'StartTime'], null);
                $now = self::pick($c, ['ServerNow', 'CurrentTime'], null);
                if ($est && $now) {
                    $delta = max(0, strtotime((string) $now) - strtotime((string) $est));
                    $dur   = sprintf('%d:%02d', intdiv($delta, 60), $delta % 60);
                } else {
                    $dur = '0:00';
                }
            } else {
                $dur = self::formatDuration($dur);
            }

            $mos = (float) self::pick($c, ['Mos', 'MOS', 'CallQuality'], 0);
            $q   = $mos >= 4.0 ? 'good' : ($mos >= 3.5 ? 'fair' : ($mos > 0 ? 'poor' : 'good'));

            $out[] = [
                'dir'     => $dir,
                'from'    => $caller,
                'fromSub' => (string) self::pick($c, ['CallerDisplayName', 'CallerName'], ''),
                'to'      => $callee,
                'toSub'   => (string) self::pick($c, ['CalleeDisplayName', 'CalleeName'], ''),
                'dur'     => (string) $dur,
                'codec'   => (string) self::pick($c, ['Codec', 'CallCodec'], '—'),
                'trunk'   => (string) self::pick($c, ['TrunkName', 'TrunkId', 'Gateway'], ''),
                'mos'     => $mos,
                'q'       => $q,
            ];
        }
        return $out;
    }

    private static function looksLikeExtension(string $s): bool {
        $s = preg_replace('/\s+/', '', $s) ?? '';
        return $s !== '' && preg_match('/^\d{2,6}$/', $s) === 1;
    }

    private static function formatDuration($v): string {
        if (is_numeric($v)) {
            $s = (int) $v;
            return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
        }
        if (is_string($v) && preg_match('/^(\d+):(\d{2}):(\d{2})$/', $v, $m)) {
            $s = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
            if ($s >= 3600) return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
            return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
        }
        return (string) $v;
    }

    private static function pick(array $a, array $keys, $default) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== '') return $a[$k];
        }
        return $default;
    }

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

    private static function globalMacro(string $name): string {
        $rows = API::UserMacro()->get([
            'output'      => ['macro', 'value'],
            'globalmacro' => true,
            'filter'      => ['macro' => $name],
        ]) ?: [];
        return trim((string) ($rows[0]['value'] ?? ''));
    }
}
