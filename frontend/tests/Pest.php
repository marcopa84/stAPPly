<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Ogni test di tests/Feature gira sul TestCase dell'app (niente richieste vere al
| backend) con un DB SQLite in memoria rifatto da zero.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
|
| Funzioni comuni a più file di test: le risposte del contratto, il backend
| finto, il login demo e le richieste che il backend ha ricevuto.
|
*/

/**
 * Il corpo di un esempio di docs/contract/: le risposte finte dei test sono quelle del contratto.
 *
 * @return array<string, mixed>
 */
function contract(string $example): array
{
    return json_decode(
        (string) file_get_contents(base_path("../docs/contract/{$example}.json")),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * Finge il backend: a ogni "METODO percorso" (per esempio "GET agents/demo-valtesa/documents")
 * la sua risposta, oppure una closure come Http::failedConnection(). Al posto degli id che
 * il test non conosce il percorso può avere un asterisco, come nei pattern di Str::is().
 * Una richiesta non prevista fa fallire il test.
 *
 * @param  array<string, PromiseInterface|callable(Request): PromiseInterface>  $routes
 */
function fakeBackend(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $route = $request->method().' '.Str::after($request->url(), 'http://backend.test/');
        $response = $routes[$route]
            ?? collect($routes)->first(fn (mixed $response, string $pattern) => Str::is($pattern, $route))
            ?? throw new RuntimeException("Richiesta al backend non prevista dal test: {$route}");

        return is_callable($response) ? $response($request) : $response;
    });
}

/**
 * Il login con le credenziali demo pubblicate nella home: apre una sandbox.
 */
function demoLogin(?string $password = null): TestResponse
{
    return test()->post(route('login.store'), [
        'email' => config('stapply.demo.email'),
        'password' => $password ?? config('stapply.demo.password'),
    ]);
}

/**
 * Le copie chieste al backend con POST /clone, nell'ordine: agente nuovo => agente copiato.
 *
 * @return array<string, string>
 */
function clonesSent(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/clone'))
        ->mapWithKeys(fn (array $pair) => [Str::between($pair[0]->url(), '/agents/', '/clone') => $pair[0]['from']])
        ->all();
}

/**
 * Gli agent_id a cui il backend ha ricevuto una DELETE /agents/{agent_id}.
 *
 * @return list<string>
 */
function agentsDeleted(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->method() === 'DELETE' && preg_match('#/agents/[^/]+$#', $pair[0]->url()) === 1)
        ->map(fn (array $pair) => Str::afterLast($pair[0]->url(), '/'))
        ->values()
        ->all();
}
