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

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function invite(User $user, User $target): bool
    {
        // Покана за исклучена сметка не може да се прифати (UserInvitations::accept),
        // па не смее ни да се издаде — инаку екранот ветува линк што не работи.
        return $user->hasRole('admin') && $target->disabled_at === null;
    }

    /**
     * Админ не може да си го одземе сопствениот пристап — тоа е единствениот
     * начин да се остане без ниту една сметка што може да отвора сметки.
     */
    public function disable(User $user, User $target): bool
    {
        return $user->hasRole('admin') && ! $user->is($target);
    }
}
