<?php

use App\Livewire\Agents\Chat;
use App\Livewire\Agents\Create;
use App\Livewire\Agents\Documents;
use App\Livewire\Agents\Tables;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Un utente con un agente il cui backend_id è prevedibile, per le risposte finte.
 *
 * @return array{0: User, 1: Agent}
 */
function ownerWithAgent(User $user, string $backendId): array
{
    return [$user, Agent::factory()->for($user)->create(['backend_id' => $backendId])];
}

beforeEach(function () {
    // Per ogni agente i file degli esempi del contratto (2 documenti, 1 tabella); ogni upload riesce.
    fakeBackend([
        'GET agents/*/documents' => Http::response(contract('documents.list.200')),
        'GET agents/*/tables' => Http::response(contract('tables.list.200')),
        'POST agents/*/documents' => Http::response(contract('documents.upload.201'), 201),
        'POST agents/*/tables' => Http::response(contract('tables.upload.201'), 201),
    ]);
});

function uploadsSent(): int
{
    return Http::recorded()->filter(fn (array $pair) => $pair[0]->method() === 'POST')->count();
}

describe('agenti', function () {
    test('una sandbox non crea più agenti del limite della demo', function () {
        $sandbox = User::factory()->demoSandbox()->create();
        Agent::factory()->for($sandbox)->count(4)->create();

        $this->actingAs($sandbox)
            ->get(route('agents.create'))
            ->assertSee('Nella demo puoi avere al massimo 4 agenti: eliminane uno per crearne un altro.');

        Livewire::actingAs($sandbox)
            ->test(Create::class)
            ->set('form.name', 'Il quinto')
            ->set('form.instructions', 'Rispondi in italiano.')
            ->call('save')
            ->assertNoRedirect();

        expect($sandbox->agents()->count())->toBe(4);
    });

    test('sotto il limite, e per gli utenti registrati, si crea come sempre', function (User $user, int $existing) {
        Agent::factory()->for($user)->count($existing)->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', 'Un altro agente')
            ->set('form.instructions', 'Rispondi in italiano.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect($user->agents()->count())->toBe($existing + 1);
    })->with([
        'sandbox sotto il limite' => [fn () => User::factory()->demoSandbox()->create(), 3],
        'utente registrato' => [fn () => User::factory()->create(), 6],
    ]);
});

describe('file', function () {
    test('una sandbox non carica oltre il numero di file della demo', function (string $component, string $limit, UploadedFile $file, string $message) {
        // Con i file degli esempi del contratto il limite è già pieno.
        Config::set($limit, $component === Documents::class ? 2 : 1);
        [$sandbox, $agent] = ownerWithAgent(User::factory()->demoSandbox()->create(), 'copia-demo');

        Livewire::actingAs($sandbox)
            ->test($component, ['agent' => $agent])
            ->set('file', $file)
            ->call('upload')
            ->assertHasErrors('file')
            ->assertSee($message);

        expect(uploadsSent())->toBe(0);
    })->with([
        'documenti' => [Documents::class, 'stapply.demo.max_documents', UploadedFile::fake()->createWithContent('nuovo.txt', 'testo'), 'Nella demo un agente può avere al massimo 2 documenti'],
        'tabelle' => [Tables::class, 'stapply.demo.max_tables', UploadedFile::fake()->createWithContent('nuova.csv', "a,b\n1,2\n"), 'Nella demo un agente può avere al massimo 1 tabelle'],
    ]);

    test('una sandbox ha un limite di dimensione più basso, gli utenti registrati no', function () {
        $big = fn () => UploadedFile::fake()->create('grande.pdf', 3 * 1024, 'application/pdf');

        [$sandbox, $copy] = ownerWithAgent(User::factory()->demoSandbox()->create(), 'copia-demo');

        // Il file si ferma appena scelto, senza arrivare all'upload.
        Livewire::actingAs($sandbox)
            ->test(Documents::class, ['agent' => $copy])
            ->assertSee('fino a 2 MB')
            ->assertSee('2 di 10')
            ->set('file', $big())
            ->assertHasErrors(['file' => 'max']);

        [$user, $agent] = ownerWithAgent(User::factory()->create(), 'agente-di-prova');

        Livewire::actingAs($user)
            ->test(Documents::class, ['agent' => $agent])
            ->assertSee('fino a 10 MB')
            ->set('file', $big())
            ->call('upload')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://backend.test/agents/agente-di-prova/documents');
        expect(uploadsSent())->toBe(1);
    });

    test('i caricamenti all\'ora sono contati per tutte le sandbox insieme', function () {
        Config::set('stapply.demo.uploads_per_hour', 1);
        $file = fn () => UploadedFile::fake()->createWithContent('nuovo.txt', 'testo');

        [$first, $firstCopy] = ownerWithAgent(User::factory()->demoSandbox()->create(), 'copia-uno');

        Livewire::actingAs($first)
            ->test(Documents::class, ['agent' => $firstCopy])
            ->set('file', $file())
            ->call('upload')
            ->assertHasNoErrors();

        [$second, $secondCopy] = ownerWithAgent(User::factory()->demoSandbox()->create(), 'copia-due');

        Livewire::actingAs($second)
            ->test(Documents::class, ['agent' => $secondCopy])
            ->set('file', $file())
            ->call('upload')
            ->assertHasErrors('file')
            ->assertSee('La demo ha raggiunto il limite di caricamenti per quest&#039;ora: riprova tra 60 minuti.', false);

        [$user, $agent] = ownerWithAgent(User::factory()->create(), 'agente-di-prova');

        Livewire::actingAs($user)
            ->test(Documents::class, ['agent' => $agent])
            ->set('file', $file())
            ->call('upload')
            ->assertHasNoErrors();

        expect(uploadsSent())->toBe(2);
    });
});

describe('chat', function () {
    test('i messaggi al minuto sono contati per tutte le sandbox insieme', function () {
        Config::set('stapply.demo.chat_messages_per_minute', 1);

        $send = function (User $user) {
            $agent = Agent::factory()->for($user)->create();
            $conversation = Conversation::factory()->for($agent)->create(['user_id' => $user->id]);

            return Livewire::actingAs($user)
                ->test(Chat::class, ['agent' => $agent, 'conversation' => $conversation])
                ->set('message', 'Quanti giorni di ferie ho?')
                ->call('send');
        };

        $send(User::factory()->demoSandbox()->create())->assertHasNoErrors();

        $send(User::factory()->demoSandbox()->create())
            ->assertHasErrors('message')
            ->assertSee('In questo momento la demo riceve troppi messaggi: riprova tra');

        // Gli utenti registrati hanno solo il loro limite.
        $send(User::factory()->create())->assertHasNoErrors();

        Http::assertNothingSent();
    });
});
