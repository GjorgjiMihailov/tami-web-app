<?php

namespace App\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Дејствата на админот врз профил на порталот: линк за нова лозинка,
 * исклучување и бришење. Ги делат списокот „Клиенти" и страницата на
 * сметководител — иста логика, иста заштита, на едно место.
 *
 * Сè е строго админ-само и се проверува при секој повик: Livewire методите се
 * достапни преку жица без разлика што исцртува Blade.
 */
trait ManagesClientProfiles
{
    use SendsInvitations;

    /** @var array{kind: string, id: int, name: string}|null */
    public ?array $deleting = null;

    public string $deleteConfirmation = '';

    public ?string $profileError = null;

    private function ensureAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }

    /** Само сметки на сметководители и клиенти — никогаш админ. */
    private function managedUser(int $userId): User
    {
        return User::role(['accountant', 'internal_client', 'freelancer_client'])->findOrFail($userId);
    }

    /**
     * Линк за нова лозинка. Истиот еднократен линк како при поканата: важи 7
     * дена, се гледа само еднаш, а претходниот престанува да важи.
     */
    public function resetLink(int $userId): void
    {
        $this->ensureAdmin();
        $this->profileError = null;

        $user = $this->managedUser($userId);

        if ($user->disabled_at !== null) {
            $this->profileError = 'Сметката е исклучена. Прво вклучи ја, па испрати линк.';

            return;
        }

        Gate::authorize('invite', $user);

        $this->sendInvitation($user);
    }

    public function disableProfile(int $userId): void
    {
        $this->ensureAdmin();

        $user = $this->managedUser($userId);
        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => now()])->save();
    }

    public function enableProfile(int $userId): void
    {
        $this->ensureAdmin();

        $user = $this->managedUser($userId);
        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => null])->save();
    }

    public function requestDelete(string $kind, int $id): void
    {
        $this->ensureAdmin();
        $this->profileError = null;

        $name = match ($kind) {
            'accountant' => User::role('accountant')->findOrFail($id)->name,
            'company' => Company::findOrFail($id)->name,
            default => abort(404),
        };

        $this->deleting = ['kind' => $kind, 'id' => $id, 'name' => $name];
        $this->deleteConfirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->deleting = null;
        $this->deleteConfirmation = '';
        $this->resetErrorBag('deleteConfirmation');
    }

    /**
     * Бришењето е трајно. Се бара впишување на називот, зашто со фирма
     * заминуваат и сите нејзини фактури, книжења и документи.
     */
    public function confirmDelete(): void
    {
        $this->ensureAdmin();

        if ($this->deleting === null) {
            return;
        }

        if (trim($this->deleteConfirmation) !== $this->deleting['name']) {
            $this->addError('deleteConfirmation', 'Називот не се совпаѓа.');

            return;
        }

        try {
            DB::transaction(function () {
                if ($this->deleting['kind'] === 'accountant') {
                    $user = User::role('accountant')->findOrFail($this->deleting['id']);
                    abort_if($user->is(auth()->user()), 403);
                    $user->delete();

                    return;
                }

                $company = Company::findOrFail($this->deleting['id']);
                // Прво фирмата (со неа заминува сето што е внесено), потоа
                // нејзините сметки — колоната company_id кај корисникот се
                // празни, не бришат.
                $accounts = User::where('company_id', $company->id)->get();
                $company->delete();
                $accounts->each->delete();
            });
        } catch (QueryException $e) {
            // Сметка што внела книжења/документи во туѓа фирма не смее да се
            // избрише — кон нив покажуваат стварни записи.
            report($e);
            $this->profileError = 'Профилот има внесени записи и не може да се избрише. Исклучи го наместо тоа.';
            $this->cancelDelete();

            return;
        }

        $this->cancelDelete();
        $this->afterDelete();
    }

    protected function afterDelete(): void
    {
    }
}
