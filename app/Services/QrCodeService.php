<?php

namespace App\Services;

use App\Models\Table;
use Illuminate\Support\Facades\Storage;

class QrCodeService
{
    /**
     * Generate QR code untuk meja dan simpan sebagai file lokal.
     * Menggunakan QR Server API (gratis, tanpa package eksternal).
     */
    public static function generateForTable(Table $table): string
    {
        $url = $table->guestUrl();
        $encodedUrl = urlencode($url);
        $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encodedUrl}&format=png";

        try {
            $imageContent = file_get_contents($qrApiUrl);

            if ($imageContent === false) {
                throw new \RuntimeException('Gagal mengambil QR code dari API.');
            }

            $filename = "qr-codes/{$table->store_id}/table-{$table->id}.png";
            Storage::disk('public')->put($filename, $imageContent);

            $publicUrl = Storage::disk('public')->url($filename);

            $table->update(['qr_code_url' => $publicUrl]);

            return $publicUrl;
        } catch (\Exception $e) {
            // Fallback: gunakan Google Charts API
            $googleUrl = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl={$encodedUrl}&choe=UTF-8";

            try {
                $imageContent = file_get_contents($googleUrl);
                if ($imageContent === false) {
                    throw new \RuntimeException('Gagal dari Google Charts.');
                }

                $filename = "qr-codes/{$table->store_id}/table-{$table->id}.png";
                Storage::disk('public')->put($filename, $imageContent);

                $publicUrl = Storage::disk('public')->url($filename);
                $table->update(['qr_code_url' => $publicUrl]);

                return $publicUrl;
            } catch (\Exception $fallbackException) {
                \Log::error('Gagal generate QR code untuk meja #'.$table->id, [
                    'error' => $e->getMessage(),
                    'table_id' => $table->id,
                ]);

                return $url; // Fallback: return URL plain
            }
        }
    }

    /**
     * Regenerate QR code untuk meja yang sudah ada.
     */
    public static function regenerate(Table $table): string
    {
        // Hapus file lama jika ada
        $oldUrl = $table->qr_code_url;
        if ($oldUrl) {
            $oldPath = str_replace(Storage::disk('public')->url(''), '', $oldUrl);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        return self::generateForTable($table);
    }
}
