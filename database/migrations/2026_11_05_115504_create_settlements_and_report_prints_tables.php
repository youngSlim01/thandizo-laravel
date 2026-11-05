<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Níveis 10 e 11: reconciliações dos agrodealers e registo das
     * impressões de relatórios.
     *
     * Uma venda está no máximo numa reconciliação activa (não devolvida):
     * coluna gerada active_transaction_id com índice único (antigo índice
     * parcial settlement_items_active_transaction_key).
     */
    public function up(): void
    {
        Schema::create('settlement_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('settlement_code', 40)->unique();
            $table->foreignUuid('agrodealer_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('voucher_amount', 14, 2)->default(0);
            $table->decimal('copayment_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('payable_amount', 14, 2)->default(0);
            $table->string('status', 30)->default('PENDING_MANAGER');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->foreignUuid('manager_reviewed_by')->nullable()->constrained('users');
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->string('manager_notes', 1000)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('finance_reference')->nullable();
            $table->string('notes', 1000)->nullable();

            $table->index(['agrodealer_id', 'period_start', 'period_end']);
        });
        DB::statement("alter table settlement_batches add constraint settlement_batches_status_check check (status in ('RECONCILED', 'PENDING_MANAGER', 'MANAGER_RETURNED', 'SUBMITTED_TO_FINANCE', 'VALIDATED', 'RETURNED'))");
        DB::statement('alter table settlement_batches add constraint settlement_period_valid check (period_end >= period_start)');

        Schema::create('settlement_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained('settlement_batches')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained();
            $table->boolean('active')->default(true);
            $table->timestamp('released_at')->nullable();
            $table->uuid('active_transaction_id')->nullable()->storedAs('if(active, transaction_id, null)');

            $table->unique('active_transaction_id');
            $table->index('settlement_id');
        });

        Schema::create('report_prints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('report_type', 40)->default('AGRODEALER_TRANSACTIONS');
            $table->foreignUuid('agrodealer_id')->constrained();
            $table->foreignUuid('province_id')->nullable()->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('transactions');
            $table->decimal('total_amount', 14, 2);
            $table->decimal('voucher_amount', 14, 2);
            $table->decimal('copayment_amount', 14, 2);
            $table->foreignUuid('printed_by')->constrained('users');
            $table->timestamp('printed_at')->useCurrent()->index();
        });
        DB::statement('alter table report_prints add constraint report_prints_period_check check (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_prints');
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlement_batches');
    }
};
