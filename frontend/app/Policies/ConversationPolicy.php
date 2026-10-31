<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Una conversazione è solo di chi l'ha aperta, e solo finché può vedere l'agente:
 * vale anche per gli agenti demo, con cui si chatta come con gli altri.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    /**
     * Scrivere nella conversazione.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    private function owns(User $user, Conversation $conversation): bool
    {
        return $conversation->user()->is($user) && $user->can('view', $conversation->agent);
    }
}
