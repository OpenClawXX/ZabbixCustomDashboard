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

    /**
     * Top extensions today via the OData function import:
     *   GET /xapi/v1/ReportExtensionStatistics/Pbx.GetExtensionStatisticsData(
     *       periodFrom='2024-…',periodTo='2024-…',extensionFilter=null,callArea=0
     *   )?$top=N&$orderby=…
     *
     * Returns PbxExtensionStatistics rows {DisplayName, Dn, Inbound*, Outbound*}.
     * Sorting/aggregation to "top by total calls" happens in the mapper.
     */
    public function topExtensions(int $top = 10): array {
        $now  = gmdate('Y-m-d\TH:i:s\Z');
        $from = gmdate('Y-m-d\T00:00:00\Z');
        $path = sprintf(
            "/xapi/v1/ReportExtensionStatistics/Pbx.GetExtensionStatisticsData(periodFrom='%s',periodTo='%s',extensionFilter=null,callArea=0)",
            $from, $now
        );
        $r = $this->get($path, ['$top' => (string) max(1, $top)]);
        return $r['value'] ?? [];
    }

    /**
     * Call-quality history. The v20 XAPI doesn't expose a pre-bucketed
     * quality timeline — quality lives on individual CDR rows
     * (ReportCallLogData / Pbx.GetCallQualityReport per cdrId). Building
     * a 48-bucket 24h chart would require pulling the full day's CDR
     * and aggregating, which is too expensive for a 30s rollup.
     *
     * Until we add a separate caching pipeline for it, return an empty
     * result and let the page render zeros.
     */
    public function callQuality(string $bucket = '30m'): array {
        return [];
    }

    /* ------------------------------------------------------------------ */
    /* Adapter helpers — shape XAPI rows into the VOIP_* window globals.  */
    /* Bodies left for the implementation step; signatures locked so      */
    /* ActionVoipData wiring can be reviewed in this PR.                  */
    /* ------------------------------------------------------------------ */

    /**
     * Build the VOIP_SITES grid from /Users + /Groups.
     *
     * v20 PbxUser carries the registration state directly as IsRegistered
     * (bool) — no need to fish through a RegistrarContact field. Site
     * grouping uses the user's first non-DEFAULT entry in Groups[]
     * (PbxUserGroup carries Name/Number/GroupId).
     */
    public function extensionsBySite(): array {
        $users = $this->users(5000);

        // /Groups: friendly names for the group ids that appear in PbxUser.Groups[].
        // Best-effort — if the API client lacks read permission, fall through
        // and use the raw group id as the site name.
        $groupsById = [];
        try {
            $g = $this->get('/xapi/v1/Groups');
            foreach (($g['value'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $id = (string) ($row['Id'] ?? $row['Number'] ?? '');
                if ($id !== '') $groupsById[$id] = (string) ($row['Name'] ?? $row['Number'] ?? $id);
            }
        } catch (\Throwable $_) { /* best-effort */ }

        $bySite = [];
        foreach ($users as $u) {
            if (!is_array($u)) continue;
            $ext = (string) ($u['Number'] ?? '');
            if ($ext === '') continue;

            $display = trim((string) ($u['DisplayName'] ?? ''));
            if ($display === '') {
                $display = trim(((string) ($u['FirstName'] ?? '')) . ' ' . ((string) ($u['LastName'] ?? '')));
            }
            if ($display === '') $display = $ext;

            // Registration / presence
            $enabled    = (bool) ($u['Enabled']       ?? true);
            $registered = (bool) ($u['IsRegistered']  ?? false);
            $profile    = strtolower((string) ($u['CurrentProfileName'] ?? ''));
            if (!$enabled)           $state = 'unreg';
            elseif (!$registered)    $state = 'unreg';
            elseif (str_contains($profile, 'do not disturb') || str_contains($profile, 'dnd')) $state = 'dnd';
            else                     $state = 'reg';

            // Site = first non-DEFAULT group. PbxUserGroup has GroupId,
            // Name, Number — prefer Name.
            $siteId   = 'DIST';
            $siteName = 'District';
            foreach (($u['Groups'] ?? []) as $g) {
                if (!is_array($g)) continue;
                $gid    = (string) ($g['GroupId'] ?? $g['Id'] ?? '');
                $gname  = (string) ($g['Name']    ?? '');
                if (strtoupper($gname) === 'DEFAULT' || $gname === '') continue;
                $siteId   = $gid !== '' ? $gid : $gname;
                $siteName = $groupsById[$gid] ?? $gname;
                break;
            }

            $bySite[$siteId] ??= ['id' => $siteId, 'name' => $siteName, 'expanded' => true, 'ext' => []];
            $bySite[$siteId]['ext'][] = [
                'ext'   => $ext,
                'name'  => $display,
                'site'  => $siteId,
                'state' => $state,
            ];
        }
        $out = array_values($bySite);
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($out as &$site) {
            usort($site['ext'], fn($a, $b) => strcmp($a['ext'], $b['ext']));
        }
        return $out;
    }

    /**
     * Queue summary rows for the VOIP_QUEUES card.
     *
     * v20 doesn't expose live performance counters on the PbxQueue entity
     * itself — agent counts come from the embedded Agents[] array, and SLA
     * threshold lives in SLATime (seconds). "Currently waiting" / "answered
     * today" / "abandoned today" / "SLA%" need a separate
     * ReportDetailedQueueStatistics call per queue, which is too expensive
     * for the 30s rollup and is gated behind admin-level permissions on
     * many builds. For now we ship the static shape (agents, slaSec) and
     * leave the dynamic metrics zeroed; future work can wire the detailed
     * report on a slower cadence.
     */
    public function queuesWithPerformance(): array {
        $queues = $this->queues();
        $out = [];
        foreach ($queues as $q) {
            if (!is_array($q)) continue;
            $agents = is_array($q['Agents'] ?? null) ? $q['Agents'] : [];
            $out[] = [
                'name'     => (string) ($q['Name']   ?? 'Queue'),
                'ext'      => (string) ($q['Number'] ?? ''),
                'agents'   => count($agents),
                'agentsOn' => count($agents),  // live "logged in" needs ReportDetailedQueueStatistics
                'waiting'  => 0,
                'sla'      => 0,
                'abandon'  => 0,
                'ans'      => 0,
                'slaSec'   => (int) ($q['SLATime'] ?? 30),
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
