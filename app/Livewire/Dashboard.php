<?php

namespace App\Livewire;

use App\Livewire\Concerns\UpdatesCompanyLimit;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Таблото на админот; за сите други — насочувач.
 *
 * Админот никогаш не бира фирма при најава: тој не работи сметководство,
 * туку го води системот, па добива сопствено табло (профили, лимити,
 * состојба на сметките, активност). Фирма отвора по потреба, од „Фирми".
 *
 * Останатите немаат Почетна и Фирми во менито, но најавата сепак води тука
 * (види resources/views/livewire/pages/auth/login.blade.php). Одлуката за
 * дестинацијата живее на едно место, наместо расфрлена низ четирите Volt
 * страници за најава што сите пренасочуваат на 'dashboard'.
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    use UpdatesCompanyLimit;

    private const ROLE_LABELS = [
        'admin' => 'Админ',
        'accountant' => 'Сметководител',
        'internal_client' => 'Интерно сметководство',
        'freelancer_client' => 'Фриленсер',
    ];

    /** Колку долго по последното барање човек се смета за „активен сега". */
    private const ACTIVE_WINDOW_HOURS = 2;

    public function mount()
    {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return null;
        }

        // Сметководител што сè уште нема ниту еден клиент нема каде да отиде:
        // екранот „Изберете фирма" би му понудил само врска што за него враќа
        // 403. Го праќаме да го внесе првиот.
        if ($user->hasRole('accountant') && ! $user->visibleCompanies()->exists()) {
            return $this->redirect(route('onboarding.first-client'));
        }

        $target = $this->companyToOpen($user);

        // No target means an accountant with several companies and nothing
        // remembered — fall through and render the choice screen below.
        return $target ? $this->redirect(route('companies.dashboard', $target)) : null;
    }

    private function companyToOpen($user): ?Company
    {
        $visible = $user->visibleCompanies();

        $rememberedId = CurrentCompany::lastFor($user);

        if ($rememberedId !== null) {
            $remembered = (clone $visible)->whereKey($rememberedId)->first();

            if ($remembered) {
                return $remembered;
            }
        }

        $companies = (clone $visible)->orderBy('name')->get();

        return $companies->count() === 1 ? $companies->first() : null;
    }

    public function render()
    {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return view('livewire.admin-dashboard', $this->adminData());
        }

        $companies = $user->visibleCompanies()->orderBy('name')->get();

        return view('livewire.dashboard', ['companies' => $companies]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminData(): array
    {
        $users = User::with(['roles', 'company', 'latestInvitation'])->get();

        $withRole = fn (string $role) => $users->filter(fn (User $u) => $u->hasRole($role))->count();

        $statusCounts = $users->countBy(fn (User $u) => $u->accessStatus());

        // Сметките што бараат внимание: некој не ја прифатил поканата, поканата
        // истекла или сметката е исклучена.
        $needAttention = $users
            ->filter(fn (User $u) => $u->accessStatus() !== 'active')
            ->sortBy('name')
            ->values();

        // Само со база за сесии знаеме кој е активен. Со друг начин на чување
        // бројот би бил секогаш нула, а тоа би лажело — затоа екранот го кажува.
        $sessionsAvailable = config('session.driver') === 'database';

        $lastSeen = $sessionsAvailable
            ? DB::table('sessions')
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', now()->subHours(self::ACTIVE_WINDOW_HOURS)->getTimestamp())
                ->groupBy('user_id')
                ->selectRaw('user_id, max(last_activity) as last_activity')
                ->pluck('last_activity', 'user_id')
            : collect();

        return [
            'totals' => [
                'companies' => Company::count(),
                'legal' => Company::where('type', CompanyType::LEGAL->value)->count(),
                'individual' => Company::where('type', CompanyType::INDIVIDUAL->value)->count(),
                'accountants' => $withRole('accountant'),
                'admins' => $withRole('admin'),
                'internalClients' => $withRole('internal_client'),
                'freelancers' => $withRole('freelancer_client'),
                'needAttention' => $needAttention->count(),
            ],
            'accountants' => User::role('accountant')->withCount('assignedCompanies')->orderBy('name')->get(),
            'statusCounts' => $statusCounts,
            'needAttention' => $needAttention,
            'sessionsAvailable' => $sessionsAvailable,
            'lastSeen' => $lastSeen,
            'activeNow' => $users
                ->filter(fn (User $u) => $lastSeen->has($u->id))
                ->sortByDesc(fn (User $u) => $lastSeen[$u->id])
                ->values(),
            'recentLogins' => $users
                ->whereNotNull('last_login_at')
                ->sortByDesc('last_login_at')
                ->take(10)
                ->values(),
            'roleLabels' => self::ROLE_LABELS,
            'activeWindowHours' => self::ACTIVE_WINDOW_HOURS,
        ];
    }
}
