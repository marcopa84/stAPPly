<?php

namespace App\Markdown;

use App\Services\Backend\Data\Source;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Estensione di CommonMark: gli id dei chunk citati nella risposta diventano chip che
 * mostrano il passaggio. Un parser vero, e non una sostituzione sull'HTML finito:
 * un id dentro il codice resta testo e nessun chip finisce dentro un attributo.
 */
final class CitationExtension implements ExtensionInterface
{
    /** @var array<string, Source> */
    private array $sources = [];

    /**
     * @param  list<Source>  $sources  i passaggi citati nella risposta (campo sources del backend)
     */
    public function __construct(array $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->id] = $source;
        }
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Priorità sopra OpenBracketParser (20), che leggerebbe [id] come l'inizio di un link.
        $environment
            ->addInlineParser(new CitationParser($this->sources), 25)
            ->addRenderer(Citation::class, new CitationRenderer);
    }
}
