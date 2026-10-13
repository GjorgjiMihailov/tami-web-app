<?php

namespace App\Services\Bank;

/** Што се случило со еден фајл при увоз; се чува како низа во Livewire. */
final class Mt940ImportOutcome
{
    /** @param  list<string>  $warnings */
    private function __construct(
        public readonly bool $ok,
        public readonly string $file,
        public readonly string $message,
        public readonly array $warnings = [],
        public readonly ?int $statementId = null,
        public readonly int $lines = 0,
    ) {}

    /** @param  list<string>  $warnings */
    public static function created(string $file, string $message, int $statementId, int $lines, array $warnings): self
    {
        return new self(true, $file, $message, $warnings, $statementId, $lines);
    }

    public static function rejected(string $file, string $message): self
    {
        return new self(false, $file, $message);
    }

    /** @return array{ok: bool, file: string, message: string, warnings: list<string>, statement_id: ?int, lines: int} */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'file' => $this->file,
            'message' => $this->message,
            'warnings' => $this->warnings,
            'statement_id' => $this->statementId,
            'lines' => $this->lines,
        ];
    }
}
