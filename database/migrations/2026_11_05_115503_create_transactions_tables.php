<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 9: vendas no POS (transactions, transaction_items) e anulações
     * (reversals).
     *
     * Uma venda nunca se apaga e os seus itens nunca se alteram (triggers):
     * anular muda o estado da venda e regista um movimento inverso no ledger.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_code', 40)->unique();
            $table->foreignUuid('voucher_id')->constrained();
            $table->foreignUuid('household_id')->constrained();
            $table->foreignUuid('agrodealer_id')->constrained();
            $table->foreignUuid('pos_id')->nullable()->constrained('agrodealer_pos');
            $table->foreignUuid('operator_id')->nullable()->constrained('users');
            $table->decimal('subtotal', 14, 2);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('voucher_amount', 14, 2)->default(0);
            $table->decimal('copayment_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['agrodealer_id', 'confirmed_at']);
            $table->index(['voucher_id', 'status']);
            $table->index('household_id');
        });
        foreach ([
            'transactions_amounts_check' => 'subtotal >= 0 and discount_value >= 0 and discount_amount >= 0 and total_amount >= 0 and voucher_amount >= 0 and copayment_amount >= 0',
            'transactions_total_check' => 'total_amount = subtotal - discount_amount',
            'transactions_split_check' => 'voucher_amount + copayment_amount = total_amount',
            'transactions_discount_check' => '(discount_type is null and discount_value = 0 and discount_amount = 0) or discount_type is not null',
            'transactions_discount_type_check' => "discount_type is null or discount_type in ('PERCENTAGE', 'FIXED_AMOUNT')",
            'transactions_status_check' => "status in ('PENDING', 'CONFIRMED', 'REJECTED', 'REVERSED')",
        ] as $name => $check) {
            DB::statement("alter table transactions add constraint {$name} check ({$check})");
        }

        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained();
            $table->foreignUuid('product_id')->constrained();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamp('created_at')->useCurrent();
        });
        DB::statement('alter table transaction_items add constraint transaction_items_values_check check (quantity > 0 and unit_price >= 0 and line_total >= 0)');
        DB::statement('alter table transaction_items add constraint transaction_items_line_check check (line_total = round(quantity * unit_price, 2))');

        Schema::create('reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->unique()->constrained();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('REQUESTED');
            $table->string('reason');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        DB::statement("alter table reversals add constraint reversals_status_check check (status in ('REQUESTED', 'APPROVED', 'REJECTED', 'COMPLETED'))");

        Schema::table('voucher_ledger', function (Blueprint $table) {
            $table->foreign('transaction_id')->references('id')->on('transactions');
        });

        // Histórico de vendas protegido (antigo 20261007140000).
        DB::unprepared("create trigger transaction_items_no_update before update on transaction_items for each row signal sqlstate '45000' set message_text = 'TRANSACTION_HISTORY_IMMUTABLE'");
        DB::unprepared("create trigger transaction_items_no_delete before delete on transaction_items for each row signal sqlstate '45000' set message_text = 'TRANSACTION_HISTORY_IMMUTABLE'");
        DB::unprepared("create trigger transactions_no_delete before delete on transactions for each row signal sqlstate '45000' set message_text = 'TRANSACTION_HISTORY_IMMUTABLE'");
    }

    public function down(): void
    {
        foreach (['transaction_items_no_update', 'transaction_items_no_delete', 'transactions_no_delete'] as $trigger) {
            DB::unprepared("drop trigger if exists {$trigger}");
        }
        Schema::table('voucher_ledger', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
        });
        Schema::dropIfExists('reversals');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
    }
};
