<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

it('mostra la home con il nome, la tagline e le credenziali demo', function () {
    config(['stapply.demo.password' => 'pinzami-tutto']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Staple your docs. Apply your knowledge.')
        // "stAPPly" c'è anche nel <title>: qui si controlla che APP sia davvero evidenziato.
        ->assertSee('<span class="text-accent-content">APP</span>', false)
        ->assertSee('La doppia «p» non è un refuso')
        ->assertSee('demo@stapply.test')
        ->assertSee('pinzami-tutto');
});

it('offre accesso e registrazione agli ospiti', function () {
    $this->get(route('home'))
        ->assertSee(route('login'))
        ->assertSee(route('register'))
        ->assertDontSee(route('dashboard'));
});

it('porta alla dashboard chi ha già fatto l\'accesso', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee(route('dashboard'));
});

it('disegna la mascotte in ogni stato', function (string $state, string $label) {
    $html = Blade::render('<x-mascot :state="$state" class="size-12" />', ['state' => $state]);

    expect($html)
        ->toContain('data-state="'.$state.'"')
        ->toContain('role="img"')
        ->toContain('aria-label="'.$label.'"')
        ->toContain('class="mascot size-12"')
        ->not->toContain('aria-hidden');
})->with([
    'in attesa' => ['idle', 'La pinzatrice di stAPPly, in attesa'],
    'pensa' => ['thinking', 'La pinzatrice di stAPPly sta pensando'],
    'contenta' => ['happy', 'La pinzatrice di stAPPly è contenta'],
    'confusa' => ['confused', 'La pinzatrice di stAPPly è confusa'],
]);

it('nasconde la mascotte decorativa agli screen reader', function () {
    $html = Blade::render('<x-mascot state="thinking" decorative />');

    expect($html)
        ->toContain('aria-hidden="true"')
        ->not->toContain('role="img"')
        ->not->toContain('aria-label');
});

it('torna alla posa di riposo con uno stato sconosciuto', function () {
    $html = Blade::render('<x-mascot state="arrabbiata" />');

    expect($html)->toContain('data-state="idle"');
});

it('mostra il logo con la pinzatrice e il nome evidenziato', function () {
    $html = Blade::render('<x-app-logo href="/" />');

    expect($html)
        ->toContain('st<span class="text-accent-content">APP</span>ly')
        ->toContain('bg-accent text-accent-foreground');
});

it('non usa il nome della vecchia mascotte di Office nelle viste', function () {
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));

    $matches = collect(iterator_to_array($views))
        ->filter(fn (SplFileInfo $file) => str_contains(strtolower(file_get_contents($file->getPathname())), 'clippy'))
        ->keys();

    expect($matches)->toBeEmpty();
});
