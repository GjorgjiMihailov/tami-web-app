<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\BankLineRule;
use App\Models\Company;
use App\Models\Partner;
use App\Support\Bank\AccountNumber;
use App\Support\Bank\BankLinePattern;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use App\Support\Bank\Mt940\Mt940Line;
use Illuminate\Support\Collection;

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

        $rules = BankLineRule::where('company_id', $company->id)
            ->where('account_key', $key)
            ->where('direction', $line->direction->value)
            ->get();

        if ($record === null && $rules->isEmpty()) {
            return [];
        }

        // Запомнето конто или партнер што веќе не е валидно (избришано, од друга
        // фирма, неактивно, станало наслов) се игнорира, не се пренесува.
        [$account, $accountReason, $exactRule] = $this->suggestedAccount($company, $record, $rules, $line);
        $partnerId = $record?->partner_id ?? $exactRule?->partner_id;
        $partner = $partnerId !== null
            ? Partner::where('company_id', $company->id)->find($partnerId)
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
                'match_reason' => $accountReason,
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

    /**
     * Конто за предлог по ред: правило за истиот образец на текст; конто на кое
     * оваа сметка досега секогаш одела; (само ако нема правила) старото конто на
     * сметката. Сметка што одела на различни конта (банката) не добива предлог за
     * непознат текст — подобро ништо отколку погрешно.
     *
     * @param  Collection<int, BankLineRule>  $rules
     * @return array{0: ?Account, 1: ?string, 2: ?BankLineRule}
     */
    private function suggestedAccount(Company $company, ?BankCounterparty $record, $rules, Mt940Line $line): array
    {
        $pattern = BankLinePattern::from($line->description);
        $exact = $pattern !== null ? $rules->firstWhere('pattern', $pattern) : null;

        if ($exact !== null) {
            $account = $this->validAccount($company, $exact->account_id);

            return [$account, $account ? "Запомнето за слична ставка: конто {$account->code}." : null, $exact];
        }

        if ($rules->isNotEmpty()) {
            $ids = $rules->pluck('account_id')->unique()->values();
            $account = $ids->count() === 1 ? $this->validAccount($company, $ids->first()) : null;

            return [$account, $account ? "Оваа сметка досега секогаш на конто {$account->code}." : null, null];
        }

        $account = $this->validAccount($company, $record?->account_id);

        return [$account, $account ? "Запомнето конто {$account->code} од претходно книжење." : null, null];
    }

    private function validAccount(Company $company, ?int $accountId): ?Account
    {
        return $accountId === null
            ? null
            : Account::where('company_id', $company->id)->analytical()->where('is_active', true)->find($accountId);
    }

    private function mentionsInvoice(Mt940Line $line): bool
    {
        $text = trim(($line->description ?? '').' '.($line->referenceNumber ?? ''));

        return $text !== '' && preg_match(self::INVOICE_MARKER, $text) === 1;
    }
}
