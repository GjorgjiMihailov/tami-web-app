<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * „Наскоро" страница за поставките на сметководителот на ниво на канцеларија
 * (Шеми за книжење). Стои на порталот, без фирма: овие поставки
 * важат за сите фирми на сметководителот, па нема која фирма да се бара.
 */
#[Layout('layouts.app')]
class OfficeComingSoon extends Component
{
    public const FEATURES = [
        'semi-za-knizenje' => [
            'label' => 'Шеми за книжење',
            'sentence' => 'Овде сметководителот ќе ги подготвува шемите за книжење, едни за сите свои фирми.',
        ],
    ];

    public string $featureLabel = '';

    public string $featureSentence = '';

    public function mount(string $feature): void
    {
        abort_unless(auth()->user()->hasRole('accountant'), 403);
        abort_unless(array_key_exists($feature, self::FEATURES), 404);

        $this->featureLabel = self::FEATURES[$feature]['label'];
        $this->featureSentence = self::FEATURES[$feature]['sentence'];
    }

    public function render()
    {
        return view('livewire.office-coming-soon');
    }
}
