<?php

namespace App\Console\Commands;

use App\Mail\BatchExpiryDigestMail;
use App\Models\Store;
use App\Models\User;
use App\Support\BatchTracking;
use App\Support\ExpiringProductBatches;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendBatchExpiryDigestCommand extends Command
{
    protected $signature = 'batch:send-expiry-digest {--dry-run : Hanya tampilkan jumlah, tidak mengirim email}';

    protected $description = 'Kirim email ringkasan batch mendekati/melewati ED untuk toko yang mengaktifkan pemberitahuan.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sentStores = 0;
        $sentMails = 0;

        Store::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($dry, &$sentStores, &$sentMails) {
                foreach ($stores as $store) {
                    if (! BatchTracking::enabled($store)) {
                        continue;
                    }
                    if (! (bool) ($store->settings['batch_expiry_email_enabled'] ?? false)) {
                        continue;
                    }

                    $batches = ExpiringProductBatches::alertPayload($store, 200);
                    if ($batches === []) {
                        continue;
                    }

                    $emails = $this->recipientEmails($store);
                    if ($emails->isEmpty()) {
                        $this->warn("Toko #{$store->id} {$store->name}: tidak ada alamat email penerima (isi email toko atau admin/manajer).");

                        continue;
                    }

                    $sentStores++;
                    $warnDays = BatchTracking::expiryWarnDays($store);

                    foreach ($emails as $email) {
                        if ($dry) {
                            $this->line("[dry-run] {$store->name} → {$email} (".count($batches).' batch)');
                            $sentMails++;

                            continue;
                        }

                        Mail::to($email)->send(new BatchExpiryDigestMail($store, $warnDays, $batches));
                        $sentMails++;
                    }
                }
            });

        $this->info($dry ? "Selesai (dry-run): {$sentStores} toko, {$sentMails} penerima." : "Selesai: {$sentStores} toko, {$sentMails} email dikirim.");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function recipientEmails(Store $store): \Illuminate\Support\Collection
    {
        $out = collect();

        $storeEmail = trim((string) ($store->email ?? ''));
        if ($storeEmail !== '' && filter_var($storeEmail, FILTER_VALIDATE_EMAIL)) {
            $out->push($storeEmail);
        }

        $store->members()
            ->wherePivotIn('role', ['admin', 'manager'])
            ->pluck('users.email')
            ->each(function (?string $e) use ($out) {
                $e = trim((string) $e);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $out->push($e);
                }
            });

        User::query()
            ->where('store_id', $store->id)
            ->whereIn('role', ['admin', 'manager'])
            ->pluck('email')
            ->each(function (?string $e) use ($out) {
                $e = trim((string) $e);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $out->push($e);
                }
            });

        return $out->unique()->values();
    }
}
