<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="$heading" :subheading="$subheading">
        <flux:callout variant="warning" icon="lock-closed" class="mt-6" :heading="\App\Support\DemoAccount::MESSAGE" />
    </x-settings.layout>
</section>
