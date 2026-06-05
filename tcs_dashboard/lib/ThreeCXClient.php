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

    /**
     * GET /xapi/v1/Users with paging. 3CX v20 caps $top at 100, so we walk
     * the collection with $skip until a page returns fewer than $page rows.
     * Caller passes a soft upper bound — defaults to 5000, which is well
     * above what any school-district PBX would hold.
     */
    public function users(int $max = 5000): array {
        $page = 100;
        $out  = [];
        for ($skip = 0; $skip < $max; $skip += $page) {
            $r = $this->get('/xapi/v1/Users', [
                '$top'  => (string) $page,
                '$skip' => (string) $skip,
            ]);
            $rows = $r['value'] ?? [];
            if (!$rows) break;
            foreach ($rows as $row) $out[] = $row;
            if (count($rows) < $page) break;
        }
        return $out;
    }

    /**
     * Call queues. v20 builds differ on the collection name — try both.
     */
    public function queues(): array {
        foreach (['/xapi/v1/CallQueues', '/xapi/v1/Queues'] as $path) {
            try {
                $r = $this->get($path);
                $rows = $r['value'] ?? [];
                if ($rows) return $rows;
            } catch (\RuntimeException $e) {
                if (!self::isNotFound($e)) throw $e;
            }
        }
        return [];
    }

    /** GET /xapi/v1/Queues({Id})/Performance?date=today. */
    public function queuePerformance(string $queueId): array {
        return $this->get(sprintf('/xapi/v1/Queues(%s)/Performance', rawurlencode($queueId)), [
            'date' => 'today',
        ]);
    }

    /**
     * Top extensions by call volume today. 3CX v20 dropped the `/Defs/`
     * prefix and exposes report data as OData function imports on the
     * service root. Path / param names still drift between minor builds,
     * so we walk a small candidate list and return on the first 2xx.
     */
    public function topExtensions(int $top = 10): array {
        $candidates = [
            // v20+: function import
            ['/xapi/v1/ReportExtensionStatisticsByExtensionsListData', ['top' => (string) $top]],
            // v20 alt: direct collection if exposed
            ['/xapi/v1/ReportExtensionStatistics',                     ['top' => (string) $top]],
            // v18 legacy
            ['/xapi/v1/Defs/ExtensionStatistics', ['type' => 'ByExtension', 'period' => 'today', 'top' => (string) $top]],
        ];
        foreach ($candidates as [$path, $q]) {
            try {
                $r = $this->get($path, $q);
                return $r['value'] ?? (is_array($r) ? $r : []);
            } catch (\RuntimeException $e) {
                if (self::isNotFound($e)) continue;
                throw $e;
            }
        }
        return [];
    }

    /**
     * Call-quality 24h history. Same 3CX-build drift as topExtensions()
     * — walk a candidate list and accept the first 2xx.
     */
    public function callQuality(string $bucket = '30m'): array {
        $candidates = [
            ['/xapi/v1/ReportCallQualityData',          ['bucket' => $bucket]],
            ['/xapi/v1/ReportCallQuality',              ['bucket' => $bucket]],
            ['/xapi/v1/Defs/CallQualityStatistics',     ['period' => 'last24h', 'bucket' => $bucket]],
        ];
        foreach ($candidates as [$path, $q]) {
            try {
                return $this->get($path, $q);
            } catch (\RuntimeException $e) {
                if (self::isNotFound($e)) continue;
                throw $e;
            }
        }
        return [];
    }

    private static function isNotFound(\RuntimeException $e): bool {
        return str_contains($e->getMessage(), 'HTTP 404') || str_contains($e->getMessage(), 'HTTP 405');
    }

    /* ------------------------------------------------------------------ */
    /* Adapter helpers — shape XAPI rows into the VOIP_* window globals.  */
    /* Bodies left for the implementation step; signatures locked so      */
    /* ActionVoipData wiring can be reviewed in this PR.                  */
    /* ------------------------------------------------------------------ */

    public function extensionsBySite(): array {
        $users = $this->users(500);

        // Group definitions: try /Groups for friendly names. Failure here
        // just means we fall back to "DIST" as the catch-all site id.
        $groupsById = [];
        try {
            $g = $this->get('/xapi/v1/Groups', ['$select' => 'Id,Name,Number']);
            foreach (($g['value'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $id = (string) ($row['Id'] ?? $row['Number'] ?? '');
                if ($id !== '') $groupsById[$id] = (string) ($row['Name'] ?? $id);
            }
        } catch (\Throwable $_) { /* best-effort */ }

        $bySite = [];
        foreach ($users as $u) {
            if (!is_array($u)) continue;
            $ext   = (string) ($u['Number'] ?? '');
            if ($ext === '') continue;
            $first = (string) ($u['FirstName'] ?? '');
            $last  = (string) ($u['LastName']  ?? '');
            $name  = trim($first . ' ' . $last) ?: $ext;

            // Registration state. RegistrarContact present → registered.
            // CurrentProfileName "Do Not Disturb"/"OutOfOffice" → dnd.
            $reg = $u['Registrar'] ?? $u['RegistrarContact'] ?? null;
            $profile = strtolower((string) ($u['CurrentProfileName'] ?? ''));
            $state = $reg ? 'reg' : 'unreg';
            if ($state === 'reg' && (str_contains($profile, 'do not disturb') || str_contains($profile, 'dnd'))) {
                $state = 'dnd';
            }

            // Site = first group the user belongs to (skipping the implicit
            // "DEFAULT" or empty groups). If groups are absent the user
            // lands in DIST.
            $siteId = 'DIST';
            $siteName = 'District Office';
            $userGroups = $u['Groups'] ?? [];
            if (is_array($userGroups)) {
                foreach ($userGroups as $g) {
                    $gid = (string) (is_array($g) ? ($g['Id'] ?? $g['GroupId'] ?? '') : $g);
                    if ($gid === '' || strtoupper($gid) === 'DEFAULT') continue;
                    $siteId   = $gid;
                    $siteName = $groupsById[$gid] ?? $gid;
                    break;
                }
            }

            $bySite[$siteId] ??= ['id' => $siteId, 'name' => $siteName, 'expanded' => true, 'ext' => []];
            $bySite[$siteId]['ext'][] = [
                'ext'   => $ext,
                'name'  => $name,
                'site'  => $siteId,
                'state' => $state,
            ];
        }
        // Stable order: by site name, extensions by number within each.
        $out = array_values($bySite);
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($out as &$site) {
            usort($site['ext'], fn($a, $b) => strcmp($a['ext'], $b['ext']));
        }
        return $out;
    }

    public function queuesWithPerformance(): array {
        $queues = $this->queues();
        $out = [];
        foreach ($queues as $q) {
            if (!is_array($q)) continue;
            $id   = (string) ($q['Id']     ?? '');
            $name = (string) ($q['Name']   ?? 'Queue');
            $num  = (string) ($q['Number'] ?? '');

            $perf = [];
            if ($id !== '') {
                try { $perf = $this->queuePerformance($id); }
                catch (\Throwable $_) { /* leave perf empty for this queue */ }
            }

            $agents   = (int)   ($perf['AgentsTotal']         ?? $perf['TotalAgents']    ?? 0);
            $agentsOn = (int)   ($perf['AgentsLoggedIn']      ?? $perf['LoggedInAgents'] ?? $agents);
            $waiting  = (int)   ($perf['CallsWaiting']        ?? $perf['Waiting']        ?? 0);
            $ans      = (int)   ($perf['AnsweredCalls']       ?? $perf['Answered']       ?? 0);
            $abandon  = (int)   ($perf['AbandonedCalls']      ?? $perf['Abandoned']      ?? 0);
            $sla      = (float) ($perf['ServiceLevel']        ?? $perf['SlaPercent']     ?? 0);
            $slaSec   = (int)   ($perf['ServiceLevelSeconds'] ?? $perf['SlaSeconds']     ?? 30);

            $out[] = [
                'name'     => $name,
                'ext'      => $num,
                'agents'   => $agents,
                'agentsOn' => $agentsOn,
                'waiting'  => $waiting,
                'sla'      => (int) round($sla),
                'abandon'  => $abandon,
                'ans'      => $ans,
                'slaSec'   => $slaSec,
            ];
        }
        return $out;
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
