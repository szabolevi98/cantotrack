<?php

namespace CantoTrack\Core\Markdown;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * "@anna" in a comment becomes a mention of Anna — a link to her week, with
 * her name on it. A handle nobody has stays the text it was, so an email
 * address or a decorator in a code sample is not turned into somebody.
 */
final class MentionParser implements InlineParserInterface
{
    /** @var \Closure(): array<string, array{id: int, name: string}> */
    private \Closure $handles;

    /** @param callable(): array<string, array{id: int, name: string}> $handles */
    public function __construct(private readonly string $base, callable $handles)
    {
        $this->handles = \Closure::fromCallable($handles);
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('@[A-Za-z0-9]{2,40}');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();

        // "anna@example.test" is an address, not a mention.
        $before = $cursor->peek(-1);
        if ($before !== null && preg_match('/[\w.@]/u', $before) === 1) {
            return false;
        }

        $handle = strtolower(substr($inlineContext->getFullMatch(), 1));
        $person = ($this->handles)()[$handle] ?? null;

        if ($person === null) {
            return false;
        }

        $cursor->advanceBy($inlineContext->getFullMatchLength());

        $link = new Link($this->base . '/timesheet?user=' . $person['id'], '@' . $handle, $person['name']);
        $link->data->set('attributes', ['class' => 'mention']);
        $inlineContext->getContainer()->appendChild($link);

        return true;
    }
}
