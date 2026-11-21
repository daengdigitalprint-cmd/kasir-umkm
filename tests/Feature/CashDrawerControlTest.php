<?php

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: User, 2: User, 3: CashShift}
 */
function drawerContext(array $settings = []): array
{
    $store = Store::query()->create([
        'name' => 'Toko Laci',
        'slug' => 'toko-laci-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);

    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    $shift = CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'opening_cash' => 100000,
        'opened_at' => now(),
    ]);

    return [$store, $cashier, $admin, $shift];
}

function drawerOut(array $extra = []): array
{
    return array_merge([
        'type' => 'out',
        'category' => CashTransaction::CATEGORY_EXPENSE,
        'amount' => 20000,
        'notes' => 'Beli galon',
    ], $extra);
}

test('uang keluar melebihi saldo laci ditolak kecuali dikonfirmasi', function () {
    [, $cashier] = drawerContext();

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 150000]))
        ->assertSessionHasErrors('amount');
    expect(CashTransaction::query()->count())->toBe(0);

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 150000, 'confirm_overdraw' => true]))
        ->assertSessionHasNoErrors();
    expect(CashTransaction::query()->count())->toBe(1);
});

test('uang keluar di atas batas butuh pin persetujuan untuk kasir', function () {
    [, $cashier, $admin] = drawerContext([
        'cash_out_approval_limit' => 50000,
        'cash_approval_pin_hash' => Hash::make('2468'),
    ]);

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 60000]))
        ->assertSessionHasErrors('approval_pin');
    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 60000, 'approval_pin' => '1357']))
        ->assertSessionHasErrors('approval_pin');

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 60000, 'approval_pin' => '2468']))
        ->assertSessionHasNoErrors();

    $ct = CashTransaction::query()->latest('id')->first();
    expect($ct->approved_with_pin)->toBeTrue();

    // Di bawah batas tidak perlu PIN.
    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 10000]))
        ->assertSessionHasNoErrors();
});

test('foto bukti tersimpan dan tampil di riwayat', function () {
    Storage::fake('public');
    [, $cashier] = drawerContext();

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut([
        'receipt' => UploadedFile::fake()->image('struk.jpg'),
    ]))->assertSessionHasNoErrors();

    $ct = CashTransaction::query()->first();
    Storage::disk('public')->assertExists($ct->receipt_path);

    $this->actingAs($cashier)->get('/cash-shifts/transactions')
        ->assertInertia(fn ($page) => $page->where('transactions.0.receipt_url', Storage::disk('public')->url($ct->receipt_path)));
});

test('membatalkan entri mengeluarkannya dari saldo dan membalik jurnal', function () {
    [$store, $cashier, , $shift] = drawerContext(['accounting_enabled' => true, 'plan_unlocked_modules' => ['accounting']]);

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut(['amount' => 30000]))->assertSessionHasNoErrors();
    $ct = CashTransaction::query()->first();

    $this->actingAs($cashier)->post("/cash-shifts/transactions/{$ct->id}/void", ['reason' => 'Salah ketik'])
        ->assertSessionHasNoErrors();

    $ct = CashTransaction::query()->withVoided()->find($ct->id);
    expect($ct->voided_at)->not->toBeNull()
        ->and($ct->void_reason)->toBe('Salah ketik')
        ->and(CashTransaction::query()->where('cash_shift_id', $shift->id)->count())->toBe(0);

    expect($ct->journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::query()->where('reference_type', 'journal_reversal')->where('reference_id', $ct->journal_entry_id)->exists())->toBeTrue();

    $this->actingAs($cashier)->get('/cash-shifts/transactions')
        ->assertInertia(fn ($page) => $page
            ->where('summary.cash_out', 0)
            ->where('summary.expected_cash', null) // hitung buta untuk kasir
            ->where('transactions.0.void_reason', 'Salah ketik'));

    // Tidak bisa dibatalkan dua kali.
    $this->actingAs($cashier)->post("/cash-shifts/transactions/{$ct->id}/void", ['reason' => 'lagi'])
        ->assertSessionHas('error');
});

test('kasir butuh pin untuk membatalkan bila pin persetujuan diatur', function () {
    [, $cashier] = drawerContext(['cash_approval_pin_hash' => Hash::make('2468')]);

    $this->actingAs($cashier)->post('/cash-shifts/transactions', drawerOut())->assertSessionHasNoErrors();
    $ct = CashTransaction::query()->first();

    $this->actingAs($cashier)->post("/cash-shifts/transactions/{$ct->id}/void", ['reason' => 'Salah'])
        ->assertSessionHasErrors('approval_pin');
    $this->actingAs($cashier)->post("/cash-shifts/transactions/{$ct->id}/void", ['reason' => 'Salah', 'approval_pin' => '2468'])
        ->assertSessionHasNoErrors();
});

test('pengaturan laci hanya untuk owner atau admin dan pin tidak dikirim ke browser', function () {
    [$store, $cashier, $admin] = drawerContext();

    $payload = [
        'approval_limit' => 100000,
        'approval_pin' => '2468',
        'presets' => [
            ['type' => 'out', 'category' => 'expense', 'label' => 'Gas elpiji', 'notes' => 'Beli gas elpiji', 'amount' => 25000],
        ],
    ];

    $this->actingAs($cashier)->put('/cash-shifts/transactions/settings', $payload)->assertForbidden();

    $this->actingAs($admin)->put('/cash-shifts/transactions/settings', $payload)->assertSessionHasNoErrors();

    $settings = $store->fresh()->settings;
    expect($settings['cash_out_approval_limit'])->toBe(100000)
        ->and(Hash::check('2468', $settings['cash_approval_pin_hash']))->toBeTrue()
        ->and($settings['cash_quick_presets'][0]['label'])->toBe('Gas elpiji');

    // Model uji menyimpan relasi toko dari permintaan sebelumnya; muat ulang seperti permintaan baru.
    $this->actingAs($cashier->fresh())->get('/cash-shifts/transactions')
        ->assertInertia(fn ($page) => $page
            ->where('drawerSettings.has_approval_pin', true)
            ->where('drawerSettings.approval_limit', 100000)
            ->missing('drawerSettings.cash_approval_pin_hash'));

    // Jenis yang tidak cocok dengan arah kas ditolak.
    $this->actingAs($admin)->put('/cash-shifts/transactions/settings', [
        'approval_limit' => 0,
        'presets' => [['type' => 'in', 'category' => 'expense', 'label' => 'X', 'notes' => 'X']],
    ])->assertSessionHasErrors('presets.0.category');
});
