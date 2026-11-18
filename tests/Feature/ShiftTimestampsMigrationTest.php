<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShiftTimestampsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_18_180000_shift_timestamps_utc_to_argentina.php');
    }

    public function test_it_subtracts_three_hours_from_time_columns_only_and_is_reversible(): void
    {
        Schema::create('tz_probe', function (Blueprint $table) {
            $table->id();
            $table->date('only_date')->nullable();
            $table->timestamp('ts')->nullable();
            $table->dateTime('dt')->nullable();
            $table->timestamp('empty_ts')->nullable();
        });

        DB::table('tz_probe')->insert([
            'only_date' => '2026-11-18',
            'ts' => '2026-11-17 20:35:10',
            'dt' => '2026-11-18 01:10:00',
            'empty_ts' => null,
        ]);

        DB::table('users')->insert([
            'id' => 9001, 'name' => 'tz', 'email' => 'tz@example.com', 'password' => 'x',
            'created_at' => '2026-11-17 20:35:10', 'updated_at' => '2026-11-18 00:10:00',
        ]);

        $this->migration()->up();

        $row = DB::table('tz_probe')->first();
        $this->assertSame('2026-11-17 17:35:10', $row->ts);
        $this->assertSame('2026-11-17 22:10:00', $row->dt, 'cruza la medianoche hacia el día anterior');
        $this->assertNull($row->empty_ts, 'los NULL se respetan');
        $this->assertSame('2026-11-18', substr((string) $row->only_date, 0, 10), 'las columnas date no se tocan');

        $user = DB::table('users')->where('id', 9001)->first();
        $this->assertSame('2026-11-17 17:35:10', $user->created_at, 'también las tablas reales del sistema');
        $this->assertSame('2026-11-17 21:10:00', $user->updated_at);

        $this->migration()->down();

        $this->assertSame('2026-11-17 20:35:10', DB::table('tz_probe')->value('ts'));
        $this->assertSame('2026-11-17 20:35:10', DB::table('users')->where('id', 9001)->value('created_at'));
    }
}
