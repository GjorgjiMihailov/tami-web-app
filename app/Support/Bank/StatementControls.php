<?php

namespace App\Support\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Support\Format;

/**
 * Проверки што мора да поминат пред изводот да се прокнижи. Чиста логика: ништо
 * не запишува.
 */
class StatementControls
{
    /**
     * Почетна + движење − крајна. Нула значи дека изводот се совпаѓа.
     *
     * @param  iterable<string>  $signedAmounts  уплата +, исплата − (од наша гледна точка)
     */
    public static function difference(?string $opening, ?string $closing, iterable $signedAmounts): ?string
    {
        if ($opening === null || $closing === null || $opening === '' || $closing === '') {
            return null;
        }

        $movement = '0.00';
        foreach ($signedAmounts as $amount) {
            $movement = bcadd($movement, $amount, 2);
        }

        return bcsub(bcadd($opening, $movement, 2), $closing, 2);
    }

    /** @return array<int, string> празно = може да се потврди */
    public static function problems(BankStatement $statement): array
    {
        $statement->loadMissing('lines');
        $problems = [];

        if ($statement->lines->isEmpty()) {
            $problems[] = 'Изводот нема ставки.';
        }

        $opening = $statement->opening_balance;
        $closing = $statement->closing_balance;

        if ($opening === null || $closing === null) {
            $problems[] = 'Внесете почетна и крајна состојба.';
        } else {
            $difference = self::difference((string) $opening, (string) $closing, $statement->lines->map->signedAmount());

            if (bccomp($difference, '0', 2) !== 0) {
                $problems[] = 'Почетна состојба + движење не е еднакво на крајната состојба (разлика '.self::money($difference).').';
            }

            $previous = self::previousBooked($statement);

            if ($previous !== null && bccomp((string) $previous->closing_balance, (string) $opening, 2) !== 0) {
                $problems[] = 'Почетната состојба ('.self::money((string) $opening).') не е еднаква на крајната од извод '
                    .$previous->number.' ('.self::money((string) $previous->closing_balance).').';
            }
        }

        foreach ($statement->lines as $index => $line) {
            foreach (self::lineProblems($statement, $line) as $problem) {
                $problems[] = 'Ставка '.($index + 1).': '.$problem;
            }
        }

        return array_merge($problems, self::sharedInvoiceProblems($statement));
    }

    /**
     * Две ставки на иста фактура поединечно може да влезат во салдото, а заедно
     * да го надминат. Се собираат само ставките што создаваат ново плаќање.
     *
     * @return array<int, string>
     */
    private static function sharedInvoiceProblems(BankStatement $statement): array
    {
        $problems = [];

        $groups = $statement->lines
            ->filter(fn (BankStatementLine $line) => $line->kind === LineKind::INVOICE_PAYMENT)
            ->filter(fn (BankStatementLine $line) => $line->direction === LineDirection::IN
                ? $line->sales_invoice_id !== null && $line->sales_invoice_payment_id === null
                : $line->purchase_invoice_id !== null && $line->purchase_invoice_payment_id === null)
            ->groupBy(fn (BankStatementLine $line) => $line->direction->value.'-'.($line->sales_invoice_id ?? $line->purchase_invoice_id));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $first = $group->first();
            $invoice = $first->direction === LineDirection::IN
                ? $first->salesInvoice()->with(['lines', 'payments'])->first()
                : $first->purchaseInvoice()->with(['lines', 'payments'])->first();
            $total = $group->reduce(fn (string $carry, BankStatementLine $line) => bcadd($carry, (string) $line->amount, 2), '0.00');

            if ($invoice !== null && bccomp($total, $invoice->balanceDue(), 2) > 0) {
                $numbers = $group->map(fn (BankStatementLine $line) => $statement->lines->search(fn ($l) => $l->is($line)) + 1)->implode(', ');
                $problems[] = "Ставки {$numbers}: заедно ја надминуваат фактурата (салдо ".self::money($invoice->balanceDue()).').';
            }
        }

        return $problems;
    }

    /** Претходниот книжен извод на истата сметка во истата година. */
    private static function previousBooked(BankStatement $statement): ?BankStatement
    {
        return BankStatement::where('company_id', $statement->company_id)
            ->where('account', $statement->account)
            ->where('kind', $statement->kind)
            ->where('status', BankStatement::STATUS_BOOKED)
            ->whereYear('statement_date', $statement->statement_date->year)
            ->where('number', '<', $statement->number)
            ->whereKeyNot($statement->id)
            ->orderByDesc('number')
            ->first();
    }

    /** @return array<int, string> */
    private static function lineProblems(BankStatement $statement, BankStatementLine $line): array
    {
        $problems = [];

        if (bccomp((string) $line->amount, '0', 2) <= 0) {
            $problems[] = 'износот мора да е поголем од нула.';
        }

        if ($line->line_date->year !== $statement->statement_date->year) {
            $problems[] = 'датумот е од друга година од изводот.';
        }

        if ($line->kind === LineKind::INVOICE_PAYMENT) {
            return array_merge($problems, self::invoiceProblems($statement, $line));
        }

        if ($line->account_id === null) {
            $problems[] = 'изберете конто.';

            return $problems;
        }

        $account = Account::where('company_id', $statement->company_id)->find($line->account_id);

        if ($account === null) {
            $problems[] = 'контото не постои во оваа фирма.';
        } elseif (! $account->is_analytical) {
            $problems[] = "контото {$account->code} не е аналитичко.";
        }

        return $problems;
    }

    /** @return array<int, string> */
    private static function invoiceProblems(BankStatement $statement, BankStatementLine $line): array
    {
        $isIn = $line->direction === LineDirection::IN;
        $invoice = $isIn ? $line->salesInvoice : $line->purchaseInvoice;
        $existing = $isIn ? $line->sales_invoice_payment_id : $line->purchase_invoice_payment_id;

        if ($invoice === null) {
            return ['изберете фактура.'];
        }

        // ID-јата доаѓаат од екранот; фирмата се проверува овде, не се верува на формата.
        if ($invoice->company_id !== $statement->company_id) {
            return ['фактурата не е од оваа фирма.'];
        }

        if ($existing !== null) {
            return self::linkedPaymentProblems($line, $invoice, $isIn, $existing);
        }

        $invoice->loadMissing(['lines', 'payments']);

        if ($invoice->status !== 'confirmed') {
            return ['фактурата не е потврдена.'];
        }

        if (bccomp((string) $line->amount, $invoice->balanceDue(), 2) > 0) {
            return ['износот го надминува салдото на фактурата ('.self::money($invoice->balanceDue()).').'];
        }

        return [];
    }

    /**
     * Врзано претходно плаќање: мора да е на избраната фактура, банкарско и
     * врзано само за оваа ставка — инаку исто плаќање се брои двапати.
     *
     * @return array<int, string>
     */
    private static function linkedPaymentProblems(BankStatementLine $line, $invoice, bool $isIn, int $paymentId): array
    {
        $payment = $isIn ? $line->salesInvoicePayment : $line->purchaseInvoicePayment;
        $ownerId = $payment === null ? null : ($isIn ? $payment->sales_invoice_id : $payment->purchase_invoice_id);

        if ($payment === null || $ownerId !== $invoice->id) {
            return ['плаќањето не е на избраната фактура.'];
        }

        if ($payment->payment_method !== 'bank') {
            return ['врзаното плаќање не е банкарско.'];
        }

        $taken = BankStatementLine::where($isIn ? 'sales_invoice_payment_id' : 'purchase_invoice_payment_id', $paymentId)
            ->whereKeyNot($line->id)
            ->exists();

        return $taken ? ['плаќањето е веќе врзано за друга ставка на извод.'] : [];
    }

    private static function money(string $value): string
    {
        return str_replace('-', '−', Format::money($value, ''));
    }
}
