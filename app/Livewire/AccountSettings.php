<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Account'])]
class AccountSettings extends Component
{
    public ?string $securityNotice = null;

    public ?string $securityError = null;

    public function deletePasskey(int $passkeyId, DeletePasskey $deletePasskey): void
    {
        $this->securityNotice = null;
        $this->securityError = null;

        $user = Auth::user();

        if ($user->passkeys()->count() <= 1) {
            $this->securityError = 'You must keep at least one passkey registered to sign in.';

            return;
        }

        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->whereKey($passkeyId)->firstOrFail();

        $deletePasskey($user, $passkey);

        $this->securityNotice = 'Passkey "'.$passkey->name.'" was removed.';
    }

    public function revokeSession(string $sessionId): void
    {
        $this->securityNotice = null;
        $this->securityError = null;

        $user = Auth::user();
        $isCurrent = $sessionId === Session::getId();

        if (! $user->sessions()->revoke($sessionId)) {
            $this->securityError = 'That session could not be found.';

            return;
        }

        if ($isCurrent) {
            Auth::logout();
            Session::invalidate();
            Session::regenerateToken();

            $this->redirect(route('login'), navigate: false);

            return;
        }

        $this->securityNotice = 'Session ended.';
    }

    public function revokeOtherSessions(): void
    {
        $this->securityNotice = null;
        $this->securityError = null;

        $count = Auth::user()->sessions()->revokeOthers(Session::getId());

        $this->securityNotice = $count === 0
            ? 'No other active sessions were found.'
            : 'Ended '.$count.' other session'.($count === 1 ? '' : 's').'.';
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.account-settings', [
            'passkeys' => $user->passkeys()->orderByDesc('created_at')->get(),
            'sessions' => $user->sessions()->latest('last_activity')->get(),
            'sessionDriver' => Config::string('session.driver'),
        ]);
    }
}
