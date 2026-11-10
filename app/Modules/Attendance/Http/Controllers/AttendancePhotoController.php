<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Models\AttendancePhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menampilkan foto selfie absensi dari disk privat.
 * Hanya admin/manager/owner toko yang sama, atau karyawan pemilik foto itu sendiri.
 */
class AttendancePhotoController extends AdminAttendanceController
{
    public function show(AttendancePhoto $photo): Response
    {
        $store = $this->currentStore();
        abort_if((int) $photo->store_id !== (int) $store->id, 404);

        $isAdmin = in_array(auth()->user()->getRoleForActiveStore(), ['admin', 'manager', 'owner'], true);
        $isOwnPhoto = (int) $photo->employee?->user_id === (int) auth()->id();
        abort_unless($isAdmin || $isOwnPhoto, 403);

        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, null, [
            'Content-Type' => $photo->mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
