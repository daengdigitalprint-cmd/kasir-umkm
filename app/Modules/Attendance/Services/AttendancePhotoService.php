<?php

namespace App\Modules\Attendance\Services;

use App\Models\Store;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePhoto;
use App\Modules\Attendance\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Penyimpanan & validasi foto selfie bukti kehadiran.
 * Foto disimpan di disk privat (`local` → storage/app/private), bukan `public`,
 * agar data pribadi tidak dapat diakses tanpa autentikasi.
 */
class AttendancePhotoService
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_SIZE_KB = 5120; // 5 MB

    public function store(
        UploadedFile $file,
        Store $store,
        Employee $employee,
        Attendance $attendance,
        string $type,
    ): AttendancePhoto {
        $this->validate($file);

        $directory = sprintf(
            'attendance/%d/%d/%s/%s',
            $store->id,
            $employee->id,
            $type,
            $attendance->work_date->format('Y/m'),
        );

        $extension = $file->extension() ?: 'jpg';
        $path = Storage::disk('local')->putFileAs(
            $directory,
            $file,
            Str::uuid().'.'.$extension,
        );

        return AttendancePhoto::create([
            'store_id' => $store->id,
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'type' => $type,
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'captured_at' => now(),
        ]);
    }

    private function validate(UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'photo' => 'Format foto harus JPEG, PNG, atau WEBP.',
            ]);
        }

        if ($file->getSize() > self::MAX_SIZE_KB * 1024) {
            throw ValidationException::withMessages([
                'photo' => 'Ukuran foto maksimal 5 MB.',
            ]);
        }
    }
}
