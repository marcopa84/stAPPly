<section class="w-full">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('agents.index')" wire:navigate>Agenti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $agent->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" class="truncate">{{ $agent->name }}</flux:heading>

                @if ($agent->is_demo)
                    <flux:badge size="sm" color="amber">Demo</flux:badge>
                @endif
            </div>

            @if ($agent->description)
                <flux:subheading size="lg">{{ $agent->description }}</flux:subheading>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="primary" icon="chat-bubble-left-right" :href="route('agents.chat', $agent)" wire:navigate>
                Chat
            </flux:button>

            <flux:button icon="pencil-square" :href="route('agents.edit', $agent)" wire:navigate>
                Modifica
            </flux:button>
        </div>
    </div>

    <flux:navbar class="mt-4 border-b border-zinc-200 dark:border-zinc-700" aria-label="Sezioni dell'agente">
        @foreach ($sections as $key => $item)
            <flux:navbar.item
                :href="$this->sectionUrl($key)"
                :icon="$item['icon']"
                :current="$key === $section"
                wire:navigate
                wire:key="section-{{ $key }}"
            >
                {{ $item['label'] }}
            </flux:navbar.item>
        @endforeach
    </flux:navbar>

    <div class="mt-6">
        <livewire:dynamic-component :is="$current['component']" :agent="$agent" :key="$section" />
    </div>
</section>
