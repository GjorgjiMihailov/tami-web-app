<?php

namespace App\Support\Payroll\Mpin;

readonly class MpinValidationResult
{
    /**
     * @param  list<string>  $errors    блокираат извоз
     * @param  list<string>  $warnings  се прикажуваат, не блокираат
     */
    public function __construct(
        public array $errors,
        public array $warnings,
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
