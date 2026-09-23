<?php

namespace Goldnead\Smartlinks\Support;

use Goldnead\Smartlinks\Contracts\HostResolver;

class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $ips[] = $record[$key];
                }
            }
        }

        // dns_get_record ignores /etc/hosts; gethostbynamel does not.
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
