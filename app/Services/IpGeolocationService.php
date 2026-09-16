<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves the country for a batch of IPs via ip-api.com's free, keyless
 * endpoint, caching each result for a month since an IP's country is
 * effectively static. Private/reserved/loopback addresses (dev machines,
 * LAN, localhost) never hit the API — they have no meaningful location.
 */
class IpGeolocationService
{
    private const CACHE_TTL_DAYS = 30;

    private const BATCH_SIZE = 100; // ip-api.com's batch endpoint limit

    /**
     * @param  string[]  $ips
     * @return array<string, ?string> ip => country name, or null if unresolvable
     */
    public function countriesFor(array $ips): array
    {
        $unique = array_values(array_unique(array_filter($ips)));

        $result = [];
        $toLookup = [];

        foreach ($unique as $ip) {
            if (! $this->isPublicIp($ip)) {
                $result[$ip] = null;

                continue;
            }

            $cacheKey = $this->cacheKey($ip);
            if (Cache::has($cacheKey)) {
                $result[$ip] = Cache::get($cacheKey);

                continue;
            }

            $toLookup[] = $ip;
        }

        foreach (array_chunk($toLookup, self::BATCH_SIZE) as $chunk) {
            foreach ($this->lookupBatch($chunk) as $ip => $country) {
                Cache::put($this->cacheKey($ip), $country, now()->addDays(self::CACHE_TTL_DAYS));
                $result[$ip] = $country;
            }
        }

        return $result;
    }

    private function cacheKey(string $ip): string
    {
        return "geoip:country:{$ip}";
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * @param  string[]  $ips
     * @return array<string, ?string>
     */
    private function lookupBatch(array $ips): array
    {
        $result = array_fill_keys($ips, null);

        try {
            $response = Http::timeout(5)->post(
                'http://ip-api.com/batch?fields=status,country,query',
                array_map(fn (string $ip) => ['query' => $ip], $ips),
            );
        } catch (\Throwable) {
            return $result;
        }

        if (! $response->successful()) {
            return $result;
        }

        foreach ((array) $response->json() as $entry) {
            $ip = $entry['query'] ?? null;
            if ($ip && ($entry['status'] ?? null) === 'success') {
                $result[$ip] = $entry['country'] ?? null;
            }
        }

        return $result;
    }
}
