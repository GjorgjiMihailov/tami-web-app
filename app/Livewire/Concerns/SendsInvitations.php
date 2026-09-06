<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\UserInvitations;

/**
 * За екрани што отвораат/поканат корисник на портал и мора веднаш да го
 * прикажат линкот на поканата. Го дели екранот на профил на клиент
 * (App\Livewire\CompanyUsers) и екранот на сметки на канцеларијата — истата
 * логика, иста порака, на едно место.
 */
trait SendsInvitations
{
    /** Линкот се гледа само еднаш, веднаш по создавањето — не се чува. */
    public ?string $inviteLink = null;

    public string $invitedName = '';

    public bool $inviteMailSent = false;

    /**
     * Поканата се прави прва, пораката втора: испраќањето по е-пошта е обид, не
     * услов. Ако поштата на серверот не е поставена, сметката и линкот остануваат.
     */
    private function sendInvitation(User $user): void
    {
        $this->inviteLink = UserInvitations::issue($user, auth()->user());
        $this->invitedName = $user->name;

        try {
            $user->notify(new UserInvitationNotification($this->inviteLink));
            $this->inviteMailSent = true;
        } catch (\Throwable $e) {
            report($e);
            $this->inviteMailSent = false;
        }
    }
}
