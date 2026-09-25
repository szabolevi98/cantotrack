<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Sort;
use PHPUnit\Framework\TestCase;

final class SortTest extends TestCase
{
    private const COLUMNS = ['name' => 'u.name', 'open' => 'open_tickets'];

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testAHeaderGoesAscendingThenDescendingThenBack(): void
    {
        $_GET = [];
        self::assertSame('?sort=name&dir=asc', Sort::url('name'));

        $_GET = ['sort' => 'name', 'dir' => 'asc'];
        self::assertSame('?sort=name&dir=desc', Sort::url('name'));

        $_GET = ['sort' => 'name', 'dir' => 'desc'];
        self::assertSame('?', Sort::url('name'), 'the third click is the list’s own order');
    }

    public function testAnotherHeaderStartsAscending(): void
    {
        $_GET = ['sort' => 'name', 'dir' => 'desc'];

        self::assertSame('?sort=open&dir=asc', Sort::url('open'));
    }

    public function testTheFiltersStayAndThePageGoes(): void
    {
        $_GET = ['q' => 'bike', 'project' => '3', 'page' => '4'];

        self::assertSame('?q=bike&project=3&sort=name&dir=asc', Sort::url('name'));
    }

    public function testOnlyTheListsOwnColumnsReachTheSql(): void
    {
        $_GET = ['sort' => 'open', 'dir' => 'desc'];
        self::assertSame('open_tickets DESC, u.name', Sort::orderBy(self::COLUMNS, 'u.name'));

        $_GET = ['sort' => 'password_hash', 'dir' => 'asc'];
        self::assertSame('u.name', Sort::orderBy(self::COLUMNS, 'u.name'), 'a column the list does not offer');

        $_GET = ['sort' => 'name; DROP TABLE users', 'dir' => 'asc'];
        self::assertSame('u.name', Sort::orderBy(self::COLUMNS, 'u.name'));

        $_GET = ['sort' => ['name'], 'dir' => 'asc'];
        self::assertNull(Sort::current());
    }

    public function testAnyOtherDirectionIsAscending(): void
    {
        $_GET = ['sort' => 'name', 'dir' => 'sideways'];

        self::assertSame(['key' => 'name', 'dir' => 'asc'], Sort::current());
    }

    public function testTheLinkSaysItsStateAndIsEscaped(): void
    {
        $_GET = ['q' => '"><script>', 'sort' => 'name', 'dir' => 'asc'];

        $link = Sort::link('name', 'Name <b>');

        self::assertStringContainsString('class="sort is-sorted"', $link);
        self::assertStringContainsString('▲', $link);
        self::assertStringContainsString('Name &lt;b&gt;', $link);
        self::assertStringNotContainsString('<script>', $link);
        self::assertSame('aria-sort="ascending"', Sort::aria('name'));
        self::assertSame('', Sort::aria('open'));
        self::assertStringContainsString('class="sort"', Sort::link('open', 'Open'));
    }

    public function testAFormSentFromASortedListKeepsTheOrder(): void
    {
        $_GET = [];
        self::assertSame('', Sort::inputs());

        $_GET = ['sort' => 'open', 'dir' => 'desc'];
        self::assertSame('<input type="hidden" name="sort" value="open"><input type="hidden" name="dir" value="desc">', Sort::inputs());
    }
}
