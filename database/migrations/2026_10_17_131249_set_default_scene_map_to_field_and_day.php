<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scenes')->orderBy('id')->lazy()->each(function (object $scene): void {
            $settings = json_decode($scene->settings_json ?? '[]', true);
            if (! is_array($settings)) {
                $settings = [];
            }
            $changed = false;
            if (! array_key_exists('background', $settings)) {
                $settings['background'] = 'field';
                $changed = true;
            }
            if (($settings['lighting'] ?? 'night-gloom') === 'night-gloom') {
                $settings['lighting'] = 'day';
                $changed = true;
            }
            if (! $changed) {
                return;
            }
            DB::table('scenes')->where('id', $scene->id)->update([
                'settings_json' => json_encode($settings),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('scenes')->orderBy('id')->lazy()->each(function (object $scene): void {
            $settings = json_decode($scene->settings_json ?? '[]', true);
            if (! is_array($settings)) {
                return;
            }
            if (($settings['background'] ?? null) !== 'field' || ($settings['lighting'] ?? null) !== 'day') {
                return;
            }
            unset($settings['background']);
            $settings['lighting'] = 'night-gloom';
            DB::table('scenes')->where('id', $scene->id)->update([
                'settings_json' => json_encode($settings),
            ]);
        });
    }
};
