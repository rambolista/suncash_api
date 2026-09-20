<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy's "Account Management" feature (`mysuncash-stage` commit
 * `47d840e38`, "Initial Commit - Account Management", added after this
 * app's first Customer Management port) — Lock/Restrict/Restore a
 * customer's account with a reason + note + case reference, and a
 * permanent audit trail of every status change. Mirrors legacy's own
 * `api/migration_file.php` `ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF
 * NOT EXISTS` — guarded the same way here since this shared `mysuncash` DB
 * may already have some of this from elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mysuncash')->table('customers', function (Blueprint $table) {
            foreach (['restricted_by', 'locked_by'] as $prefix) {
                if (! Schema::connection('mysuncash')->hasColumn('customers', $prefix)) {
                    $table->string($prefix, 100)->nullable();
                }
            }
            foreach (['restricted_date', 'locked_date'] as $col) {
                if (! Schema::connection('mysuncash')->hasColumn('customers', $col)) {
                    $table->dateTime($col)->nullable();
                }
            }
            foreach (['restricted_reason', 'locked_reason'] as $col) {
                if (! Schema::connection('mysuncash')->hasColumn('customers', $col)) {
                    $table->string($col, 255)->nullable();
                }
            }
            foreach (['restricted_note', 'locked_note'] as $col) {
                if (! Schema::connection('mysuncash')->hasColumn('customers', $col)) {
                    $table->text($col)->nullable();
                }
            }
            foreach (['restricted_reference', 'locked_reference'] as $col) {
                if (! Schema::connection('mysuncash')->hasColumn('customers', $col)) {
                    $table->string($col, 110)->nullable();
                }
            }
        });

        if (! Schema::connection('mysuncash')->hasTable('account_status_reasons')) {
            Schema::connection('mysuncash')->create('account_status_reasons', function (Blueprint $table) {
                $table->increments('id');
                $table->string('type', 50)->nullable();
                $table->text('reason')->nullable();
                $table->string('severity', 50)->nullable();
                $table->text('who_may_restore')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_date')->useCurrent();
            });

            $now = now();
            $reasons = [
                ['type' => 'locked', 'reason' => 'Suspicious activity detected'],
                ['type' => 'locked', 'reason' => 'Customer requested a temporary lock'],
                ['type' => 'locked', 'reason' => 'Multiple failed login attempts'],
                ['type' => 'locked', 'reason' => 'Lost or stolen device'],
                ['type' => 'locked', 'reason' => 'Other'],
                ['type' => 'restricted', 'reason' => 'Compliance hold'],
                ['type' => 'restricted', 'reason' => 'Fraud investigation'],
                ['type' => 'restricted', 'reason' => 'Duplicate account'],
                ['type' => 'restricted', 'reason' => 'Chargeback / dispute under review'],
                ['type' => 'restricted', 'reason' => 'Other'],
                ['type' => 'restoration', 'reason' => 'Issue resolved'],
                ['type' => 'restoration', 'reason' => 'Reviewed and cleared'],
                ['type' => 'restoration', 'reason' => 'Compliance hold lifted'],
                ['type' => 'restoration', 'reason' => 'Other'],
            ];
            DB::connection('mysuncash')->table('account_status_reasons')->insert(array_map(
                fn ($r) => $r + ['severity' => null, 'who_may_restore' => null, 'notes' => null, 'created_date' => $now],
                $reasons
            ));
        }

        if (! Schema::connection('mysuncash')->hasTable('customer_account_status_histories')) {
            Schema::connection('mysuncash')->create('customer_account_status_histories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('customer_identifier', 100)->nullable()->index();
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50)->nullable();
                $table->integer('reason_id')->nullable();
                $table->string('reason_label', 255)->nullable();
                $table->text('note')->nullable();
                $table->string('reference', 110)->nullable()->default('-1');
                $table->string('created_by', 100);
                $table->timestamp('created_date')->useCurrent()->index();
                $table->string('updated_by', 100)->nullable();
                $table->string('updated_date', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysuncash')->dropIfExists('customer_account_status_histories');
        Schema::connection('mysuncash')->dropIfExists('account_status_reasons');

        Schema::connection('mysuncash')->table('customers', function (Blueprint $table) {
            $columns = [
                'restricted_by', 'restricted_date', 'restricted_reason', 'restricted_note', 'restricted_reference',
                'locked_by', 'locked_date', 'locked_reason', 'locked_note', 'locked_reference',
            ];
            foreach ($columns as $column) {
                if (Schema::connection('mysuncash')->hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
