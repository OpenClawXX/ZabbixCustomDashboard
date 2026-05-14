<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Lib;

/**
 * Fleet-level ExtremeCloud IQ client.
 *
 * Sits alongside XIQClient (which is verbatim from jerahl/ZabbixExtremeIQ and
 * scoped to per-device queries). XIQFleetClient hits the cloud-wide list
 * endpoints — /devices, /clients/active — and handles paging, caching, and
 * rate-limit accounting on its own minimal cURL shim so the upstream client
 * stays unmodified for easy re-syncs.
 *
 * Token-only auth: pass a permanent API token from Zabbix global macro
 * {$XIQ_API_TOKEN}. JWT credential flow lives in XIQClient if needed.
 *
 * Caching: APCu, per-endpoint TTL. Defaults are sized for a ~30s page-refresh
 * cadence — devices change slowly (5min), active clients churn faster (60s).
 *
 * Rate-limit awareness: every response updates getRateLimitRemaining(). The
 * 7,500-req/hr quota is shared across all XIQ integrations on the tenant.
 */
final class XIQFleetClient {

    private const BASE_URL      = 'https://api.extremecloudiq.com';
    private const CACHE_PREFIX  = 'tcs_dashboard:xiq_fleet_client:';
    private const PAGE_LIMIT    = 100;
    private const MAX_PAGES     = 200;       // hard ceiling — defends against runaway pagination
    private const HTTP_TIMEOUT  = 30;

    private string $token;
    private int $rateLimitRemaining = -1;
    private int $rateLimitReset     = 0;

    private function __construct(string $token) {
        $this->token = $token;
    }

    public static function fromToken(string $token): self {
        if ($token === '') {
            throw new \InvalidArgumentException('XIQFleetClient: empty API token');
        }
        return new self($token);
    }

    public function getRateLimitRemaining(): int { return $this->rateLimitRemaining; }
    public function getRateLimitReset(): int     { return $this->rateLimitReset; }
    public function isRateLimitLow(): bool       { return $this->rateLimitRemaining >= 0 && $this->rateLimitRemaining < 500; }

    /**
     * Whole-fleet AP list.
     *
     * Each row is the BASIC view of GET /devices — id, hostname, mac_address,
     * device_function, product_type, network_policy_id, software_version,
     * connected (bool), last_connect_time_ms, ip_address. Use views=FULL only
     * if you need d360 telemetry (much heavier).
     */
    public function getDevices(int $cacheTtl = 300): array {
        return $this->cached('devices', $cacheTtl, function () {
            return $this->getPaged('/devices', ['views' => 'BASIC']);
        });
    }

    /**
     * Whole-fleet active client list (views=FULL — emits rssi, snr, channel,
     * connection_duration, locations[]; without it XIQ returns only 12 fields).
     */
    public function getActiveClients(int $cacheTtl = 60): array {
        return $this->cached('clients_active', $cacheTtl, function () {
            return $this->getPaged('/clients/active', ['views' => 'FULL']);
        });
    }

    /**
     * List of network policies (id + name). Use as the seed for SSID rollups
     * via XIQClient::getPolicySsids($policyId).
     */
    public function getNetworkPolicies(int $cacheTtl = 600): array {
        return $this->cached('policies', $cacheTtl, function () {
            return $this->getPaged('/network-policies', []);
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @param callable():array $producer */
    private function cached(string $bucket, int $ttl, callable $producer): array {
        $key = self::CACHE_PREFIX . $bucket;
        if ($ttl > 0 && function_exists('apcu_fetch')) {
            $hit = apcu_fetch($key, $ok);
            if ($ok && is_array($hit)) return $hit;
        }
        $value = $producer();
        if ($ttl > 0 && function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Drain a paginated XIQ list endpoint. XIQ list responses follow one of
     * two shapes; we handle both:
     *   { data: [...], total_pages: N, page: M }                 (wrapped)
     *   [ ... ]                                                  (raw)
     */
    private function getPaged(string $path, array $query): array {
        $all  = [];
        $page = 1;
        do {
            $resp = $this->getJson($path, $query + ['page' => $page, 'limit' => self::PAGE_LIMIT]);
            $rows = $resp['data'] ?? (array_is_list($resp) ? $resp : []);
            if (!is_array($rows) || !$rows) break;
            foreach ($rows as $r) $all[] = $r;

            $totalPages = (int) ($resp['total_pages'] ?? 0);
            if ($totalPages > 0) {
                if ($page >= $totalPages) break;
            } else {
                // No total_pages — stop when we get a short page.
                if (count($rows) < self::PAGE_LIMIT) break;
            }
            $page++;
            if ($page > self::MAX_PAGES) break;
        } while (true);

        return $all;
    }

    /** @return array<string, mixed> */
    private function getJson(string $path, array $query): array {
        $url = self::BASE_URL . $path . ($query ? '?' . http_build_query($query) : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("XIQ transport: $err");
        }
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = (string) substr($raw, 0, $headerSize);
        $body    = (string) substr($raw, $headerSize);

        // RateLimit headers are advisory but cheap to track for the warning banner.
        if (preg_match('/^RateLimit-Remaining:\s*(\d+)/im', $headers, $m)) {
            $this->rateLimitRemaining = (int) $m[1];
        }
        if (preg_match('/^RateLimit-Reset:\s*(\d+)/im', $headers, $m)) {
            $this->rateLimitReset = (int) $m[1];
        }

        if ($status === 401) throw new \RuntimeException('XIQ 401 — token revoked or invalid');
        if ($status === 429) throw new \RuntimeException('XIQ 429 — rate limit exceeded');
        if ($status < 200 || $status >= 300) {
            $snip = substr($body, 0, 240);
            throw new \RuntimeException("XIQ HTTP $status on $path — $snip");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('XIQ returned non-JSON body');
        }
        return $decoded;
    }
}
