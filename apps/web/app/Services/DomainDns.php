<?php

namespace App\Services;

use RuntimeException;

class DomainDns
{
    public function records(string $hostname, int $type): array
    {
        $records = @dns_get_record($hostname, $type);
        if ($records === false) {
            throw new RuntimeException('DNS belum dapat diperiksa. Coba kembali setelah beberapa saat.');
        }

        return $records;
    }

    public function addresses(string $hostname, int $depth = 0): array
    {
        if ($depth > 7) {
            return [];
        }
        $addresses = [];
        foreach ($this->records($hostname, DNS_A | DNS_AAAA | DNS_CNAME) as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            } elseif (isset($record['target'])) {
                array_push($addresses, ...$this->addresses(strtolower(rtrim($record['target'], '.')), $depth + 1));
            }
        }

        return array_values(array_unique($addresses));
    }

    public static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! str_starts_with(strtolower($ip), '::ffff:');
    }
}
