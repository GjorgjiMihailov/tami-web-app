<?php

namespace App\Support\Posting;

use App\Models\Account;

/**
 * Сè што му треба на моторот за еден документ: променливите (во денари и во
 * валутата на документот), кришките по вид × група, знамињата за услови и
 * партнерот. Нема логика — само податоци.
 */
final class PostingContext
{
    /**
     * @param  array<string, string>  $totals  променливи во денари
     * @param  array<string, string>  $foreignTotals  истите променливи во валутата на документот
     * @param  list<PostingSlice>  $slices
     * @param  array<string, bool>  $flags  has_goods, cash, import
     * @param  array{currency_code: string, exchange_rate: string}|null  $foreign
     */
    public function __construct(
        public readonly array $totals,
        public readonly array $foreignTotals = [],
        public readonly array $slices = [],
        public readonly array $flags = [],
        public readonly ?int $partnerId = null,
        public readonly string $documentLabel = '',
        public readonly ?array $foreign = null,
        public readonly ?Account $invoiceAccount = null,
        /** @var list<array{account: Account, amount: string}> сметки од ставките на документот и збир по сметка */
        public readonly array $accountBuckets = [],
    ) {}
}
