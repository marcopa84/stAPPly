@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent text-accent-foreground">
            <x-app-logo-icon class="size-5" />
        </x-slot>
        <x-slot name="name"><x-app-wordmark /></x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent text-accent-foreground">
            <x-app-logo-icon class="size-5" />
        </x-slot>
        <x-slot name="name"><x-app-wordmark /></x-slot>
    </flux:brand>
@endif
