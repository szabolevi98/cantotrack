<?php

namespace CantoTrack\Core;

/**
 * The address a request really came from.
 *
 * Behind a CDN or a reverse proxy, REMOTE_ADDR is the proxy's address, and every
 * visitor looks like the same one — so a limit per address limits everybody at
 * once. The proxy passes the visitor's own address in a header, but a header is
 * something anybody can send. It is believed only when the request came from
 * one of the proxies listed in `app.trusted_proxies`; from anywhere else, the
 * header is ignored and the connection's own address is used.
 *
 *   [app]
 *   trusted_proxies = "173.245.48.0/20, 103.21.244.0/22, …"
 *   client_ip_header = "HTTP_CF_CONNECTING_IP"
 */
class ClientIp
{
    public static function get(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $header = (string) Config::get('app.client_ip_header', '');
        $trusted = array_values(array_filter(array_map('trim', explode(',', (string) Config::get('app.trusted_proxies', '')))));

        if ($header === '' || $trusted === [] || !self::inAny($remote, $trusted)) {
            return $remote;
        }

        // X-Forwarded-For may carry a chain; the first entry is the visitor.
        $given = trim(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);

        return filter_var($given, FILTER_VALIDATE_IP) !== false ? $given : $remote;
    }

    /** @param list<string> $ranges */
    public static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);

        $ipBytes = @inet_pton($ip);
        $subnetBytes = @inet_pton((string) $subnet);

        if ($ipBytes === false || $subnetBytes === false || strlen($ipBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $bits = $bits === null ? strlen($ipBytes) * 8 : (int) $bits;
        $whole = intdiv($bits, 8);

        if (substr($ipBytes, 0, $whole) !== substr($subnetBytes, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBytes[$whole]) & $mask) === (ord($subnetBytes[$whole]) & $mask);
    }
}
