<?php

namespace App\Support\Ppf;

readonly class PpfValidationResult
{
    /**
     * @param  list<string>  $errors  блокираат извоз
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
