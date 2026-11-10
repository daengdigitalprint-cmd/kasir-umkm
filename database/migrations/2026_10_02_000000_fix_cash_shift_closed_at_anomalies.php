<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Perbaiki shift yang closed_at < opened_at (terbalik) atau durasi < 60 menit.
        // Pola yang terlihat: closed_at di DB kurang ~8 jam dari waktu penutupan sebenarnya.
        $shifts = DB::table('cash_shifts')
            ->whereNotNull('closed_at')
            ->get(['id', 'opened_at', 'closed_at']);

        foreach ($shifts as $shift) {
            $opened = \Carbon\Carbon::parse($shift->opened_at);
            $closed = \Carbon\Carbon::parse($shift->closed_at);

            $diffMinutes = $closed->diffInMinutes($opened, false);
            $isNegative = $closed->lt($opened);

            // Anomali: terbalik atau durasi kurang dari 60 menit
            if ($isNegative || abs($diffMinutes) < 60) {
                // Tambahkan 8 jam ke closed_at
                $newClosed = $closed->copy()->addHours(8);

                // Pastikan setelah ditambah 8 jam, closed_at > opened_at
                if ($newClosed->lte($opened)) {
                    $newClosed->addDay();
                }

                DB::table('cash_shifts')
                    ->where('id', $shift->id)
                    ->update(['closed_at' => $newClosed->format('Y-m-d H:i:s')]);
            }
        }
    }

    public function down(): void
    {
        // Tidak ada rollback otomatis yang aman untuk perubahan data historis.
        // Backup direkomendasikan sebelum menjalankan migration ini.
    }
};
