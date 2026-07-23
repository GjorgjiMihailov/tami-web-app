<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use Livewire\Component;

class Sidebar extends Component
{
    public function render()
    {
        $company = request()->route('company');

        return view('livewire.layout.sidebar', [
            'company' => $company instanceof Company ? $company : null,
        ]);
    }
}
