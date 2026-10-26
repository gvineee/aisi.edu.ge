<?php

/*
 * Proxies whose X-Forwarded-For/-Proto/-Port headers are believed, so
 * request()->ip() is the visitor (login throttles, form rate limits, audit
 * logs) and not the CDN edge. X-Forwarded-Host is never trusted: the school
 * (tenant) is resolved from the Host header — see bootstrap/app.php.
 *
 * TRUSTED_PROXIES: empty (trust nobody — direct traffic), `cloudflare`
 * (Cloudflare's published edge ranges), or a comma-separated IP/CIDR list.
 * A request reaching the origin directly from a non-listed address keeps
 * its own address, whatever headers it sends.
 */

// https://www.cloudflare.com/ips/ — checked 2026-10-26.
$cloudflare = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

$configured = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match ($configured) {
        '' => [],
        'cloudflare' => $cloudflare,
        default => array_values(array_filter(array_map(trim(...), explode(',', $configured)))),
    },
];
