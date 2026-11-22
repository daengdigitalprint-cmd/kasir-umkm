<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\StampCardLedger;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\StampCardSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kartu Stempel Digital.
 *
 * Kartu penuh (stempel mencapai target) otomatis jadi 1 hadiah "siap tukar" dan
 * hitungan stempel mulai lagi dari sisa. Semua perubahan dicatat di stamp_card_ledgers.
 */
class StampCardService
{
    /** Pastikan pelanggan punya kode kartu (untuk tautan & QR). */
    public function ensureToken(Customer $customer): string
    {
        if ($customer->stamp_card_token) {
            return $customer->stamp_card_token;
        }

        do {
            $token = Str::lower(Str::random(24));
        } while (Customer::query()->where('stamp_card_token', $token)->exists());

        $customer->forceFill(['stamp_card_token' => $token])->save();

        return $token;
    }

    public function publicUrl(Customer $customer): string
    {
        return url('/k/'.$this->ensureToken($customer));
    }

    /** Stempel dianggap hangus bila pelanggan tak datang selama masa berlaku. */
    public function isExpired(Store $store, Customer $customer): bool
    {
        $months = (int) StampCardSettings::all($store)['expiry_months'];

        return $months > 0
            && $customer->stamp_count > 0
            && $customer->stamp_last_at !== null
            && $customer->stamp_last_at->lt(now()->subMonths($months));
    }

    /** Stempel yang berlaku saat ini (0 bila sudah hangus). */
    public function currentCount(Store $store, Customer $customer): int
    {
        return $this->isExpired($store, $customer) ? 0 : (int) $customer->stamp_count;
    }

    /** Berapa stempel yang didapat dari transaksi ini menurut aturan toko. */
    public function stampsForTransaction(Store $store, Transaction $transaction): int
    {
        $rules = StampCardSettings::all($store);

        if ($rules['mode'] === 'transaction') {
            return (float) $transaction->total_amount >= $rules['min_amount'] ? 1 : 0;
        }

        $productIds = $rules['product_ids'];
        $categoryIds = $rules['category_ids'];
        if ($productIds === [] && $categoryIds === []) {
            return 0;
        }

        $transaction->loadMissing('items.product:id,product_category_id');
        $qty = 0;
        foreach ($transaction->items as $item) {
            $matches = in_array((int) $item->product_id, $productIds, true)
                || ($item->product && in_array((int) $item->product->product_category_id, $categoryIds, true));
            if ($matches) {
                $qty += (float) $item->quantity;
            }
        }

        return (int) floor($qty + 0.0001);
    }

    /**
     * Tambah stempel dari transaksi (dipanggil di dalam transaksi DB penjualan).
     * Aman diulang: transaksi yang sudah pernah diberi stempel tidak dihitung lagi.
     *
     * @return array<string, mixed>|null ringkasan untuk struk, null bila tidak ada stempel
     */
    public function earnFromTransaction(Transaction $transaction): ?array
    {
        $store = $transaction->store;
        $customer = $transaction->customer;
        if (! $store || ! $customer || ! StampCardSettings::enabled($store)) {
            return null;
        }

        if (StampCardLedger::query()->where('transaction_id', $transaction->id)->where('type', 'earn')->exists()) {
            return null;
        }

        $stamps = $this->stampsForTransaction($store, $transaction);
        if ($stamps <= 0) {
            return null;
        }

        $summary = $this->addStamps($store, $customer, $stamps, 'earn', 'Transaksi '.$transaction->transaction_number, $transaction->user_id, $transaction->id);
        $this->ensureToken($customer);

        $details = is_array($transaction->payment_details) ? $transaction->payment_details : [];
        $details['stamp_card'] = $summary;
        $transaction->payment_details = $details;
        $transaction->save();

        return $summary;
    }

    /** Tarik kembali stempel dari transaksi yang dibatalkan/dihapus. */
    public function reverseForTransaction(Transaction $transaction, ?int $userId = null): int
    {
        $earned = (int) StampCardLedger::query()->where('transaction_id', $transaction->id)->where('type', 'earn')->sum('stamps');
        $reversed = (int) StampCardLedger::query()->where('transaction_id', $transaction->id)->where('type', 'reverse')->sum('stamps');
        $toReverse = $earned + $reversed; // reverse dicatat negatif
        if ($toReverse <= 0 || ! $transaction->customer_id) {
            return 0;
        }

        $customer = Customer::query()->find($transaction->customer_id);
        $store = $transaction->store;
        if (! $customer || ! $store) {
            return 0;
        }

        $this->addStamps($store, $customer, -$toReverse, 'reverse', 'Batal transaksi '.$transaction->transaction_number, $userId, $transaction->id);

        return $toReverse;
    }

    /** Koreksi manual oleh pengelola (positif/negatif). */
    public function adjust(Store $store, Customer $customer, int $delta, string $note, ?int $userId): array
    {
        return $this->addStamps($store, $customer, $delta, 'adjust', $note, $userId);
    }

    /**
     * Hapus kartu stempel pelanggan: stempel & hadiah yang belum ditukar dikosongkan, tautan kartu
     * lama tidak berlaku lagi, dan pelanggan hilang dari daftar Kartu stempel. Data pelanggan dan
     * riwayat stempel tetap disimpan (riwayat dapat entri "hapus kartu").
     */
    public function removeCard(Store $store, Customer $customer, ?int $userId): void
    {
        DB::transaction(function () use ($store, $customer, $userId) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            StampCardLedger::query()->create([
                'store_id' => $store->id,
                'customer_id' => $locked->id,
                'type' => 'remove',
                'stamps' => -(int) $locked->stamp_count,
                'rewards_delta' => -(int) $locked->stamp_rewards_ready,
                'count_after' => 0,
                'rewards_after' => 0,
                'note' => 'Kartu dihapus',
                'created_by' => $userId,
            ]);

            $locked->forceFill([
                'stamp_count' => 0,
                'stamp_rewards_ready' => 0,
                'stamp_last_at' => null,
                'stamp_card_token' => null,
            ])->save();

            $customer->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Tukar 1 hadiah yang siap. */
    public function redeem(Store $store, Customer $customer, ?int $userId): array
    {
        return DB::transaction(function () use ($store, $customer, $userId) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($locked->stamp_rewards_ready < 1) {
                throw new \RuntimeException('Belum ada hadiah yang siap ditukar.');
            }

            $locked->stamp_rewards_ready -= 1;
            $locked->stamp_rewards_redeemed += 1;
            $locked->save();

            StampCardLedger::query()->create([
                'store_id' => $store->id,
                'customer_id' => $locked->id,
                'type' => 'redeem',
                'stamps' => 0,
                'rewards_delta' => -1,
                'count_after' => $locked->stamp_count,
                'rewards_after' => $locked->stamp_rewards_ready,
                'note' => 'Tukar hadiah: '.StampCardSettings::all($store)['reward'],
                'created_by' => $userId,
            ]);

            $customer->setRawAttributes($locked->getAttributes(), true);

            return $this->summary($store, $locked, 0);
        });
    }

    /**
     * Inti perubahan stempel. Positif: kartu penuh dikonversi jadi hadiah.
     * Negatif: kurangi stempel; bila kurang, tarik hadiah yang belum ditukar.
     */
    private function addStamps(Store $store, Customer $customer, int $delta, string $type, string $note, ?int $userId, ?int $transactionId = null): array
    {
        return DB::transaction(function () use ($store, $customer, $delta, $type, $note, $userId, $transactionId) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $target = StampCardSettings::target($store);

            if ($delta > 0 && $this->isExpired($store, $locked)) {
                $expired = (int) $locked->stamp_count;
                $locked->stamp_count = 0;
                StampCardLedger::query()->create([
                    'store_id' => $store->id,
                    'customer_id' => $locked->id,
                    'type' => 'expire',
                    'stamps' => -$expired,
                    'count_after' => 0,
                    'rewards_after' => $locked->stamp_rewards_ready,
                    'note' => 'Stempel hangus (lama tidak berkunjung)',
                ]);
            }

            $rewardsBefore = (int) $locked->stamp_rewards_ready;
            $count = (int) $locked->stamp_count + $delta;
            $rewards = $rewardsBefore;

            while ($count >= $target) {
                $count -= $target;
                $rewards++;
            }
            while ($count < 0 && $rewards > 0) {
                $count += $target;
                $rewards--;
            }
            $count = max(0, $count);

            $locked->stamp_count = $count;
            $locked->stamp_rewards_ready = $rewards;
            if ($delta > 0) {
                $locked->stamp_last_at = now();
            }
            $locked->save();

            StampCardLedger::query()->create([
                'store_id' => $store->id,
                'customer_id' => $locked->id,
                'transaction_id' => $transactionId,
                'type' => $type,
                'stamps' => $delta,
                'rewards_delta' => $rewards - $rewardsBefore,
                'count_after' => $count,
                'rewards_after' => $rewards,
                'note' => mb_substr($note, 0, 255),
                'created_by' => $userId,
            ]);

            $customer->setRawAttributes($locked->getAttributes(), true);

            return $this->summary($store, $locked, $delta, $rewards - $rewardsBefore);
        });
    }

    /** @return array<string, mixed> */
    public function summary(Store $store, Customer $customer, int $added = 0, int $newRewards = 0): array
    {
        $rules = StampCardSettings::all($store);

        return [
            'added' => $added,
            'count' => $this->currentCount($store, $customer),
            'target' => $rules['target'],
            'rewards_ready' => (int) $customer->stamp_rewards_ready,
            'new_rewards' => $newRewards,
            'reward' => $rules['reward'],
        ];
    }

    /** Data kartu untuk kasir/pengelola. */
    public function cardPayload(Store $store, Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'card_url' => $this->publicUrl($customer),
        ] + $this->summary($store, $customer);
    }

    /** Samakan nomor HP: hanya angka, awalan 62 → 0. */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /** Cari pelanggan dari isi QR (tautan /k/{token}) atau nomor WA. */
    public function findCustomer(Store $store, string $input): ?Customer
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('~/k/([A-Za-z0-9]{12,40})~', $input, $m) || preg_match('~^[a-z0-9]{24}$~', $input, $m)) {
            $token = $m[1] ?? $m[0];
            $customer = Customer::query()->where('store_id', $store->id)->where('stamp_card_token', strtolower($token))->first();
            if ($customer) {
                return $customer;
            }
        }

        $phone = self::normalizePhone($input);
        if (strlen($phone) < 8) {
            return null;
        }

        // Nomor tersimpan bisa berformat bebas (spasi, strip, +62), jadi dibandingkan setelah dinormalkan.
        return Customer::query()
            ->where('store_id', $store->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderByDesc('stamp_last_at')
            ->cursor()
            ->first(fn (Customer $c) => self::normalizePhone($c->phone) === $phone);
    }
}
