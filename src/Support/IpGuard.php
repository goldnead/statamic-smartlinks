<?php

namespace Goldnead\Smartlinks\Support;

/**
 * Whether an address may be fetched by the link check: only public unicast.
 * Stored links come from editors and imports; without this a link to
 * `169.254.169.254` or an intranet host would make the server fetch it.
 */
final class IpGuard
{
    /** Ranges PHP's FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE let through. */
    private const BLOCKED = [
        '100.64.0.0/10',    // carrier-grade NAT
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, broadcast
        '64:ff9b::/96',     // NAT64: may map onto private v4
        '64:ff9b:1::/48',
        '2001:db8::/32',    // documentation
        'ff00::/8',         // multicast
        '::ffff:0:0/96',    // v4-mapped
        '100::/64',         // discard
    ];

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
