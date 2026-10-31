<?php

use App\Livewire\Agents;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('agents', Agents\Index::class)->name('agents.index');
    Route::livewire('agents/create', Agents\Create::class)->name('agents.create');
    Route::livewire('agents/{agent}', Agents\Show::class)->name('agents.show');
    Route::livewire('agents/{agent}/edit', Agents\Edit::class)->name('agents.edit');
    Route::livewire('agents/{agent}/chat/{conversation?}', Agents\Chat::class)->name('agents.chat');
});

require __DIR__.'/settings.php';
