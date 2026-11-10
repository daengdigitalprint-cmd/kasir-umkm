<?php

use App\Models\CashShift;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Tools\Reports\TransactionAnomalyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFraudStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Fraud',
        'slug' => 'toko-fraud-'.uniqid(),
        'is_active' => true,
    ]);
}

function recordFraudTransaction(Store $store, User $user, array $overrides = []): Transaction
{
    $transaction = Transaction::create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-FRD-'.uniqid(),
        'subtotal' => 100000,
        'total_amount' => 100000,
        'amount_paid' => 100000,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ], $overrides));

    $transaction->forceFill(['created_at' => now()])->save();

    return $transaction;
}

it('menandai transaksi dengan diskon tidak wajar', function () {
    $store = makeFraudStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    recordFraudTransaction($store, $user, ['discount_amount' => 35000]);
    recordFraudTransaction($store, $user, ['discount_amount' => 5000]);

    $result = (new TransactionAnomalyTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_flags'])->toBe(1)
        ->and($result->data['discount_anomalies'])->toHaveCount(1)
        ->and($result->data['discount_anomalies'][0]['discount_pct'])->toBe(35.0)
        ->and($result->data['discount_anomalies'][0]['discount_amount'])->toBe(35000.0)
        ->and($result->data['discount_anomalies'][0]['cashier'])->toBe($user->name)
        ->and($result->message)->toContain('Diskon tidak wajar');
});

it('menandai void berulang oleh kasir yang sama', function () {
    $store = makeFraudStore();
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $other = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    foreach (range(1, 3) as $i) {
        recordFraudTransaction($store, $cashier, [
            'is_void' => true,
            'void_reason' => 'customer batal',
            'void_at' => now(),
            'void_by' => $cashier->id,
        ]);
    }

    recordFraudTransaction($store, $other, [
        'is_void' => true,
        'void_reason' => 'input salah',
        'void_at' => now(),
        'void_by' => $other->id,
    ]);

    $result = (new TransactionAnomalyTool)->execute([], $cashier);

    expect($result->success)->toBeTrue()
        ->and($result->data['void_patterns'])->toHaveCount(1)
        ->and($result->data['void_patterns'][0]['cashier'])->toBe($cashier->name)
        ->and($result->data['void_patterns'][0]['void_count'])->toBe(3)
        ->and($result->data['void_patterns'][0]['total_void_amount'])->toBe(300000.0)
        ->and($result->data['void_patterns'][0]['sample_transactions'])->toHaveCount(3)
        ->and($result->message)->toContain('Void berulang');
});

it('menandai shift kas dengan selisih hitungan fisik', function () {
    $store = makeFraudStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
        'opening_cash' => 100000,
        'expected_cash' => 500000,
        'closing_cash_counted' => 480000,
        'difference_amount' => -20000,
    ]);

    $result = (new TransactionAnomalyTool)->execute([], $user);
    $row = $result->data['cash_differences'][0];

    expect($result->success)->toBeTrue()
        ->and($result->data['cash_differences'])->toHaveCount(1)
        ->and($row['expected_cash'])->toBe(500000.0)
        ->and($row['counted_cash'])->toBe(480000.0)
        ->and($row['difference'])->toBe(-20000.0)
        ->and($row['cashier'])->toBe($user->name)
        ->and($result->message)->toContain('Selisih kas shift');
});

it('menggabungkan seluruh indikasi anomali dalam satu laporan', function () {
    $store = makeFraudStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    recordFraudTransaction($store, $user, ['discount_amount' => 40000]);

    foreach (range(1, 3) as $i) {
        recordFraudTransaction($store, $user, [
            'is_void' => true,
            'void_reason' => 'customer batal',
            'void_at' => now(),
            'void_by' => $user->id,
        ]);
    }

    CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
        'opening_cash' => 50000,
        'expected_cash' => 300000,
        'closing_cash_counted' => 295000,
        'difference_amount' => -5000,
    ]);

    $result = (new TransactionAnomalyTool)->execute([], $user);

    expect($result->data['total_flags'])->toBe(3)
        ->and($result->message)->toBe(implode("\n", [
            '## Ringkasan Audit Transaksi',
            '',
            'Ditemukan **3 jenis indikasi**.',
            '',
            '- **Diskon tidak wajar:** 1 transaksi',
            '- **Void berulang:** 1 kasir',
            '- **Selisih kas shift:** 1 shift',
            '',
            '### Tindak lanjut',
            '',
            '- Tinjau transaksi dengan diskon **≥30%** dari subtotal.',
            '- Lakukan audit manual untuk seluruh temuan.',
        ]));
});

it('melaporkan toko bersih tanpa indikasi anomali', function () {
    $store = makeFraudStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    recordFraudTransaction($store, $user, ['discount_amount' => 0]);

    CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
        'opening_cash' => 100000,
        'expected_cash' => 500000,
        'closing_cash_counted' => 500000,
        'difference_amount' => 0,
    ]);

    $result = (new TransactionAnomalyTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_flags'])->toBe(0)
        ->and($result->message)->toBe(implode("\n", [
            '## Ringkasan Audit Transaksi',
            '',
            '- **Status:** Tidak ada transaksi mencurigakan pada periode ini.',
            '- Pemantauan mencakup diskon besar, void berulang, dan selisih kas shift.',
        ]));
});

it('menolak user tanpa toko', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $result = (new TransactionAnomalyTool)->execute([], $user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('tidak memiliki toko');
});
