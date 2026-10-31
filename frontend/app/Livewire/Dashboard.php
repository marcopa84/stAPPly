<?php

namespace App\Livewire;

use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(BackendClient $backend): View
    {
        try {
            $backendOnline = $backend->health();
            $backendError = $backendOnline ? null : 'Il backend risponde ma non si dichiara pronto: controlla il suo log.';
        } catch (BackendException $e) {
            $backendOnline = false;
            $backendError = $e->getMessage();
        }

        $user = Auth::user();

        return view('dashboard', [
            'agents' => $user->agents()->demoFirst()->get(),
            'conversations' => $user->conversations()->with('agent')->latest('updated_at')->latest('id')->take(5)->get(),
            'backendOnline' => $backendOnline,
            'backendError' => $backendError,
        ]);
    }
}
