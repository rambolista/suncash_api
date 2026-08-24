<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('two_factor_method', 20)->nullable()->after('status');
            $table->text('two_factor_secret')->nullable()->after('two_factor_method');
            $table->timestamp('two_factor_enabled_at')->nullable()->after('two_factor_secret');
        });

        Schema::create('customer_two_factor_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 20);
            $table->string('method', 20);
            $table->string('code_hash')->nullable();
            $table->text('secret')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['customer_id', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_two_factor_challenges');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_method',
                'two_factor_secret',
                'two_factor_enabled_at',
            ]);
        });
    }
};
