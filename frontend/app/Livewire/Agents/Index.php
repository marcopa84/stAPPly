<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agenti')]
class Index extends Component
{
    /**
     * @return Collection<int, Agent>
     */
    #[Computed]
    public function agents(): Collection
    {
        return Auth::user()->agents()->demoFirst()->get();
    }

    /**
     * Livewire 4 cercherebbe livewire/agents.blade.php per una classe Index.
     */
    public function render(): View
    {
        return view('livewire.agents.index');
    }
}
