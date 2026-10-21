<?php

use App\Support\Bank\AccountNumber;
use App\Support\Bank\BankLinePattern;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_line_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // AccountNumber::key — исто како кај bank_counterparties.
            $table->string('account_key', 34);
            $table->string('direction', 3);
            // BankLinePattern: текстот без бројки, датуми и месеци.
            $table->string('pattern', 80);
            $table->string('example_text')->nullable();
            $table->string('name_seen')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->unsignedInteger('times_used')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_key', 'direction', 'pattern'], 'bank_rule_company_key_dir_pat_unique');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_line_rules');
    }

    /** Правила од веќе прокнижените изводи, по редослед: подоцна книжење го пребришува претходното. */
    private function backfill(): void
    {
        $rules = [];

        $lines = DB::table('bank_statement_lines as l')
            ->join('bank_statements as s', 's.id', '=', 'l.bank_statement_id')
            ->where('s.status', 'booked')
            ->where('l.kind', 'account')
            ->whereNotNull('l.account_id')
            ->whereNotNull('l.counterparty_account')
            ->orderBy('l.line_date')
            ->orderBy('l.id')
            ->get(['s.company_id', 'l.direction', 'l.counterparty_account', 'l.counterparty_name', 'l.description', 'l.account_id', 'l.partner_id', 'l.line_date']);

        foreach ($lines as $line) {
            $key = AccountNumber::key((string) $line->counterparty_account);
            $pattern = BankLinePattern::from($line->description);

            if ($key === '' || $pattern === null) {
                continue;
            }

            $id = $line->company_id.'|'.$key.'|'.$line->direction.'|'.$pattern;
            $rule = $rules[$id] ?? [
                'company_id' => $line->company_id, 'account_key' => $key, 'direction' => $line->direction,
                'pattern' => $pattern, 'times_used' => 0, 'partner_id' => null, 'name_seen' => null,
            ];
            $rule['account_id'] = $line->account_id;
            $rule['partner_id'] = $line->partner_id ?? $rule['partner_id'];
            $rule['name_seen'] = $line->counterparty_name ?? $rule['name_seen'];
            $rule['example_text'] = mb_substr((string) $line->description, 0, 255);
            $rule['times_used']++;
            $rule['last_used_at'] = $line->line_date;
            $rules[$id] = $rule;
        }

        foreach (array_chunk(array_values($rules), 200) as $chunk) {
            DB::table('bank_line_rules')->insert(array_map(
                fn (array $rule) => $rule + ['created_at' => now(), 'updated_at' => now()],
                $chunk,
            ));
        }
    }
};
