<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 20)->nullable()->after('status');
            $table->text('two_factor_secret')->nullable()->after('two_factor_method');
            $table->timestamp('two_factor_enabled_at')->nullable()->after('two_factor_secret');
            $table->string('pin')->nullable()->after('password');
        });

        Schema::create('two_factor_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 20);
            $table->string('method', 20);
            $table->string('code_hash')->nullable();
            $table->text('secret')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_challenges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_method',
                'two_factor_secret',
                'two_factor_enabled_at',
                'pin',
            ]);
        });
    }
};
