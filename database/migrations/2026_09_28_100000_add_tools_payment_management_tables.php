<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Tools > Payment Management" (legacy `tools::payment_management()` +
 * `payment_management_model`). Mirrors legacy's own `api/migration_file.php`
 * (lines 1696-1808) table-for-table, guarded the same "IF NOT EXISTS" way
 * since this shared `mysuncash` DB may already have some of this from
 * elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysuncash')->hasTable('payment_requests')) {
            Schema::connection('mysuncash')->create('payment_requests', function (Blueprint $table) {
                $table->increments('id');
                $table->string('transaction_id', 50);
                $table->string('payment_type_id', 50)->nullable();
                $table->integer('merchant_id')->nullable();
                $table->string('company_name', 250)->nullable();
                $table->string('payment_method_id', 50)->nullable();
                $table->decimal('amount', 18, 2)->nullable();
                $table->char('currency', 4)->default('BSD');
                $table->date('settlement_start')->nullable();
                $table->date('settlement_end')->nullable();
                $table->string('external_reference', 150)->nullable();
                $table->enum('status', ['DRAFT', 'PENDING', 'RETURNED', 'VERIFIED', 'APPROVED', 'PROCESSING', 'PAID', 'FAILED', 'REJECTED', 'CANCELLED'])->default('PENDING');
                $table->enum('source', ['SYSTEM', 'MANUAL'])->default('MANUAL');
                $table->string('prepared_by', 80)->nullable();
                $table->text('notes')->nullable();
                $table->string('bank_reference', 150)->nullable();
                $table->timestamp('created_date')->useCurrent();
                $table->dateTime('updated_date')->nullable();

                $table->index('transaction_id', 'idx_transaction_id');
                $table->index('merchant_id', 'idx_merchant_id');
                $table->index('status', 'idx_status');
            });
        }

        if (! Schema::connection('mysuncash')->hasTable('payment_documents')) {
            Schema::connection('mysuncash')->create('payment_documents', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('payment_id');
                $table->text('file_url')->nullable();
                $table->string('file_name', 250)->nullable();
                $table->string('file_type', 60)->nullable();
                $table->boolean('is_active')->default(1)->comment('1 active, 0 deleted');
                $table->timestamp('created_date')->useCurrent();

                $table->index('payment_id', 'idx_payment_id');
                $table->index('is_active', 'idx_is_active');
            });
        }

        if (! Schema::connection('mysuncash')->hasTable('payment_audit_trail')) {
            Schema::connection('mysuncash')->create('payment_audit_trail', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('payment_id')->nullable();
                $table->string('event_type', 60);
                $table->string('user_id', 20)->nullable();
                $table->string('user_name', 80)->nullable();
                $table->string('source_ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->text('old_values')->nullable();
                $table->text('new_values')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_date')->useCurrent();

                $table->index('payment_id', 'idx_payment_id');
                $table->index('event_type', 'idx_event_type');
                $table->index('user_id', 'idx_user_id');
            });
        }

        if (! Schema::connection('mysuncash')->hasTable('payment_types')) {
            Schema::connection('mysuncash')->create('payment_types', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 150)->nullable();
                $table->enum('status', ['A', 'I'])->default('A');
                $table->timestamp('created_at')->useCurrent();
            });

            DB::connection('mysuncash')->table('payment_types')->insert(array_map(
                fn ($name) => ['name' => $name, 'status' => 'A'],
                ['Western Union', 'Utility Payments', 'Gaming Funds', 'Government Payments', 'Store/Agent Commissions', 'Other']
            ));
        }

        if (! Schema::connection('mysuncash')->hasTable('payment_methods')) {
            Schema::connection('mysuncash')->create('payment_methods', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 150)->nullable();
                $table->enum('status', ['A', 'I'])->default('A');
                $table->timestamp('created_at')->useCurrent();
            });

            DB::connection('mysuncash')->table('payment_methods')->insert(array_map(
                fn ($name) => ['name' => $name, 'status' => 'A'],
                ['Bank Transfer', 'Cheque', 'Internal Transfer', 'Other']
            ));
        }

        if (! Schema::connection('mysuncash')->hasColumn('manual_settlement', 'payment_id')) {
            Schema::connection('mysuncash')->table('manual_settlement', function (Blueprint $table) {
                $table->integer('payment_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysuncash')->dropIfExists('payment_audit_trail');
        Schema::connection('mysuncash')->dropIfExists('payment_documents');
        Schema::connection('mysuncash')->dropIfExists('payment_requests');
        Schema::connection('mysuncash')->dropIfExists('payment_types');
        Schema::connection('mysuncash')->dropIfExists('payment_methods');

        if (Schema::connection('mysuncash')->hasColumn('manual_settlement', 'payment_id')) {
            Schema::connection('mysuncash')->table('manual_settlement', function (Blueprint $table) {
                $table->dropColumn('payment_id');
            });
        }
    }
};
