<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\Menu;
use App\Support\PortalApp;
use App\Support\WorkingYear;
use Illuminate\Support\Str;
use Livewire\Component;

class Sidebar extends Component
{
    public ?Company $company = null;

    public ?string $expandedGroup = null;

    // Хостот е тој што ја одредува апликацијата, а /livewire/update доаѓа на
    // истиот хост — значи важи и при освежување на компонентата. Сепак се
    // запишува во својство при mount, за да не зависи render() од барањето.
    public string $appKey = 'portal';

    public int $workingYear = 0;

    /** @var list<int> */
    public array $availableYears = [];

    /** @var list<array{id: int, name: string}> */
    public array $companyOptions = [];

    /** @var list<array{key: string, label: string, items: list<array{label: string, url: string, pattern: string, soon: bool}>}> */
    public array $menu = [];

    // The name of the route the page was loaded on. Captured here, once,
    // because the /livewire/update POST carries no route at all — deriving
    // it in render() silently loses every highlight the moment a group is
    // toggled.
    public string $currentRoute = '';

    public string $brandUrl = '';

    public function mount(?Company $company = null): void
    {
        $company ??= request()->route('company');
        $this->company = $company instanceof Company ? $company : null;
        $this->currentRoute = (string) request()->route()?->getName();

        $app = PortalApp::fromHost(request()->getHost()) ?? PortalApp::PORTAL;
        $this->appKey = $app->value;

        $this->brandUrl = $app === PortalApp::PORTAL || ! $this->company
            ? route('dashboard')
            : (Menu::firstUrl(auth()->user(), $this->company, $app) ?? route('dashboard'));

        if (! $this->company) {
            return;
        }

        CurrentCompany::remember($this->company);

        $this->menu = Menu::for(auth()->user(), $this->company, $app);
        $this->expandedGroup = $this->groupMatchingCurrentRoute();
        $this->workingYear = WorkingYear::for($this->company);
        $this->availableYears = WorkingYear::availableYears($this->company);
        $this->companyOptions = auth()->user()->visibleCompanies()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    public function app(): PortalApp
    {
        return PortalApp::from($this->appKey);
    }

    // One string, computed here rather than split across a Blade @if between
    // two {{ }} echoes: Livewire wraps every @if block in
    // <!--[if BLOCK]><![endif]--> HTML comments for its DOM-diffing, even on
    // the very first render — so "{{ a }}@if(...) {{ b }}@endif" renders as
    // "a<!--comment--> b<!--comment-->", not "a b", and assertSee('a b')
    // never matches.
    public function headerLabel(): string
    {
        $name = config('app.name', 'Laravel');

        return $this->app() === PortalApp::PORTAL
            ? $name
            : $name.' '.$this->app()->label();
    }

    public function toggleGroup(string $group): void
    {
        $this->expandedGroup = $this->expandedGroup === $group ? null : $group;
    }

    public function isActive(string $pattern): bool
    {
        return $pattern !== '' && Str::is($pattern, $this->currentRoute);
    }

    // Livewire hands updated* hooks the raw incoming value, so cast before use
    // rather than type-hinting the parameter.
    public function updatedWorkingYear($value): void
    {
        $year = (int) $value;

        if (! $this->company || ! in_array($year, $this->availableYears, true)) {
            return;
        }

        WorkingYear::set($this->company, $year);

        $this->dispatch('working-year-changed', year: $year);
    }

    private function groupMatchingCurrentRoute(): ?string
    {
        foreach ($this->menu as $group) {
            foreach ($group['items'] as $item) {
                if ($this->isActive($item['pattern'])) {
                    return $group['key'];
                }
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.layout.sidebar');
    }
}
