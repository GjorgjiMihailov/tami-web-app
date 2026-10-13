<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\Company;
use App\Models\Partner;
use App\Support\Bank\AccountNumber;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use App\Support\Bank\Mt940\Mt940Line;

/**
 * Ги пополнува ставките од увоз од она што е запомнето по сметка на другата
 * страна и насока. Резултатот е секогаш „проверете“, никогаш „сигурно“: истото
 * лице може да е и заемодавач и купувач, а ова препознавање не знае дали
 * ставката плаќа фактура — тоа е задача на матчерот за фактури.
 *
 * Затоа, ако во описот или повикувањето на број има знак за фактура, конто не
 * се нуди: ставката останува нејасна за човек да ја реши.
 */
class BankCounterpartyRecognizer
{
    public const SOURCE = 'memory';

    /** „faktura“, „fakt.“, „fra“, „ф-ра“, „фактура“, „invoice“, „inv“ како цели зборови. */
    private const INVOICE_MARKER = '/(?<![\p{L}\p{N}])(fakt\p{L}*|fra|inv|invoice|ф-?ра|фактур\p{L}*)(?![\p{L}\p{N}])/iu';

    /**
     * Атрибути за спојување со новата ставка; празна низа = непознато.
     *
     * @return array<string, mixed>
     */
    public function attributesFor(Company $company, Mt940Line $line): array
    {
        $key = AccountNumber::key((string) $line->counterpartyAccount);

        if ($key === '') {
            return [];
        }

        $record = BankCounterparty::where('company_id', $company->id)
            ->where('account_key', $key)
            ->where('direction', $line->direction->value)
            ->first();

        if ($record === null) {
            return [];
        }

        // Запомнето конто или партнер што веќе не е валидно (избришано, од друга
        // фирма, неактивно, станало наслов) се игнорира, не се пренесува.
        $account = $record->account_id !== null
            ? Account::where('company_id', $company->id)->analytical()->where('is_active', true)->find($record->account_id)
            : null;
        $partner = $record->partner_id !== null
            ? Partner::where('company_id', $company->id)->find($record->partner_id)
            : null;

        if ($account !== null && $this->mentionsInvoice($line)) {
            return [
                'partner_id' => $partner?->id,
                'match_reason' => 'Во описот има фактура — може да е плаќање на фактура, не на запомнатото конто. Проверете.',
            ];
        }

        if ($account !== null) {
            return [
                'kind' => LineKind::ACCOUNT,
                'account_id' => $account->id,
                'partner_id' => $partner?->id,
                'match_confidence' => MatchConfidence::CHECK,
                'match_reason' => "Запомнето конто {$account->code} од претходно книжење.",
                'match_source' => self::SOURCE,
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

    private function mentionsInvoice(Mt940Line $line): bool
    {
        $text = trim(($line->description ?? '').' '.($line->referenceNumber ?? ''));

        return $text !== '' && preg_match(self::INVOICE_MARKER, $text) === 1;
    }
}
