<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="grid min-h-dvh lg:grid-cols-2">
            {{-- Il pannello del brand resta scuro anche con il tema chiaro: la classe dark vale solo qui dentro. --}}
            <div class="dark relative hidden flex-col justify-between gap-10 bg-zinc-900 p-10 text-zinc-400 lg:flex border-e border-neutral-800">
                <a href="{{ route('home') }}" class="flex items-center gap-3 self-start text-lg font-semibold text-white" wire:navigate>
                    <span class="flex size-9 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                        <x-app-logo-icon class="size-6" />
                    </span>
                    <x-app-wordmark />
                </a>

                <x-mascot class="mx-auto w-64 xl:w-80" decorative />

                <div>
                    <p lang="en" class="text-2xl font-medium tracking-tight text-white">Staple your docs. Apply your knowledge.</p>
                    <p class="mt-3 max-w-md text-sm/6">
                        Risposte che partono dai tuoi documenti e dalle tue tabelle, con le fonti sempre in vista.
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-center px-6 py-10 lg:px-8">
                <div class="flex w-full max-w-sm flex-col gap-6">
                    <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 lg:hidden" wire:navigate>
                        <span class="flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                            <x-app-logo-icon class="size-7" />
                        </span>
                        <x-app-wordmark class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white" />
                    </a>

                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
