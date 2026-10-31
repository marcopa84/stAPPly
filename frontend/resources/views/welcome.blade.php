<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-screen bg-white text-zinc-600 antialiased dark:bg-zinc-900 dark:text-zinc-400">
        <header class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <x-app-logo :href="route('home')" />

            @if (Route::has('login'))
                <nav class="flex items-center gap-2" aria-label="Accesso">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" size="sm">{{ __('Dashboard') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>

                        @if (Route::has('register'))
                            <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Register') }}</flux:button>
                        @endif
                    @endauth
                </nav>
            @endif
        </header>

        <main>
            @if (request()->query('demo') === 'terminata')
                <div class="mx-auto max-w-5xl px-4 pt-4 sm:px-6">
                    <flux:callout variant="secondary" icon="information-circle" :heading="\App\Support\DemoAccount::ENDED_MESSAGE" />
                </div>
            @endif

            <section class="mx-auto grid max-w-5xl items-center gap-10 px-4 pt-8 pb-16 sm:px-6 lg:grid-cols-[3fr_2fr] lg:pt-16 lg:pb-24">
                <div>
                    <h1 class="text-5xl font-semibold tracking-tight text-zinc-900 sm:text-6xl dark:text-white">
                        <x-app-wordmark />
                    </h1>

                    <p lang="en" class="mt-4 text-xl font-medium text-zinc-900 sm:text-2xl dark:text-white">
                        Staple your docs. Apply your knowledge.
                    </p>

                    <p class="mt-5 max-w-xl text-base/7">
                        Crea un assistente che risponde partendo dai tuoi documenti e dalle tue tabelle,
                        e che ti mostra sempre da dove ha preso ogni risposta.
                    </p>

                    @if (Route::has('login'))
                        <div class="mt-8 flex flex-wrap gap-3">
                            @auth
                                <flux:button :href="route('dashboard')" variant="primary">Vai alla dashboard</flux:button>
                            @else
                                @if (Route::has('register'))
                                    <flux:button :href="route('register')" variant="primary">Crea il tuo account</flux:button>
                                @endif

                                <flux:button :href="route('login')">{{ __('Log in') }}</flux:button>
                            @endauth
                        </div>
                    @endif
                </div>

                <div class="flex justify-center">
                    <x-mascot state="idle" class="w-56 sm:w-72" />
                </div>
            </section>

            <section aria-labelledby="nome" class="border-y border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950/40">
                <div class="mx-auto grid max-w-5xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2">
                    <div>
                        <h2 id="nome" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Un nome pinzato</h2>

                        <p class="mt-4 text-base/7">
                            stAPPly unisce due parole inglesi: <strong lang="en" class="font-semibold text-zinc-900 dark:text-white">staple</strong>,
                            pinzare, e <strong lang="en" class="font-semibold text-zinc-900 dark:text-white">apply</strong>, applicare.
                            Si legge quasi come <em lang="en">staple</em>, ma dentro c'è <em lang="en">apply</em>.
                        </p>

                        <p class="mt-4 text-base/7">
                            La doppia «p» non è un refuso: è il punto in cui le due parole sono pinzate insieme.
                        </p>
                    </div>

                    <div class="flex flex-col justify-center gap-3 text-2xl font-medium tracking-tight sm:text-3xl" aria-hidden="true">
                        <p class="text-zinc-400 dark:text-zinc-500"><span class="text-zinc-900 dark:text-white">st</span>aple</p>
                        <p class="text-zinc-900 dark:text-white"><span class="text-zinc-400 dark:text-zinc-500">+</span> apply</p>
                        <p class="border-t border-zinc-300 pt-3 text-zinc-900 dark:border-zinc-700 dark:text-white">
                            <span class="text-zinc-400 dark:text-zinc-500">=</span> <x-app-wordmark />
                        </p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="storia" class="mx-auto max-w-5xl px-4 py-16 sm:px-6">
                <h2 id="storia" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Dalla graffetta alla pinzatrice</h2>

                <p class="mt-4 max-w-3xl text-base/7">
                    Nel 1997 l'assistente di Office spuntava nel mezzo del lavoro per offrire un aiuto
                    che nessuno aveva chiesto, senza capire cosa stavi facendo. stAPPly è il suo erede
                    intelligente: parla quando gli fai una domanda e risponde da quello che gli hai dato da leggere.
                </p>

                <p class="mt-4 max-w-3xl text-base/7">
                    Una graffetta tiene insieme i fogli in modo provvisorio; una pinzatrice li unisce davvero.
                    È quello che fa il <abbr title="Retrieval-Augmented Generation" class="no-underline">RAG</abbr>:
                    prende passaggi da documenti diversi e li unisce in una risposta sola, con le fonti in vista.
                </p>
            </section>

            <section aria-labelledby="come-funziona" class="mx-auto max-w-5xl px-4 pb-16 sm:px-6">
                <h2 id="come-funziona" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Come funziona</h2>

                <ol class="mt-8 grid gap-4 md:grid-cols-3">
                    @foreach ([
                        ['Crea un agente', 'Dagli un nome e le sue istruzioni: di cosa si occupa e come deve rispondere.'],
                        ['Carica documenti e tabelle', 'PDF, Word, Markdown, HTML e testo per i documenti; CSV ed Excel per i dati. Ogni agente vede solo i suoi file.'],
                        ['Chiedi', 'Ricevi risposte con le fonti citate e la traccia dei tool usati: sai sempre se ha cercato nei documenti, nei dati o in entrambi.'],
                    ] as [$step, $description])
                        <li class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-800">
                            <span class="flex size-8 items-center justify-center rounded-full bg-accent text-sm font-semibold text-accent-foreground">
                                {{ $loop->iteration }}
                            </span>
                            <h3 class="mt-4 font-semibold text-zinc-900 dark:text-white">{{ $step }}</h3>
                            <p class="mt-2 text-sm/6">{{ $description }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section aria-labelledby="mascotte" class="border-y border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950/40">
                <div class="mx-auto max-w-5xl px-4 py-16 sm:px-6">
                    <h2 id="mascotte" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">La pinzatrice</h2>

                    <p class="mt-4 max-w-2xl text-base/7">
                        Ti fa compagnia nella chat e ti fa capire a che punto è la risposta.
                    </p>

                    <ul class="mt-8 grid grid-cols-2 gap-6 lg:grid-cols-4">
                        @foreach ([
                            'idle' => ['Aspetta', 'Resta in attesa della tua domanda, senza interrompere.'],
                            'thinking' => ['Ci pensa', 'Preme a ritmo mentre cerca nei documenti e nei dati.'],
                            'happy' => ['Ha trovato', 'Quando la risposta è pronta, fa uscire un punto metallico.'],
                            'confused' => ['Non trova', 'Se la risposta non c\'è lo dice, e spiega dove ha cercato.'],
                        ] as $state => [$title, $description])
                            <li class="flex flex-col items-center text-center">
                                <x-mascot :state="$state" decorative class="w-28 sm:w-32" />
                                <h3 class="mt-4 font-semibold text-zinc-900 dark:text-white">{{ $title }}</h3>
                                <p class="mt-1 text-sm/6">{{ $description }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            <section aria-labelledby="demo" class="mx-auto max-w-5xl px-4 py-16 sm:px-6">
                <div class="grid gap-8 rounded-2xl border border-accent/30 bg-accent/5 p-6 sm:p-10 md:grid-cols-[3fr_2fr] md:items-center">
                    <div>
                        <h2 id="demo" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Prova la demo</h2>

                        <p class="mt-4 text-base/7">
                            Entra con l'utente demo: ricevi una copia tutta tua di due agenti già pronti, con documenti e
                            tabelle di prova. Uno risponde sulle regole del personale di un'azienda, l'altro sui servizi di
                            una biblioteca. Aziende, persone e numeri sono inventati.
                        </p>

                        <p class="mt-3 text-base/7">
                            Puoi modificare tutto: istruzioni, file, chat, agenti nuovi. Chi entra insieme a te ha la sua
                            copia, e al prossimo accesso riparti dai valori iniziali.
                        </p>

                        @if (Route::has('login'))
                            <flux:button :href="route('login', ['demo' => 1])" variant="primary" class="mt-6">Accedi alla demo</flux:button>
                        @endif
                    </div>

                    <dl class="grid gap-4 rounded-xl bg-white p-5 text-sm shadow-xs dark:bg-zinc-800">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                            <dd class="mt-1 font-mono text-base text-zinc-900 select-all dark:text-white">{{ config('stapply.demo.email', 'demo@stapply.test') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Password') }}</dt>
                            <dd class="mt-1 font-mono text-base text-zinc-900 select-all dark:text-white">{{ config('stapply.demo.password', 'stapply-demo') }}</dd>
                        </div>
                    </dl>
                </div>
            </section>
        </main>

        <footer class="border-t border-zinc-200 dark:border-zinc-800">
            <div class="mx-auto flex max-w-5xl flex-col gap-2 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <x-app-wordmark class="font-semibold text-zinc-900 dark:text-white" />
                <p lang="en">Staple your docs. Apply your knowledge.</p>
            </div>
        </footer>
    </body>
</html>
