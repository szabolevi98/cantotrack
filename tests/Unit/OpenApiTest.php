<?php

namespace CantoTrack\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * docs/openapi.json against the routes: every endpoint under /api/v1 is in
 * it, nothing is in it that is not an endpoint, and it holds together — each
 * reference points at something, each path parameter is declared. And it is
 * what bin/openapi.php makes, so that nobody edits the JSON by hand and has
 * the next build take the edit away.
 */
final class OpenApiTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        return json_decode((string) file_get_contents(self::root() . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEveryApiRouteIsDescribedAndNothingElse(): void
    {
        preg_match_all("~\\\$router->(get|post|patch|delete)\\('/api/v1(/[^']*)'~", (string) file_get_contents(self::root() . '/src/routes.php'), $m, PREG_SET_ORDER);
        $routes = array_map(static fn(array $r): string => strtoupper($r[1]) . ' ' . $r[2], $m);

        $described = [];
        foreach (self::document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $described[] = strtoupper((string) $method) . ' ' . $path;
            }
        }

        sort($routes);
        sort($described);
        self::assertSame($routes, $described);
    }

    public function testItHoldsTogether(): void
    {
        $document = self::document();
        $json = (string) file_get_contents(self::root() . '/docs/openapi.json');

        self::assertSame('3.1.0', $document['openapi']);

        // Every reference points at something that is there.
        preg_match_all('~"\$ref": "#/components/([a-zA-Z]+)/([A-Za-z]+)"~', $json, $refs, PREG_SET_ORDER);
        self::assertNotEmpty($refs);
        foreach ($refs as [, $kind, $name]) {
            self::assertArrayHasKey($name, $document['components'][$kind] ?? [], "#/components/$kind/$name is referred to, but not there.");
        }

        $ids = [];
        foreach ($document['paths'] as $path => $operations) {
            preg_match_all('/\{(\w+)\}/', (string) $path, $placeholders);

            foreach ($operations as $method => $operation) {
                $ids[] = $operation['operationId'];
                self::assertArrayHasKey('responses', $operation, "$method $path");

                // Each {placeholder} of the path is a declared path parameter.
                $declared = [];
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $parameter = isset($parameter['$ref'])
                        ? $document['components']['parameters'][basename((string) $parameter['$ref'])]
                        : $parameter;
                    if ($parameter['in'] === 'path') {
                        $declared[] = $parameter['name'];
                    }
                }
                sort($declared);
                $wanted = $placeholders[1];
                sort($wanted);
                self::assertSame($wanted, $declared, "The path parameters of $method $path");
            }
        }

        self::assertSame($ids, array_values(array_unique($ids)), 'Every operationId is its own.');
    }

    public function testItIsWhatTheBuildMakes(): void
    {
        $file = self::root() . '/docs/openapi.json';
        $before = (string) file_get_contents($file);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/bin/openapi.php'), $output, $status);
        $after = (string) file_get_contents($file);
        file_put_contents($file, $before);

        self::assertSame(0, $status);
        self::assertSame(str_replace("\r\n", "\n", $after), str_replace("\r\n", "\n", $before), 'docs/openapi.json is not what bin/openapi.php makes: run it, and commit the result.');
    }
}
