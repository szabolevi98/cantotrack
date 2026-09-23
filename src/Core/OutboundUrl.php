<?php

namespace CantoTrack\Core;

/**
 * Whether the server may send a request to an address somebody typed in —
 * a webhook's — and to which IP exactly.
 *
 * An address typed into a form is an address the server will visit from the
 * inside of the network it sits in. Without this, "http://127.0.0.1:3306" or
 * "http://169.254.169.254/latest/meta-data" is a way to make the tracker
 * knock on doors nobody outside can reach. So: http or https only, and every
 * address the name resolves to has to be a public one. The IP checked is then
 * the IP connected to (pinned with CURLOPT_RESOLVE), so a name that resolves
 * to something public for the check and to 127.0.0.1 a moment later does not
 * get through between the two.
 *
 * `webhooks.allow_private = true` lifts the address check, for a development
 * machine sending to itself.
 */
final class OutboundUrl
{
    /**
     * @return array{host: string, port: int, ip: string}
     * @throws ValidationError when the address may not be visited
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationError(__('A webhook address starts with http:// or https://, and has no password in it.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        if ($addresses === []) {
            throw new ValidationError(__('{host} does not resolve to an address.', ['host' => $host]));
        }

        if (!Config::bool('webhooks.allow_private', false)) {
            foreach ($addresses as $ip) {
                if (!self::isPublic($ip)) {
                    throw new ValidationError(__('{host} points into a private network, which webhooks may not reach.', ['host' => $host]));
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublic(string $ip): bool
    {
        // IPv4 addresses written as IPv6 (::ffff:127.0.0.1) are judged as
        // the IPv4 address they are.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // What the two flags leave out: carrier-grade NAT, and IPv6's
        // unique-local and link-local ranges on older PHP versions.
        return !ClientIp::inAny($ip, ['100.64.0.0/10', '0.0.0.0/8', 'fc00::/7', 'fe80::/10', '::/128', '::1/128']);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);

            foreach ($records ?: [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        // dns_get_record() does not read the hosts file; gethostbynamel()
        // does, which is what "localhost" on a development machine needs.
        if ($addresses === []) {
            $addresses = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
