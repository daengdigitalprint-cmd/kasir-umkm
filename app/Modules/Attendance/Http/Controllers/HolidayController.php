<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Models\Holiday;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Hari libur toko: karyawan tidak ditandai alpa dan lembur memakai tarif hari libur.
 */
class HolidayController extends AdminAttendanceController
{
    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $validated = $request->validate([
            'date' => [
                'required',
                'date',
                function (string $attribute, mixed $value, \Closure $fail) use ($store) {
                    $exists = Holiday::query()
                        ->where('store_id', $store->id)
                        ->whereDate('date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('Tanggal ini sudah terdaftar sebagai hari libur.');
                    }
                },
            ],
            'name' => 'required|string|max:100',
        ]);

        $holiday = Holiday::create([
            'store_id' => $store->id,
            'date' => $validated['date'],
            'name' => $validated['name'],
        ]);

        ActivityLogger::log($store, 'holiday.created', "Menambah hari libur {$holiday->date->toDateString()} ({$holiday->name})", $holiday);

        return back()->with('success', 'Hari libur ditambahkan.');
    }

    public function destroy(Holiday $holiday)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        abort_if($holiday->store_id !== $store->id, 404);

        ActivityLogger::log($store, 'holiday.deleted', "Menghapus hari libur {$holiday->date->toDateString()} ({$holiday->name})", $holiday);
        $holiday->delete();

        return back()->with('success', 'Hari libur dihapus.');
    }
}
