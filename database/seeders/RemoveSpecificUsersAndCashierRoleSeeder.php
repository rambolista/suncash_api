<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveSpecificUsersAndCashierRoleSeeder extends Seeder
{
    private const USER_EMAILS = [
        'annecauan@gmail.com',
        'rjmoris@gmail.com',
        'bellabolista@gmail.com',
    ];

    private const ROLE_NAMES = ['cashier', 'cashiers'];

    public function run(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->whereIn('email', self::USER_EMAILS)
                ->delete();
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->whereIn(DB::raw('LOWER(TRIM(name))'), self::ROLE_NAMES)
                ->delete();
        }
    }
}
