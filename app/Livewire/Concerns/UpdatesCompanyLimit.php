<?php

namespace App\Livewire\Concerns;

use App\Models\User;

/**
 * Промена на лимитот на фирми кај сметководител. Ист код на два екрана
 * (Канцеларија и таблото на админот) — две копии би се разидувале, а овде
 * се работи за правило што само админ смее да го менува.
 */
trait UpdatesCompanyLimit
{
    public function updateCompanyLimit(int $userId, string $limit): void
    {
        // Проверката е ТУКА, не само во mount(): Livewire не го извршува
        // mount() повторно при дејство, а таблото го отвораат и други улоги.
        abort_unless(auth()->user()->hasRole('admin'), 403);

        $user = User::role(['accountant', 'admin'])->findOrFail($userId);

        abort_unless($user->hasRole('accountant'), 403);

        // Празно поле = неограничено (null).
        $trimmed = trim($limit);
        $value = $trimmed === '' ? null : max(0, (int) $trimmed);

        $user->forceFill(['company_limit' => $value])->save();
    }
}
