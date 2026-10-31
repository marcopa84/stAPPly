<?php

namespace App\Markdown;

use App\Services\Backend\Data\Source;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Riconosce gli id dei chunk tra parentesi quadre, anche più d'uno: [a-001] o [a-001, b-002].
 *
 * Diventano citazioni solo gli id che il backend ha messo tra le fonti del turno. Se tra le
 * parentesi non ce n'è nessuno il parser rinuncia e il testo resta com'è: un id inventato
 * dal modello non deve sembrare una fonte.
 */
final class CitationParser implements InlineParserInterface
{
    /** La forma degli id dei chunk: <document_id>-000 (docs/api.md). */
    private const ID = '[a-z0-9][a-z0-9-]*';

    /**
     * @param  array<string, Source>  $sources  i passaggi citabili, per id
     */
    public function __construct(private readonly array $sources) {}

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('\['.self::ID.'(?: *[,;] *'.self::ID.')*\]')->caseSensitive();
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $ids = preg_split('/ *[,;] */', trim($inlineContext->getFullMatch(), '[]')) ?: [];

        if (array_intersect($ids, array_keys($this->sources)) === []) {
            return false;
        }

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());

        foreach ($ids as $id) {
            $inlineContext->getContainer()->appendChild(
                isset($this->sources[$id]) ? new Citation($this->sources[$id]) : new Text("[{$id}]"),
            );
        }

        return true;
    }
}
