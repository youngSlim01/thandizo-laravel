<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Níveis 7 e 8: cartões (vouchers), lotes, ciclos e o ledger.
     *
     * Equivalente a public.vouchers, card_batches, programme_cycles,
     * cycle_households e voucher_ledger. O ledger é o histórico financeiro
     * oficial: cada entrada é imutável (trigger), as correcções são novas
     * entradas. Os índices únicos parciais do PostgreSQL passam a colunas
     * geradas com índice único.
     */
    public function up(): void
    {
        // Numeradores (antigas sequences do PostgreSQL).
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });

        Schema::create('card_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_code', 30)->unique();
            $table->foreignUuid('community_id')->constrained();
            $table->string('status', 20)->default('GENERATED');
            $table->unsignedInteger('card_count');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->foreignUuid('printed_by')->nullable()->constrained('users');
            $table->timestamp('printed_at')->nullable();
            $table->foreignUuid('distributed_by')->nullable()->constrained('users');
            $table->timestamp('distributed_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
        });
        DB::statement("alter table card_batches add constraint card_batches_status_check check (status in ('GENERATED', 'PRINTED', 'IN_DISTRIBUTION', 'DISTRIBUTED', 'CANCELLED'))");

        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('voucher_code', 30)->unique();
            $table->foreignUuid('household_id')->constrained();
            $table->decimal('initial_value', 14, 2)->default(6000);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('issued_at')->useCurrent();
            $table->date('programme_period_end')->nullable();
            $table->foreignUuid('replaced_voucher_id')->nullable()->unique()->constrained('vouchers');
            $table->string('replacement_reason')->nullable();
            $table->foreignUuid('card_batch_id')->nullable()->constrained('card_batches');
            $table->timestamp('distributed_at')->nullable();
            $table->string('received_by_name')->nullable();
            $table->string('received_by_relationship')->nullable();
            $table->timestamps();
            // Um só cartão "vivo" por agregado (antigo vouchers_household_live_key).
            $table->uuid('live_household_id')->nullable()
                ->storedAs("if(status not in ('REPLACED', 'CANCELLED', 'INVALIDATED'), household_id, null)");

            $table->unique('live_household_id');
            $table->index(['household_id', 'status']);
        });
        DB::statement('alter table vouchers add constraint vouchers_balance_check check (current_balance >= 0)');
        DB::statement("alter table vouchers add constraint vouchers_status_check check (status in ('PENDING', 'ACTIVE', 'CANCELLED', 'INVALIDATED', 'REPLACED'))");

        Schema::create('programme_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->decimal('amount', 12, 2)->default(6000);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 10)->default('OPEN');
            $table->timestamp('opened_at')->useCurrent();
            $table->foreignUuid('opened_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            // Um só ciclo aberto de cada vez.
            $table->unsignedTinyInteger('open_flag')->nullable()->storedAs("if(status = 'OPEN', 1, null)");

            $table->unique('open_flag');
        });
        DB::statement('alter table programme_cycles add constraint programme_cycles_amount_check check (amount > 0)');
        DB::statement("alter table programme_cycles add constraint programme_cycles_status_check check (status in ('OPEN', 'CLOSED'))");
        DB::statement('alter table programme_cycles add constraint programme_cycles_period_check check (ends_on is null or starts_on is null or ends_on >= starts_on)');

        Schema::create('cycle_households', function (Blueprint $table) {
            $table->foreignUuid('cycle_id')->constrained('programme_cycles');
            $table->foreignUuid('household_id')->constrained();
            $table->foreignUuid('voucher_id')->nullable()->constrained();
            $table->decimal('amount', 12, 2);
            $table->timestamp('allocated_at')->useCurrent();
            $table->foreignUuid('allocated_by')->nullable()->constrained('users');
            $table->string('source', 10);
            $table->decimal('unused_amount', 12, 2)->nullable();

            $table->primary(['cycle_id', 'household_id']);
        });
        DB::statement("alter table cycle_households add constraint cycle_households_source_check check (source in ('DELIVERY', 'RECHARGE'))");

        Schema::create('voucher_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('voucher_id')->constrained();
            // Chave estrangeira para transactions na migração seguinte.
            $table->uuid('transaction_id')->nullable()->index();
            $table->string('entry_type', 30);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->foreignUuid('reference_voucher_id')->nullable()->constrained('vouchers');
            $table->string('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent()->index();
        });
        DB::statement('alter table voucher_ledger add constraint voucher_ledger_amount_check check (amount <> 0)');
        DB::statement('alter table voucher_ledger add constraint voucher_ledger_balance_check check (balance_after >= 0)');
        DB::statement("alter table voucher_ledger add constraint voucher_ledger_type_check check (entry_type in ('INITIAL_ALLOCATION', 'REDEMPTION', 'REVERSAL', 'REPLACEMENT_TRANSFER', 'ADJUSTMENT', 'CYCLE_ALLOCATION', 'CYCLE_EXPIRY'))");

        // O ledger nunca se altera nem se apaga (decisão D4; antigo 20261007140000).
        DB::unprepared("create trigger voucher_ledger_no_update before update on voucher_ledger for each row signal sqlstate '45000' set message_text = 'LEDGER_IMMUTABLE'");
        DB::unprepared("create trigger voucher_ledger_no_delete before delete on voucher_ledger for each row signal sqlstate '45000' set message_text = 'LEDGER_IMMUTABLE'");
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists voucher_ledger_no_update');
        DB::unprepared('drop trigger if exists voucher_ledger_no_delete');
        Schema::dropIfExists('voucher_ledger');
        Schema::dropIfExists('cycle_households');
        Schema::dropIfExists('programme_cycles');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('card_batches');
        Schema::dropIfExists('code_sequences');
    }
};
