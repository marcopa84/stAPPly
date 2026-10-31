<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Support\DemoAccount;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profilo')]
class Profile extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        // Cambiare l'email dell'utente demo lascerebbe fuori tutti, come la password.
        DemoAccount::abortIfDemo($user);

        // In minuscolo come la salvano registrazione, login e recupero password di Fortify
        // (lowercase_usernames): con le maiuscole il login non la troverebbe più, e
        // l'unique non vedrebbe un doppione scritto in un altro modo.
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }

    public function render(): View
    {
        if (DemoAccount::is(Auth::user())) {
            return view('livewire.settings.demo-locked', [
                'heading' => 'Profilo',
                'subheading' => 'Nome ed email',
            ]);
        }

        return view('livewire.settings.profile');
    }
}
