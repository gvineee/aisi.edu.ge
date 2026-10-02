<?php

namespace App\Domain\Platform;

/**
 * Proves control of a domain: the school adds a TXT record
 * `_platform-verify.<domain>` whose value is the issued token.
 */
class DomainVerifier
{
    public const RECORD_PREFIX = '_platform-verify.';

    public function verify(string $domain, string $token): bool
    {
        $records = @dns_get_record(self::RECORD_PREFIX.$domain, DNS_TXT);

        if (! is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            if (isset($record['txt']) && hash_equals($token, trim((string) $record['txt']))) {
                return true;
            }
        }

        return false;
    }
}
