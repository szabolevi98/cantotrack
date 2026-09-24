<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\Pages;
use CantoTrack\Service\TicketQuery;
use PHPUnit\Framework\TestCase;

final class PageTemplatesTest extends TestCase
{
    public function testEveryTemplateHasATitleAndItsTicketListsRead(): void
    {
        foreach (Pages::templates('2026-09-24') as $key => $template) {
            self::assertNotSame('', $template['title'], $key);
            self::assertStringStartsWith('# ', $template['body'], $key);

            preg_match_all('/\{\{tickets (.*?)\}\}/', $template['body'], $m);
            foreach ($m[1] as $query) {
                self::assertNotSame('', TicketQuery::compile($query, 1)['where'], $key . ': ' . $query);
            }
        }
    }
}
