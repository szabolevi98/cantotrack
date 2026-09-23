<?php

namespace CantoTrack\Core\Markdown;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * "CT-14" in a comment becomes a link to CT-14.
 *
 * The link goes to /t/CT-14, which finds the ticket when it is followed rather
 * than when the comment is drawn: no query per mention, and a comment that
 * names a ticket before it exists still works once it does.
 */
final class TicketKeyParser implements InlineParserInterface
{
    public function __construct(private readonly string $base)
    {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        // Case-sensitive: the parser's patterns ignore case unless told, and
        // "see-1" or "xCT-5" in a sentence is not a ticket.
        return InlineParserMatch::regex('[A-Z][A-Z0-9]{1,9}-[0-9]+')->caseSensitive();
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();

        // Part of a longer word ("ABC-12-draft", "xCT-1") is not a ticket name.
        $before = $cursor->peek(-1);
        if ($before !== null && preg_match('/[\w-]/u', $before) === 1) {
            return false;
        }

        $key = $inlineContext->getFullMatch();
        $after = $cursor->peek($inlineContext->getFullMatchLength());
        if ($after !== null && preg_match('/[\w]/u', $after) === 1) {
            return false;
        }

        $cursor->advanceBy($inlineContext->getFullMatchLength());

        $link = new Link($this->base . '/t/' . $key, $key);
        $link->data->set('attributes', ['class' => 'ticket-key']);
        $inlineContext->getContainer()->appendChild($link);

        return true;
    }
}
