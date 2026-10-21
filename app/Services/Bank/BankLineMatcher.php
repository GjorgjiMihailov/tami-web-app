<?php

namespace App\Services\Bank;

use App\Models\Company;
use App\Support\Bank\InvoiceNumberText;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use App\Support\Bank\Mt940\Mt940Line;
use App\Support\Bank\NameSkeleton;
use Illuminate\Support\Collection;

/**
 * Врзува ставка од извод за отворена фактура. Не пишува во базата и не
 * одлучува конечно: враќа атрибути за ставката (вид, фактура, партнер, ознака и
 * причина), а човек ги потврдува дури кога е „проверете“.
 *
 * Правила по ред, првото што погодува одлучува:
 *  1. број на фактура во текстот → „сигурно“ (само една фактура и износот влегува во салдото);
 *  2. партнер познат од запомнета сметка + точно една фактура со салдо = износ → „сигурно“;
 *  3. име на партнер (само партнери со отворена фактура) → „проверете“;
 *  4. само износ (точно една отворена фактура) → „проверете“.
 * Ако распознавањето по сметка веќе предложи конто, правилата 3 и 4 не се
 * користат, а 2 само додава забелешка.
 *
 * Веќе внесено банкарско плаќање со ист износ се врзува (за да не се книжи
 * втор пат), но секогаш како „проверете“: погрешна врска тивко би испуштила плаќање.
 */
class BankLineMatcher
{
    public const SOURCE = 'invoice';

    public function session(Company $company): BankMatchSession
    {
        return new BankMatchSession($company);
    }

    /**
     * @param  array<string, mixed>  $recognized  што веќе го одредило препознавањето по сметка
     * @return array<string, mixed> атрибути за спојување со ставката; празна низа = непознато
     */
    public function attributesFor(BankMatchSession $session, Mt940Line $line, array $recognized = []): array
    {
        $open = $session->open($line->direction);

        if ($open->isEmpty()) {
            return [];
        }

        $amount = bcadd($line->amount, '0', 2);
        $text = trim(($line->description ?? '').' '.($line->referenceNumber ?? ''));
        $hasAccount = ($recognized['kind'] ?? null) === LineKind::ACCOUNT;

        $byNumber = $open->filter(fn (OpenInvoice $invoice) => $invoice->number !== null
            && InvoiceNumberText::contains($text, $invoice->number))->values();

        if ($byNumber->isNotEmpty()) {
            return $this->fromNumber($session, $line, $byNumber, $amount);
        }

        $partnerId = $recognized['partner_id'] ?? null;

        if ($partnerId !== null) {
            return $this->fromPartner(
                $session, $line, $open->where('partnerId', $partnerId)->values(), $amount,
                $recognized, MatchConfidence::SURE, null,
            );
        }

        if ($hasAccount) {
            return [];
        }

        $named = $open->filter(fn (OpenInvoice $invoice) => $invoice->partnerId !== null
            && NameSkeleton::same($line->counterpartyName, $invoice->partnerName))->values();

        if ($named->isNotEmpty() && $named->pluck('partnerId')->unique()->count() === 1) {
            return $this->fromPartner(
                $session, $line, $named, $amount, $recognized, MatchConfidence::CHECK,
                "Името одговара на партнерот {$named->first()->partnerName}.",
            );
        }

        $sameAmount = $open->filter(fn (OpenInvoice $invoice) => bccomp($invoice->balance, $amount, 2) === 0)->values();

        if ($sameAmount->count() === 1) {
            $invoice = $sameAmount->first();

            return $this->select($session, $line, $invoice, MatchConfidence::CHECK,
                "Само износот одговара: фактура {$invoice->label()} од {$invoice->partnerName}. Проверете дали уплатата е од нив.");
        }

        return [];
    }

    /** @param Collection<int, OpenInvoice> $found */
    private function fromNumber(BankMatchSession $session, Mt940Line $line, Collection $found, string $amount): array
    {
        if ($found->count() === 1) {
            $invoice = $found->first();
            $hasPayment = $this->equalPayments($invoice, $amount) !== [];
            $fits = bccomp($amount, $invoice->balance, 2) <= 0;

            if (! $fits && ! $hasPayment) {
                return $this->select($session, $line, $invoice, MatchConfidence::CHECK,
                    "Број {$invoice->label()} е наведен во описот, но износот ($amount) го надминува салдото ({$invoice->balance}).");
            }

            $partial = ! $hasPayment && bccomp($amount, $invoice->balance, 2) < 0
                ? " Делумно плаќање (салдо {$invoice->balance})."
                : '';

            return $this->select($session, $line, $invoice, MatchConfidence::SURE, "Број {$invoice->label()} е наведен во описот.$partial");
        }

        $labels = $found->map->label()->implode(', ');
        $exact = $found->filter(fn (OpenInvoice $invoice) => bccomp($invoice->balance, $amount, 2) === 0);

        if ($exact->count() === 1) {
            return $this->select($session, $line, $exact->first(), MatchConfidence::CHECK,
                "Во описот има повеќе броеви на фактури ($labels); избрана е онаа со салдо еднакво на износот.");
        }

        $attributes = [
            'match_confidence' => MatchConfidence::CHECK,
            'match_reason' => "Во описот има повеќе броеви на фактури ($labels) — изберете.",
            'match_source' => self::SOURCE,
        ];

        if ($found->pluck('partnerId')->unique()->count() === 1 && $found->first()->partnerId !== null) {
            $attributes['partner_id'] = $found->first()->partnerId;
        }

        return $attributes;
    }

    /**
     * @param  Collection<int, OpenInvoice>  $invoices  отворените фактури на партнерот
     * @param  array<string, mixed>  $recognized
     */
    private function fromPartner(
        BankMatchSession $session, Mt940Line $line, Collection $invoices, string $amount,
        array $recognized, MatchConfidence $confidence, ?string $prefix,
    ): array {
        if ($invoices->isEmpty()) {
            return [];
        }

        $hasAccount = ($recognized['kind'] ?? null) === LineKind::ACCOUNT;
        $exact = $invoices->filter(fn (OpenInvoice $invoice) => bccomp($invoice->balance, $amount, 2) === 0)->values();
        $lead = $prefix === null ? '' : $prefix.' ';

        if ($exact->count() === 1) {
            $invoice = $exact->first();

            if ($hasAccount) {
                return ['match_reason' => rtrim(($recognized['match_reason'] ?? '')." Фактура {$invoice->label()} на истиот партнер има салдо еднакво на износот — проверете.")];
            }

            return $this->select($session, $line, $invoice, $confidence,
                $lead."Фактура {$invoice->label()} на партнерот има салдо еднакво на износот.");
        }

        $hint = $exact->count() > 1
            ? 'Повеќе фактури имаат салдо еднакво на износот: '.$exact->map->label()->implode(', ').'.'
            : ($this->sumHint($invoices, $amount) ?? "Партнерот има {$invoices->count()} отворени фактури, но износот не одговара на ниедна.");

        if ($hasAccount) {
            return ['match_reason' => rtrim(($recognized['match_reason'] ?? '').' '.$hint)];
        }

        return [
            'partner_id' => $invoices->first()->partnerId,
            'match_confidence' => $exact->count() > 1 || $prefix !== null ? MatchConfidence::CHECK : ($recognized['match_confidence'] ?? MatchConfidence::UNCLEAR),
            'match_reason' => $lead.$hint,
            'match_source' => self::SOURCE,
        ];
    }

    /** Износот е збир на најстарите отворени фактури → совет за „Затвори повеќе фактури“. */
    private function sumHint(Collection $invoices, string $amount): ?string
    {
        $sum = '0.00';

        foreach ($invoices->values() as $position => $invoice) {
            $sum = bcadd($sum, $invoice->balance, 2);

            if ($position >= 1 && bccomp($sum, $amount, 2) === 0) {
                return 'Износот е збир на '.($position + 1).' најстари отворени фактури на партнерот — користете „Затвори повеќе фактури…“.';
            }
        }

        return null;
    }

    /** @return list<int> */
    private function equalPayments(OpenInvoice $invoice, string $amount): array
    {
        return array_keys(array_filter($invoice->payments, fn (string $paid) => bccomp($paid, $amount, 2) === 0));
    }

    private function select(BankMatchSession $session, Mt940Line $line, OpenInvoice $invoice, MatchConfidence $confidence, string $reason): array
    {
        $isIn = $line->direction === LineDirection::IN;
        $amount = bcadd($line->amount, '0', 2);
        $payments = $this->equalPayments($invoice, $amount);

        $attributes = [
            'kind' => LineKind::INVOICE_PAYMENT,
            'account_id' => null,
            'partner_id' => $invoice->partnerId,
            ($isIn ? 'sales_invoice_id' : 'purchase_invoice_id') => $invoice->id,
            'match_source' => self::SOURCE,
        ];

        if (count($payments) === 1) {
            $attributes[$isIn ? 'sales_invoice_payment_id' : 'purchase_invoice_payment_id'] = $payments[0];
            $session->takePayment($invoice, $payments[0]);
            $confidence = MatchConfidence::CHECK;
            $reason .= ' Врзано за веќе внесеното плаќање со истиот износ — проверете.';
        } elseif (count($payments) > 1) {
            $confidence = MatchConfidence::CHECK;
            $reason .= ' Има повеќе внесени плаќања со истиот износ — изберете кое.';
        } else {
            $session->reserve($invoice, $amount);
        }

        return $attributes + ['match_confidence' => $confidence, 'match_reason' => $reason];
    }
}
