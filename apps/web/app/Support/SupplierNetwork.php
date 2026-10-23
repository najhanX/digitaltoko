<?php

namespace App\Support;

class SupplierNetwork
{
    public static function outboundIp(string $source): ?string
    {
        $configured = $source === 'digiflazz'
            ? (config('suppliers.digiflazz_outbound_ip') ?: config('suppliers.outbound_ip'))
            : config('suppliers.outbound_ip');
        $ip = is_string($configured) ? trim($configured) : '';

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? $ip : null;
    }
}
