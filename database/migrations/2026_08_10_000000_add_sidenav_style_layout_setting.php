<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('layout_settings')->orderBy('id')->each(function (object $row) {
            $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
            $settings['sidenavStyle'] ??= 'default';

            DB::table('layout_settings')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('layout_settings')->orderBy('id')->each(function (object $row) {
            $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
            unset($settings['sidenavStyle']);

            DB::table('layout_settings')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }
};
