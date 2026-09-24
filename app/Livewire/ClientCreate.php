<?php

namespace App\Livewire;

use App\Livewire\Concerns\SendsInvitations;
use App\Models\Company;
use App\Models\User;
use App\Rules\ValidEmbg;
use App\Services\CompanyCreator;
use App\Support\CompanyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Страница за внес на нов профил, во еден чекор: сметководител, правно лице
 * или физичко лице. Клиентот се создава заедно со својата фирма, сметката за
 * најава и (по избор) сметководителот што го води — порано тоа беа два екрана
 * на две места.
 */
#[Layout('layouts.app')]
class ClientCreate extends Component
{
    use SendsInvitations;

    public const KINDS = [
        'smetkovoditel' => 'Нов сметководител',
        'pravno-lice' => 'Ново правно лице',
        'fizicko-lice' => 'Ново физичко лице',
    ];

    public string $kind = 'smetkovoditel';

    public string $name = '';

    public string $firmName = '';

    public string $taxId = '';

    public string $embg = '';

    public string $contactName = '';

    public string $email = '';

    public string $accountantId = '';

    public function mount(string $kind): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
        abort_unless(array_key_exists($kind, self::KINDS), 404);

        $this->kind = $kind;
    }

    public function save(): void
    {
        Gate::authorize('create', User::class);

        $emailRule = 'required|email|max:255|unique:users,email';
        $emailMessages = ['email.unique' => 'Оваа е-пошта веќе има сметка во порталот.'];

        if ($this->kind === 'smetkovoditel') {
            $validated = $this->validate([
                'name' => 'required|string|max:255',
                'firmName' => 'nullable|string|max:255',
                'email' => $emailRule,
            ], $emailMessages);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Str::random(64),
            ]);
            // firm_name не е во #[Fillable] на моделот.
            $user->forceFill(['firm_name' => $validated['firmName'] ?: null])->save();
            $user->assignRole('accountant');

            $this->sendInvitation($user);
            $this->reset(['name', 'firmName', 'email']);

            return;
        }

        Gate::authorize('create', Company::class);

        $type = $this->kind === 'pravno-lice' ? CompanyType::LEGAL : CompanyType::INDIVIDUAL;

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'taxId' => 'nullable|string|max:255',
            'embg' => $type->isIndividual() && $this->embg !== ''
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
            // Кај физичко лице фирмата и човекот се исто име.
            'contactName' => $type->isLegal() ? 'required|string|max:255' : 'nullable',
            'email' => $emailRule,
            'accountantId' => 'nullable|integer|exists:users,id',
        ], $emailMessages);

        $accountant = $validated['accountantId'] !== null && $validated['accountantId'] !== ''
            ? User::role('accountant')->findOrFail($validated['accountantId'])
            : null;

        $user = DB::transaction(function () use ($validated, $type, $accountant) {
            $company = CompanyCreator::create(
                $validated['name'],
                $type,
                $validated['taxId'] ?? null,
                $validated['embg'] ?? null,
                auth()->user(),
            );

            $accountant?->assignedCompanies()->syncWithoutDetaching([$company->id]);

            $user = User::create([
                'name' => $type->isLegal() ? $validated['contactName'] : $validated['name'],
                'email' => $validated['email'],
                'password' => Str::random(64),
            ]);
            // company_id не е во #[Fillable] на моделот.
            $user->forceFill(['company_id' => $company->id])->save();
            $user->assignRole($type->clientRole());

            return $user;
        });

        $this->sendInvitation($user);
        $this->reset(['name', 'taxId', 'embg', 'contactName', 'email', 'accountantId']);
    }

    public function render()
    {
        return view('livewire.client-create', [
            'title' => self::KINDS[$this->kind],
            'accountants' => User::role('accountant')->orderBy('name')->get(),
        ]);
    }
}
