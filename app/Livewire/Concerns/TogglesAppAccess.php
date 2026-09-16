<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Support\Facades\Gate;

/**
 * Штиклирањето „во која апликација влегува овој човек". Правилото е исто на
 * двата екрана; разликува само опсегот — кој корисник смее да се допре — па него
 * го дава компонентата преку appAccessTarget().
 */
trait TogglesAppAccess
{
    public function toggleApp(int $userId, string $app): void
    {
        Gate::authorize('create', User::class);

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
}
