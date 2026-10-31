<?php

use App\Enums\AnswerRoute;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Una risposta dell'agente, non salvata, con un passaggio citabile.
 *
 * @param  list<array{id: string, document_id: string, filename: string, text: string}>|null  $sources
 */
function answer(string $content, ?array $sources = null): Message
{
    return Message::factory()->assistant()->make([
        'content' => $content,
        'sources' => $sources ?? [[
            'id' => 'regolamento-ferie-003',
            'document_id' => 'regolamento-ferie',
            'filename' => 'regolamento-ferie.pdf',
            'text' => 'Le ferie si chiedono con 15 giorni di anticipo.',
        ]],
    ]);
}

test('un id citato tra le fonti diventa un chip con il passaggio e il nome del file', function () {
    $html = (string) answer('Servono 15 giorni di anticipo [regolamento-ferie-003].')->answerHtml();

    expect($html)
        ->toContain('<ui-dropdown position="bottom start" class="citation">')
        ->toContain('<button type="button" class="citation-chip" aria-label="Passaggio citato regolamento-ferie-003, da regolamento-ferie.pdf">regolamento-ferie-003</button>')
        ->toContain('<span popover="manual" class="citation-popover">')
        ->toContain('<span class="citation-file">regolamento-ferie.pdf</span>')
        ->toContain('<span class="citation-text">Le ferie si chiedono con 15 giorni di anticipo.</span>')
        ->not->toContain('[regolamento-ferie-003]');
});

test('anche più id tra le stesse parentesi diventano chip', function () {
    $sources = [
        ['id' => 'ferie-001', 'document_id' => 'ferie', 'filename' => 'ferie.md', 'text' => 'Primo.'],
        ['id' => 'ferie-002', 'document_id' => 'ferie', 'filename' => 'ferie.md', 'text' => 'Secondo.'],
    ];

    $html = (string) answer('Vedi [ferie-001, ferie-002] e [ferie-002; ferie-009].', $sources)->answerHtml();

    expect(substr_count($html, 'class="citation-chip"'))->toBe(3)
        ->and($html)->toContain('[ferie-009]');
});

test('un id senza fonte resta testo, anche se ha la forma giusta', function () {
    $html = (string) answer('Lo dice il regolamento [regolamento-ferie-999].')->answerHtml();

    expect($html)
        ->toContain('[regolamento-ferie-999]')
        ->not->toContain('citation-chip');
});

test('un id dentro il codice resta codice', function () {
    $html = (string) answer('Il formato è `[regolamento-ferie-003]`.')->answerHtml();

    expect($html)
        ->toContain('<code>[regolamento-ferie-003]</code>')
        ->not->toContain('citation-chip');
});

test('il markdown si vede, ma HTML grezzo e link javascript no', function () {
    $html = (string) answer(<<<'MD'
        **Ferie**: 15 giorni di anticipo.

        <script>alert('risposta')</script>

        <img src="x" onerror="alert('img')">

        [clicca qui](javascript:alert('link'))
        MD)->answerHtml();

    expect($html)
        ->toContain('<strong>Ferie</strong>')
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('onerror')
        ->not->toContain('javascript:');
});

test('il passaggio citato viene da un documento caricato: si scappa', function () {
    $html = (string) answer('Vedi [doc-001].', [[
        'id' => 'doc-001',
        'document_id' => 'doc',
        'filename' => 'trappola"><script>alert(1)</script>.html',
        'text' => '<img src=x onerror="alert(2)"> testo',
    ]])->answerHtml();

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->toContain('trappola&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;.html')
        ->toContain('&lt;img src=x onerror=&quot;alert(2)&quot;&gt; testo');
});

test('nella pagina della chat la risposta con <script> e un [id] arriva scappata', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for(Agent::factory()->for($user))->create();
    Message::factory()->assistant()->for($conversation)->create([
        'content' => "Risposta <script>alert('xss')</script> con fonte [doc-001] e id inventato [doc-404].",
        'route' => 'documenti',
        'tools' => [['name' => 'cerca_documenti', 'arguments' => ['domanda' => '<b>ferie</b>']]],
        'sources' => [[
            'id' => 'doc-001',
            'document_id' => 'doc',
            'filename' => 'doc.html',
            'text' => '<script>alert("passaggio")</script>',
        ]],
    ]);

    $this->actingAs($user)
        ->get(route('agents.chat', ['agent' => $conversation->agent, 'conversation' => $conversation]))
        ->assertOk()
        ->assertDontSee("<script>alert('xss')</script>", false)
        ->assertDontSee('<script>alert("passaggio")</script>', false)
        ->assertSee('&lt;script&gt;alert(&quot;passaggio&quot;)&lt;/script&gt;', false)
        ->assertSee('&lt;b&gt;ferie&lt;/b&gt;', false)
        ->assertSee('[doc-404]')
        ->assertSee('class="citation-chip"', false);
});

test('ogni strada ha il suo colore nella traccia', function () {
    $colors = array_map(fn (AnswerRoute $route) => $route->color(), AnswerRoute::cases());

    expect(array_unique($colors))->toHaveCount(count(AnswerRoute::cases()));
});
