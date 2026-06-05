<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * 3CX v18/v20 XAPI client.
 *
 * Auth model: OAuth2 client-credentials grant against /connect/token,
 * yielding a bearer token cached in APCu (TTL ≈ token-expiry-30s). The
 * dashboard expects an "Integrations" API client to be provisioned in the
 * 3CX Management Console with whatever role grants read access to the
 * XAPI surfaces below — typically "System Owner" or a custom read-only
 * role.
 *
 * Public surface (each returns the shape the React app's window globals
 * expect — see notes/voip-integration-plan.md §3.1 for the mapping):
 *
 *   - systemStatus()                  → pbx + service-status rollup
 *   - trunks()                        → VOIP_TRUNKS rows
 *   - activeCalls()                   → VOIP_CALLS rows
 *   - users(int $top = 500)           → raw extension list
 *   - extensionsBySite()              → VOIP_SITES grouped by inventory tag
 *   - queues()                        → raw queue list
 *   - queuesWithPerformance()         → VOIP_QUEUES rows (joins Performance)
 *   - topExtensions(int $top = 10)    → VOIP_TOP rows
 *   - callQuality(string $bucket)     → VOIP_QUALITY arrays
 *
 * NOTE: scaffolding stub — public surface and auth plumbing are defined so
 * ActionVoipData can wire to it; individual builder bodies are TODO and
 * will be filled in alongside steps 3–5 of the integration plan.
 */
class ThreeCXClient {

    private string $url;
    private string $clientId;
    private string $clientSecret;
    private bool   $verifySsl;

    private ?string $token       = null;
    private int     $tokenExpiry = 0;

    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_TOTAL   = 30;
    private const UA              = 'TcsDashboard/1.0 (+ThreeCXClient)';

    /** APCu key prefix for the bearer token cache. */
    private const CACHE_PREFIX = 'tcs_3cx_token::';

    public function __construct(
        string $url,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        bool $verifySsl = true
    ) {
        $this->url          = rtrim($url, '/');
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->verifySsl    = $verifySsl;
    }

    /**
     * @param array{url:string,client_id:string,client_secret:string,verify_ssl?:bool} $cfg
     */
    public static function fromMacros(array $cfg): self {
        return new self(
            (string) ($cfg['url']           ?? ''),
            (string) ($cfg['client_id']     ?? ''),
            (string) ($cfg['client_secret'] ?? ''),
            (bool)   ($cfg['verify_ssl']    ?? true)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Public XAPI surface                                                */
    /* ------------------------------------------------------------------ */

    /** GET /xapi/v1/SystemStatus → pbx headline + service-status array. */
    public function systemStatus(): array {
        return $this->get('/xapi/v1/SystemStatus');
    }

    /** GET /xapi/v1/Trunks (raw OData rows). */
    public function trunks(): array {
        $r = $this->get('/xapi/v1/Trunks');
        return $r['value'] ?? [];
    }

    /** GET /xapi/v1/ActiveCalls. Returns the raw OData list. */
    public function activeCalls(): array {
        $r = $this->get('/xapi/v1/ActiveCalls');
        return $r['value'] ?? [];
    }

    /** GET /xapi/v1/Users (paged via $top / $skip if needed). */
    public function users(int $top = 500): array {
        $r = $this->get('/xapi/v1/Users', [
            '$top'    => (string) $top,
            '$select' => 'Number,FirstName,LastName,CurrentProfileName,Forwarding,Registrar,Groups',
        ]);
        return $r['value'] ?? [];
    }

    /** GET /xapi/v1/Queues. */
    public function queues(): array {
        $r = $this->get('/xapi/v1/Queues');
        return $r['value'] ?? [];
    }

    /** GET /xapi/v1/Queues({Id})/Performance?date=today. */
    public function queuePerformance(string $queueId): array {
        return $this->get(sprintf('/xapi/v1/Queues(%s)/Performance', rawurlencode($queueId)), [
            'date' => 'today',
        ]);
    }

    /** GET /xapi/v1/Defs/ExtensionStatistics?period=today&top=N. */
    public function topExtensions(int $top = 10): array {
        $r = $this->get('/xapi/v1/Defs/ExtensionStatistics', [
            'type'   => 'ByExtension',
            'period' => 'today',
            'top'    => (string) $top,
        ]);
        return $r['value'] ?? [];
    }

    /** GET /xapi/v1/Defs/CallQualityStatistics?period=last24h&bucket=30m. */
    public function callQuality(string $bucket = '30m'): array {
        return $this->get('/xapi/v1/Defs/CallQualityStatistics', [
            'period' => 'last24h',
            'bucket' => $bucket,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Adapter helpers — shape XAPI rows into the VOIP_* window globals.  */
    /* Bodies left for the implementation step; signatures locked so      */
    /* ActionVoipData wiring can be reviewed in this PR.                  */
    /* ------------------------------------------------------------------ */

    public function extensionsBySite(): array {
        // TODO step 3: group users() by Groups[] entry whose name matches a
        // VOIP_SITES site id, fold registration state from RegistrarContact
        // ('' = unreg) and CurrentProfileName ('Available'/'OutOfOffice'/DND).
        return [];
    }

    public function queuesWithPerformance(): array {
        // TODO step 3: zip queues() with queuePerformance(id) per row;
        // return rows shaped like the VOIP_QUEUES sample in voip-app.jsx
        // ({ name, ext, agents, agentsOn, waiting, sla, abandon, ans, slaSec }).
        return [];
    }

    /* ------------------------------------------------------------------ */
    /* HTTP                                                                */
    /* ------------------------------------------------------------------ */

    private function get(string $path, array $query = []): array {
        $token = $this->tokenOrRefresh();
        $url   = $this->url . $path . ($query ? '?' . http_build_query($query) : '');

        [$status, $body] = $this->curl('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        // One-shot 401 retry — token may have expired before APCu TTL.
        if ($status === 401) {
            $this->token       = null;
            $this->tokenExpiry = 0;
            $token             = $this->tokenOrRefresh(true);
            [$status, $body]   = $this->curl('GET', $url, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ]);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                '3CX XAPI %s returned HTTP %d: %s',
                $path, $status, substr($body, 0, 240)
            ));
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : [];
    }

    private function tokenOrRefresh(bool $force = false): string {
        if (!$force && $this->token !== null && time() < $this->tokenExpiry) {
            return $this->token;
        }
        // APCu shared cache so concurrent PHP requests don't dogpile /connect/token.
        $cacheKey = self::CACHE_PREFIX . md5($this->url . '|' . $this->clientId);
        if (!$force && function_exists('apcu_fetch')) {
            $hit = apcu_fetch($cacheKey, $ok);
            if ($ok && is_array($hit) && isset($hit['token'], $hit['exp']) && time() < $hit['exp']) {
                $this->token       = (string) $hit['token'];
                $this->tokenExpiry = (int) $hit['exp'];
                return $this->token;
            }
        }

        [$status, $body] = $this->curl(
            'POST',
            $this->url . '/connect/token',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ])
        );
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("3CX /connect/token returned HTTP $status: " . substr($body, 0, 240));
        }
        $j = json_decode($body, true);
        if (!is_array($j) || empty($j['access_token'])) {
            throw new \RuntimeException('3CX /connect/token returned no access_token');
        }
        $this->token       = (string) $j['access_token'];
        $ttl               = max(60, (int) ($j['expires_in'] ?? 3600) - 30);
        $this->tokenExpiry = time() + $ttl;
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, ['token' => $this->token, 'exp' => $this->tokenExpiry], $ttl);
        }
        return $this->token;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function curl(string $method, string $url, array $headers = [], ?string $body = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("3CX HTTP transport error: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string) $resp];
    }
}
