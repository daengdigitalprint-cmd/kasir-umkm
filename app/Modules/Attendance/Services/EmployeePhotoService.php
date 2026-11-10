<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Foto profil karyawan di disk privat (`local`), hanya bisa dibuka lewat rute yang
 * memeriksa hak akses (karyawan itu sendiri atau admin tokonya).
 */
class EmployeePhotoService
{
    public function store(Employee $employee, UploadedFile $file): Employee
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = $file->storeAs(
            "employee-photos/{$employee->store_id}",
            $employee->id.'-'.Str::lower(Str::random(12)).'.'.$extension,
            'local',
        );

        $old = $employee->photo_path;
        $employee->forceFill(['photo_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $employee;
    }

    public function remove(Employee $employee): void
    {
        if ($employee->photo_path) {
            Storage::disk('local')->delete($employee->photo_path);
            $employee->forceFill(['photo_path' => null])->save();
        }
    }

    public function response(Employee $employee): Response
    {
        abort_unless($employee->photo_path && Storage::disk('local')->exists($employee->photo_path), 404);

        return Storage::disk('local')->response($employee->photo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Versi untuk memaksa aplikasi/browser memuat ulang setelah foto diganti.
     */
    public static function version(Employee $employee): ?string
    {
        return $employee->photo_path ? substr(md5($employee->photo_path), 0, 10) : null;
    }
}
