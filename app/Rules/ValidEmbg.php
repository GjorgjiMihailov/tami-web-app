<?php

namespace App\Rules;

use App\Support\Embg;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidEmbg implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Embg::isValid($value)) {
            $fail('ЕМБГ не е валиден — проверете ги 13-те цифри.');
        }
    }
}
