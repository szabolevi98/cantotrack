<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Config;
use CantoTrack\Core\Markdown;
use CantoTrack\Model\LabelRepository;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The renderer builds links from the base URL; a unit test has no
        // configuration file, so it gets a small one of its own.
        $file = tempnam(sys_get_temp_dir(), 'ct-md-');
        file_put_contents((string) $file, "[app]\nbase_url = \"https://tracker.example\"\n");
        Config::load((string) $file);
        Markdown::useHandles(['anna' => ['id' => 5, 'name' => 'Anna Kovács']]);
    }

    public static function tearDownAfterClass(): void
    {
        // The integration tests after this one need the real test settings.
        if (defined('CANTOTRACK_TEST_CONFIG')) {
            Config::load(CANTOTRACK_TEST_CONFIG);
        }

        Markdown::forget();
    }

    public function testAMentionOfSomebodyWhoExistsBecomesALink(): void
    {
        $html = Markdown::toHtml('Ask @anna, not @nobody or anna@example.test.');

        self::assertStringContainsString('class="mention"', $html);
        self::assertSame(1, substr_count($html, 'class="mention"'));
        self::assertSame([5], Markdown::mentions('Ask @anna and @nobody'));
    }

    public function testATicketNameBecomesALink(): void
    {
        $html = Markdown::toHtml('See CT-14.');

        self::assertStringContainsString('<a class="ticket-key" href="https://tracker.example/t/CT-14">CT-14</a>', $html);
    }

    public function testOnlyAWholeUpperCaseNameIsATicket(): void
    {
        $html = Markdown::toHtml('xCT-5, ct-6, CT-7x and `CT-8`');

        self::assertStringNotContainsString('/t/', $html);
    }

    public function testHtmlIsEscapedAndScriptLinksAreDropped(): void
    {
        $html = Markdown::toHtml('<img src=x onerror=alert(1)> and [click](javascript:alert(1))');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testGithubFlavourWorks(): void
    {
        $html = Markdown::toHtml("- [x] done\n\n~~gone~~");

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('<del>gone</del>', $html);
    }

    public function testLabelsAreCleanedAndKeepTheirFirstSpelling(): void
    {
        self::assertSame(['Security', 'tech debt'], LabelRepository::parse(' Security, security ,tech   debt,, '));
        self::assertSame(LabelRepository::colour('Security'), LabelRepository::colour('security'));
    }
}
