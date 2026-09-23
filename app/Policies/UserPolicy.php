<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Секој ја гледа листата на својата фирма — самиот екран потоа е ограничен
     * со CompanyPolicy::view врз фирмата.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Само за сметки на КАНЦЕЛАРИЈАТА (админи/сметководители) —
     * App\Livewire\OfficeUsers. Намерно останува админ-само и не се менува
     * со проширувањето подолу: сметка на канцеларија никогаш нема company_id,
     * па manages() below секогаш ѝ одбива на не-админ актер и без оваа
     * граница — но create() е засебна одлука (отворање НОВА сметка), не
     * управување со постојна, па останува чисто admin-само со свое, кратко
     * правило.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function invite(User $user, User $target): bool
    {
        // Покана за исклучена сметка не може да се прифати (UserInvitations::accept),
        // па не смее ни да се издаде — инаку екранот ветува линк што не работи.
        return $this->manages($user, $target) && $target->disabled_at === null;
    }

    /**
     * Админ не може да си го одземе сопствениот пристап — тоа е единствениот
     * начин да се остане без ниту една сметка што може да отвора сметки.
     */
    public function disable(User $user, User $target): bool
    {
        return $this->manages($user, $target) && ! $user->is($target);
    }

    /**
     * Админ секогаш. Сметководител — само врз клиентска сметка (company_id
     * не е null) на фирма на која тој работи. Сметка на канцеларија
     * (company_id === null) никогаш не поминува преку овој услов — останува
     * достижна само за админ, автоматски, без посебна проверка: токму затоа
     * App\Livewire\OfficeUsers останува безбеден и понатаму да ги користи
     * истите 'invite'/'disable' правила.
     */
    private function manages(User $user, User $target): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant')
            && $target->company_id !== null
            && $user->visibleCompanies()->whereKey($target->company_id)->exists();
    }
}
