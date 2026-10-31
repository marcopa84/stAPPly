<?php

namespace App\Markdown;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

/**
 * Il chip di una citazione: un pulsante con l'id che apre un popover con il nome del
 * file e il testo del passaggio. Il popover è ui-dropdown di Flux, lo stesso del
 * tooltip apribile, quindi posizione, chiusura con Esc e attributi ARIA sono suoi.
 *
 * Solo span e button perché il chip sta dentro un <p>, che un <div> chiuderebbe.
 * Nome e testo vengono da un documento caricato: si scappano sempre. Lo stile è in
 * resources/css/app.css (.citation-*), perché Tailwind non legge le classi in app/.
 */
final class CitationRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof Citation) {
            throw new InvalidArgumentException('Il nodo non è una citazione: '.$node::class);
        }

        $source = $node->source;

        return new HtmlElement('ui-dropdown', ['position' => 'bottom start', 'class' => 'citation'], [
            new HtmlElement('button', [
                'type' => 'button',
                'class' => 'citation-chip',
                'aria-label' => "Passaggio citato {$source->id}, da {$source->filename}",
            ], Xml::escape($source->id)),
            new HtmlElement('span', ['popover' => 'manual', 'class' => 'citation-popover'], [
                new HtmlElement('span', ['class' => 'citation-file'], Xml::escape($source->filename)),
                new HtmlElement('span', ['class' => 'citation-text'], Xml::escape($source->text)),
            ]),
        ]);
    }
}
