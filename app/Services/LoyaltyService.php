<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyNotification;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\LoyaltySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inti logika program loyalitas: perolehan, penukaran, kadaluarsa, dan penyesuaian poin.
 *
 * Semua mutasi saldo terjadi di dalam transaksi DB dengan lock baris customer
 * untuk mencegah race condition & manipulasi saldo.
 */
class LoyaltyService
{
    /**
     * Perolehan poin dari transaksi penjualan yang berhasil.
     * Mengembalikan jumlah poin yang diperoleh (0 bila loyalitas nonaktif / tanpa pelanggan).
     */
    public function earnFromTransaction(Transaction $transaction): int
    {
        $store = $transaction->store;
        $customer = $transaction->customer;

        if (! $store || ! $customer || ! LoyaltySettings::enabled($store)) {
            return 0;
        }

        $points = LoyaltySettings::pointsForAmount($store, (float) $transaction->total_amount);
        if ($points <= 0) {
            return 0;
        }

        $this->earn(
            $customer,
            $points,
            'earn',
            "Poin dari transaksi #{$transaction->id}",
            $transaction,
            $transaction->user_id,
        );

        return $points;
    }

    /**
     * Tambah poin ke saldo pelanggan (perolehan/bonus). Aman terhadap konkurensi.
     */
    public function earn(
        Customer $customer,
        int $points,
        string $type = 'earn',
        ?string $description = null,
        ?object $source = null,
        ?int $createdBy = null,
    ): LoyaltyPointLedger {
        if ($points <= 0) {
            throw new RuntimeException('Poin yang diperoleh harus lebih dari 0.');
        }

        return DB::transaction(function () use ($customer, $points, $type, $description, $source, $createdBy) {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $store = $locked->store;

            $expiryMonths = LoyaltySettings::expiryMonths($store);
            $expiresAt = $expiryMonths > 0 ? now()->addMonths($expiryMonths) : null;

            $balanceAfter = $locked->points_balance + $points;

            $ledger = $this->writeLedger([
                'store_id' => $locked->store_id,
                'customer_id' => $locked->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'points_remaining' => $points,
                'expires_at' => $expiresAt,
                'description' => $description,
                'source' => $source,
                'created_by' => $createdBy,
            ]);

            $locked->forceFill([
                'points_balance' => $balanceAfter,
                'points_earned_total' => $locked->points_earned_total + $points,
            ])->save();

            $this->notify($locked, 'points_earned', 'Poin bertambah', "{$points} poin ditambahkan. Saldo: {$balanceAfter}.");

            return $ledger;
        });
    }

    /**
     * Tukar poin dengan hadiah. Memvalidasi stok hadiah & saldo poin di dalam lock.
     */
    public function redeem(Customer $customer, LoyaltyReward $reward, ?int $createdBy = null): LoyaltyRedemption
    {
        return DB::transaction(function () use ($customer, $reward, $createdBy) {
            /** @var Customer $lockedCustomer */
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            /** @var LoyaltyReward $lockedReward */
            $lockedReward = LoyaltyReward::query()->lockForUpdate()->findOrFail($reward->id);

            if ($lockedReward->store_id !== $lockedCustomer->store_id) {
                throw new RuntimeException('Hadiah tidak tersedia untuk toko ini.');
            }

            if (! $lockedReward->is_active) {
                throw new RuntimeException('Hadiah tidak aktif.');
            }

            if ($lockedReward->stock !== null && $lockedReward->stock <= 0) {
                throw new RuntimeException('Stok hadiah habis.');
            }

            if ($lockedCustomer->points_balance < $lockedReward->points_cost) {
                throw new RuntimeException('Saldo poin tidak mencukupi untuk menukar hadiah ini.');
            }

            $cost = $lockedReward->points_cost;
            $balanceAfter = $lockedCustomer->points_balance - $cost;

            // Kurangi poin secara FIFO dari baris earn yang masih punya sisa.
            $this->consumePoints($lockedCustomer, $cost);

            $voucherCode = $lockedReward->type === 'voucher'
                ? strtoupper('RWD-'.Str::random(8))
                : null;

            $redemption = LoyaltyRedemption::create([
                'store_id' => $lockedCustomer->store_id,
                'customer_id' => $lockedCustomer->id,
                'loyalty_reward_id' => $lockedReward->id,
                'reward_name' => $lockedReward->name,
                'reward_type' => $lockedReward->type,
                'points_spent' => $cost,
                'voucher_value' => $lockedReward->voucher_value,
                'status' => 'fulfilled',
                'voucher_code' => $voucherCode,
                'created_by' => $createdBy,
            ]);

            $this->writeLedger([
                'store_id' => $lockedCustomer->store_id,
                'customer_id' => $lockedCustomer->id,
                'type' => 'redeem',
                'points' => -$cost,
                'balance_after' => $balanceAfter,
                'points_remaining' => 0,
                'expires_at' => null,
                'description' => "Penukaran hadiah: {$lockedReward->name}",
                'source' => $redemption,
                'created_by' => $createdBy,
            ]);

            $lockedCustomer->forceFill([
                'points_balance' => $balanceAfter,
                'points_redeemed_total' => $lockedCustomer->points_redeemed_total + $cost,
            ])->save();

            if ($lockedReward->stock !== null) {
                $lockedReward->decrement('stock');
            }
            $lockedReward->increment('redeemed_count');

            $this->notify(
                $lockedCustomer,
                'redemption_success',
                'Penukaran berhasil',
                "Hadiah \"{$lockedReward->name}\" berhasil ditukar ({$cost} poin). Saldo: {$balanceAfter}.",
            );

            return $redemption;
        });
    }

    /**
     * Penyesuaian manual oleh admin (klaim poin, koreksi). Delta bisa + atau -.
     */
    public function adjust(Customer $customer, int $delta, string $reason, ?int $createdBy = null): LoyaltyPointLedger
    {
        if ($delta === 0) {
            throw new RuntimeException('Nilai penyesuaian tidak boleh 0.');
        }

        return DB::transaction(function () use ($customer, $delta, $reason, $createdBy) {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $balanceAfter = $locked->points_balance + $delta;
            if ($balanceAfter < 0) {
                throw new RuntimeException('Penyesuaian membuat saldo poin menjadi negatif.');
            }

            $update = ['points_balance' => $balanceAfter];
            $expiresAt = null;

            if ($delta > 0) {
                $store = $locked->store;
                $months = LoyaltySettings::expiryMonths($store);
                $expiresAt = $months > 0 ? now()->addMonths($months) : null;
                $update['points_earned_total'] = $locked->points_earned_total + $delta;
            } else {
                $this->consumePoints($locked, -$delta);
                $update['points_redeemed_total'] = $locked->points_redeemed_total + (-$delta);
            }

            $ledger = $this->writeLedger([
                'store_id' => $locked->store_id,
                'customer_id' => $locked->id,
                'type' => 'adjust',
                'points' => $delta,
                'balance_after' => $balanceAfter,
                'points_remaining' => $delta > 0 ? $delta : 0,
                'expires_at' => $expiresAt,
                'description' => $reason,
                'source' => null,
                'created_by' => $createdBy,
            ]);

            $locked->forceFill($update)->save();

            return $ledger;
        });
    }

    /**
     * Proses kadaluarsa poin untuk satu toko. Mengembalikan total poin yang dikadaluarsakan.
     */
    public function expirePointsForStore(Store $store): int
    {
        $expiredTotal = 0;

        $rows = LoyaltyPointLedger::query()
            ->where('store_id', $store->id)
            ->whereIn('type', ['earn', 'adjust', 'bonus_birthday', 'bonus_referral'])
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('customer_id')
            ->get();

        $byCustomer = $rows->groupBy('customer_id');

        foreach ($byCustomer as $customerId => $ledgers) {
            $expiredTotal += DB::transaction(function () use ($customerId, $ledgers) {
                /** @var Customer $customer */
                $customer = Customer::query()->lockForUpdate()->find($customerId);
                if (! $customer) {
                    return 0;
                }

                // Batch-lock all ledgers for this customer
                $ledgerIds = $ledgers->pluck('id')->values();
                $freshRows = LoyaltyPointLedger::query()
                    ->lockForUpdate()
                    ->whereIn('id', $ledgerIds)
                    ->get()
                    ->keyBy('id');

                $sum = 0;
                foreach ($ledgers as $row) {
                    $fresh = $freshRows->get($row->id);
                    if (! $fresh || $fresh->points_remaining <= 0) {
                        continue;
                    }
                    $sum += $fresh->points_remaining;
                    $fresh->forceFill(['points_remaining' => 0])->save();
                }

                if ($sum <= 0) {
                    return 0;
                }

                $balanceAfter = max(0, $customer->points_balance - $sum);

                $this->writeLedger([
                    'store_id' => $customer->store_id,
                    'customer_id' => $customer->id,
                    'type' => 'expire',
                    'points' => -$sum,
                    'balance_after' => $balanceAfter,
                    'points_remaining' => 0,
                    'expires_at' => null,
                    'description' => "Poin kadaluarsa ({$sum} poin)",
                    'source' => null,
                    'created_by' => null,
                ]);

                $customer->forceFill(['points_balance' => $balanceAfter])->save();

                $this->notify($customer, 'points_expiring', 'Poin kadaluarsa', "{$sum} poin telah kadaluarsa. Saldo: {$balanceAfter}.");

                return $sum;
            });
        }

        return $expiredTotal;
    }

    /**
     * Kirim peringatan untuk poin yang akan segera kadaluarsa.
     * Mengembalikan jumlah pelanggan yang diperingatkan.
     */
    public function warnExpiringForStore(Store $store): int
    {
        $warnDays = LoyaltySettings::expiryWarnDays($store);

        $rows = LoyaltyPointLedger::query()
            ->where('store_id', $store->id)
            ->whereIn('type', ['earn', 'adjust', 'bonus_birthday', 'bonus_referral'])
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($warnDays)])
            ->get()
            ->groupBy('customer_id');

        $count = 0;

        // Batch-load customers to avoid N+1 queries
        $customerIds = $rows->keys();
        $customers = Customer::query()->whereIn('id', $customerIds)->get()->keyBy('id');

        foreach ($rows as $customerId => $ledgers) {
            $customer = $customers->get($customerId);
            if (! $customer) {
                continue;
            }
            $sum = $ledgers->sum('points_remaining');
            $soonest = $ledgers->min('expires_at');
            $this->notify(
                $customer,
                'points_expiring',
                'Poin akan kadaluarsa',
                "{$sum} poin akan kadaluarsa pada ".\Illuminate\Support\Carbon::parse($soonest)->format('d M Y').'.',
            );
            $count++;
        }

        return $count;
    }

    /**
     * Kirim notifikasi promo poin ke seluruh pelanggan toko (in-app + log).
     * Mengembalikan jumlah pelanggan yang menerima notifikasi.
     */
    public function broadcastPromo(Store $store, string $title, string $body): int
    {
        $count = 0;
        $store->customers()->select(['id', 'store_id'])->chunkById(500, function ($customers) use (&$count, $title, $body) {
            foreach ($customers as $customer) {
                $this->notify($customer, 'promo', $title, $body);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Kurangi poin secara FIFO dari baris ledger yang masih punya sisa.
     */
    private function consumePoints(Customer $customer, int $amount): void
    {
        $remaining = $amount;

        $rows = LoyaltyPointLedger::query()
            ->where('customer_id', $customer->id)
            ->where('points_remaining', '>', 0)
            ->orderByRaw('expires_at is null asc') // yang punya expiry didahulukan
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($row->points_remaining, $remaining);
            $row->forceFill(['points_remaining' => $row->points_remaining - $take])->save();
            $remaining -= $take;
        }
    }

    /**
     * Tulis baris ledger + hash integritas.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLedger(array $data): LoyaltyPointLedger
    {
        $source = $data['source'] ?? null;
        unset($data['source']);

        if ($source !== null) {
            $data['source_type'] = $source::class;
            $data['source_id'] = $source->getKey();
        }

        $ledger = new LoyaltyPointLedger($data);
        $ledger->save();

        // Hash setelah tersimpan agar created_at tersedia.
        $ledger->integrity_hash = $ledger->generateIntegrityHash();
        $ledger->saveQuietly();

        return $ledger;
    }

    private function notify(Customer $customer, string $type, string $title, string $body): void
    {
        LoyaltyNotification::create([
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'type' => $type,
            'channel' => 'in_app',
            'title' => $title,
            'body' => $body,
            'delivery_status' => 'logged',
        ]);

        Log::info('[loyalty] '.$title, [
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'type' => $type,
            'body' => $body,
        ]);
    }
}
