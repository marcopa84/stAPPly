<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le pagine caricano immagini solo da stAPPly stesso (e le data: delle icone di Flux).
 * Le risposte della chat non producono più <img> (App\Markdown\ImageAsTextExtension):
 * questa è la seconda difesa, perché un'immagine esterna che compare in pagina si
 * scarica senza clic e può portare fuori dati nell'URL. La policy tocca solo le
 * immagini: script e stili di Livewire, Flux e Vite restano come sono.
 */
class RestrictImageSources
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', "img-src 'self' data:");

        return $response;
    }
}
