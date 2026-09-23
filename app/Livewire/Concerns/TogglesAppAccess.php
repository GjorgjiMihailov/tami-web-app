<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\PortalApp;

/**
 * Штиклирањето „во која апликација влегува овој човек". Механизмот е ист на
 * двата екрана (CompanyUsers, OfficeUsers); опсегот (кој корисник смее да се
 * допре) го дава appAccessTarget(), а ПРАВОТО (смее ли воопшто актерот) го
 * дава authorizeAppAccessChange() — намерно ОДДЕЛНО за секој екран.
 *
 * Не смее да има заедничка проверка тука: CompanyUsers сега е отворен за
 * сметководител на таа фирма (CompanyPolicy::update), а OfficeUsers мора да
 * остане строго админ-само — заедничка проверка би значела дека проширување
 * на едната автоматски протекува во другата.
 */
trait TogglesAppAccess
{
    public function toggleApp(int $userId, string $app): void
    {
        $this->authorizeAppAccessChange();

        $portalApp = PortalApp::tryFrom($app);

        // Порталот нема квадратче: најавен човек мора да има каде да влезе.
        abort_if($portalApp === null || $portalApp->userColumn() === null, 403);

        $user = $this->appAccessTarget($userId);
        $column = $portalApp->userColumn();

        $user->forceFill([$column => ! $user->{$column}])->save();
    }

    /**
     * Корисникот што овој екран смее да го менува. Мора да фрли (404/403) за
     * секој што е надвор од неговиот опсег.
     */
    abstract protected function appAccessTarget(int $userId): User;

    /**
     * Смее ли актерот воопшто да го допре квадратчето на овој екран. Секој
     * екран го дава своето — ова НЕ смее да биде заедничка, тврдо вградена
     * проверка во трејтот.
     */
    abstract protected function authorizeAppAccessChange(): void;
}
