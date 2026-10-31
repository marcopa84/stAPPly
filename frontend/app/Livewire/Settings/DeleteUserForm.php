<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Services\Agents\AgentPurger;
use App\Services\Backend\BackendException;
use App\Support\DemoAccount;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Elimina l'account. Prima gli agenti, uno alla volta e ognuno prima nel backend
     * (documenti, tabelle, sessioni): il cascade del DB toglierebbe solo la parte di
     * Laravel. Se il backend non risponde l'account resta, senza gli agenti già
     * cancellati, e si può riprovare.
     */
    public function deleteUser(Logout $logout, AgentPurger $agents): void
    {
        // Con l'utente demo sparirebbero anche gli agenti demo, per tutti.
        DemoAccount::abortIfDemo(Auth::user());

        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        try {
            foreach ($user->agents()->get() as $agent) {
                $agents->purge($agent);
            }
        } catch (BackendException $e) {
            $this->addError('password', "L'account non è stato eliminato. {$e->getMessage()}");

            return;
        }

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}
