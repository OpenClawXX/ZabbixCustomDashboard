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
                    // TODO: $client = ThreeCXClient::fromMacros($cfg);
                    //        $payload['calls'] = $client->activeCalls();
                    //        $payload['sources']['3cx'] = 'live';
                    $payload['sources']['3cx'] = 'unconfigured';
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
