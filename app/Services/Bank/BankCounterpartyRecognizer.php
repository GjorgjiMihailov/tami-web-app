<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\Company;
use App\Models\Partner;
use App\Support\Bank\AccountNumber;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;

/**
 * Ги пополнува ставките од увоз од она што е запомнето по сметка на другата
 * страна. Резултатот е секогаш „проверете“, никогаш „сигурно“: истото лице
 * може да е и заемодавач и купувач, а ова препознавање не знае да каже дали
 * ставката плаќа фактура — тоа е задача на матчерот за фактури.
 */
class BankCounterpartyRecognizer
{
    /**
     * Атрибути за спојување со новата ставка; празна низа = непознато.
     *
     * @return array<string, mixed>
     */
    public function attributesFor(Company $company, ?string $counterpartyAccount): array
    {
        $key = AccountNumber::key((string) $counterpartyAccount);

        if ($key === '') {
            return [];
        }

        $record = BankCounterparty::where('company_id', $company->id)->where('account_key', $key)->first();

        if ($record === null) {
            return [];
        }

        // Запомнето конто или партнер што веќе не е валидно (избришано, од друга
        // фирма, станало наслов) се игнорира, не се пренесува.
        $account = $record->account_id !== null
            ? Account::where('company_id', $company->id)->analytical()->find($record->account_id)
            : null;
        $partner = $record->partner_id !== null
            ? Partner::where('company_id', $company->id)->find($record->partner_id)
            : null;

        if ($account !== null) {
            return [
                'kind' => LineKind::ACCOUNT,
                'account_id' => $account->id,
                'partner_id' => $partner?->id,
                'match_confidence' => MatchConfidence::CHECK,
                'match_reason' => "Запомнето конто {$account->code} од претходно книжење.",
            ];
        }

        if ($partner !== null) {
            return [
                'partner_id' => $partner->id,
                'match_confidence' => MatchConfidence::UNCLEAR,
                'match_reason' => "Запомнат партнер: {$partner->name}.",
            ];
        }

        return [];
    }
}
