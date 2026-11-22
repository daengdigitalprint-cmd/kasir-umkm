<?php

use App\Models\CashShift;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** Outlet aktif dengan satu kasir dan satu admin. */
function makeUsabilityOrg(string $tz = 'Asia/Jakarta'): array
{
    $store = Store::query()->create([
        'name' => 'Usability Store',
        'slug' => 'shift-usab-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'timezone' => $tz,
        'settings' => ['shift_digital_signature' => true],
    ]);

    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    $admin = User::factory()->create(['store_id' => $store->id, 'email_verified_at' => now()]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    return [$store, $cashier, $admin];
}

function makeShift(Store $store, User $user, array $attrs = []): CashShift
{
    return CashShift::query()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'shift_number' => 'T-'.uniqid(),
        'opened_at' => now()->subHours(2),
        'opening_cash' => 100000,
    ], $attrs));
}

function closeShiftAs(User $user, CashShift $shift, array $data)
{
    return test()->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post("/cash-shifts/{$shift->id}/close", array_merge(['_token' => 'csrf-token'], $data));
}

test('halaman shift mengirim zona waktu toko dan saran kas awal dari shift terakhir', function () {
    [$store, $cashier] = makeUsabilityOrg('Asia/Jayapura');
    makeShift($store, $cashier, [
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subHours(12),
        'closing_cash_counted' => 250000,
        'expected_cash' => 250000,
        'difference_amount' => 0,
    ]);

    $this->actingAs($cashier)->get('/cash-shifts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CashShifts/Index')
            ->where('timezone', 'Asia/Jayapura')
            ->where('suggestedOpeningCash', 250000)
            ->where('canViewAllShifts', false));
});

test('kasir tidak melihat perkiraan kas maupun selisih di shift aktif dan riwayat', function () {
    [$store, $cashier] = makeUsabilityOrg();
    makeShift($store, $cashier, [
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subHours(20),
        'closing_cash_counted' => 90000,
        'expected_cash' => 100000,
        'difference_amount' => -10000,
    ]);
    makeShift($store, $cashier);

    $this->actingAs($cashier)->get('/cash-shifts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('openShift.expected_cash', null)
            ->where('openShift.sales_total', null)
            ->where('openShift.is_own', true)
            ->where('shifts.1.expected_cash', null)
            ->where('shifts.1.difference_amount', null));
});

test('admin melihat shift kasir lain lengkap dengan nama pemiliknya', function () {
    [$store, $cashier, $admin] = makeUsabilityOrg();
    makeShift($store, $cashier, ['opened_at' => now()->subHours(30)]);

    $this->actingAs($admin)->get('/cash-shifts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('openShift.is_own', false)
            ->where('openShift.owner_name', $cashier->name)
            ->where('openShift.expected_cash', 100000)
            ->where('openShift.hours_open', 30)
            ->where('canViewAllShifts', true));
});

test('selisih besar tanpa catatan ditolak, dengan catatan diterima', function () {
    [$store, $cashier] = makeUsabilityOrg();
    $shift = makeShift($store, $cashier);

    closeShiftAs($cashier, $shift, ['closing_cash_counted' => 50000])
        ->assertSessionHasErrors('closing_notes');
    expect($shift->fresh()->closed_at)->toBeNull();

    closeShiftAs($cashier, $shift, ['closing_cash_counted' => 50000, 'closing_notes' => 'Belanja es batu'])
        ->assertRedirect("/cash-shifts?closed={$shift->id}");
    expect((float) $shift->fresh()->difference_amount)->toBe(-50000.0);

    // Ringkasan + tautan cetak muncul untuk kasir pemilik shift, tanpa angka selisih (hitung buta).
    $this->actingAs($cashier)->get("/cash-shifts?closed={$shift->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('justClosed.id', $shift->id)
            ->where('justClosed.closing_cash_counted', 50000)
            ->where('justClosed.difference_amount', null));
});

test('selisih kecil boleh tanpa catatan', function () {
    [$store, $cashier] = makeUsabilityOrg();
    $shift = makeShift($store, $cashier);

    closeShiftAs($cashier, $shift, ['closing_cash_counted' => 95000])
        ->assertSessionHasNoErrors();
    expect($shift->fresh()->closed_at)->not->toBeNull();
});

test('laporan shift bisa dicetak pemiliknya dan admin, tidak oleh kasir lain', function () {
    [$store, $cashier, $admin] = makeUsabilityOrg();
    $other = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $store->members()->attach($other->id, ['role' => 'cashier']);

    $shift = makeShift($store, $cashier, [
        'closed_at' => now(),
        'closing_cash_counted' => 100000,
        'expected_cash' => 100000,
        'difference_amount' => 0,
    ]);

    $this->actingAs($cashier)->get("/cash-shifts/{$shift->id}/print")
        ->assertOk()
        ->assertSee($shift->shift_number)
        ->assertDontSee('Seharusnya di laci');

    $this->actingAs($admin)->get("/cash-shifts/{$shift->id}/print")
        ->assertOk()
        ->assertSee('Seharusnya di laci');

    $this->actingAs($other)->get("/cash-shifts/{$shift->id}/print")->assertForbidden();
});

/** PNG kecil yang valid (garis tanda tangan tiruan) sebagai data URL. */
function fakeSignature(): string
{
    $img = imagecreatetruecolor(120, 40);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imageline($img, 5, 30, 110, 8, imagecolorallocate($img, 0, 0, 0));
    ob_start();
    imagepng($img);
    $png = ob_get_clean();

    return 'data:image/png;base64,'.base64_encode($png);
}

function signAs(User $user, CashShift $shift, string $role, ?string $signature = null)
{
    return test()->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson("/cash-shifts/{$shift->id}/signature", [
            '_token' => 'csrf-token',
            'role' => $role,
            'signature' => $signature ?? fakeSignature(),
        ]);
}

test('kasir dan pengelola bisa menandatangani shift yang sudah ditutup, hanya sekali', function () {
    [$store, $cashier, $admin] = makeUsabilityOrg();
    $shift = makeShift($store, $cashier, ['closed_at' => now(), 'closing_cash_counted' => 100000]);

    signAs($cashier, $shift, 'cashier')->assertOk();
    signAs($admin, $shift, 'manager')->assertOk();

    $fresh = $shift->fresh();
    expect($fresh->cashier_signed_name)->toBe($cashier->name)
        ->and($fresh->cashier_signed_at)->not->toBeNull()
        ->and($fresh->manager_signed_by)->toBe($admin->id);

    signAs($cashier, $shift, 'cashier')->assertStatus(422);

    $this->actingAs($admin)->get("/cash-shifts/{$shift->id}/print")
        ->assertOk()
        ->assertSee('Tanda tangan Kasir', false)
        ->assertSee($admin->name)
        ->assertDontSee('Tanda tangan di layar');
});

test('tanda tangan ditolak untuk shift terbuka, peran yang salah, dan gambar palsu', function () {
    [$store, $cashier, $admin] = makeUsabilityOrg();
    $other = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $store->members()->attach($other->id, ['role' => 'cashier']);

    $open = makeShift($store, $cashier);
    signAs($cashier, $open, 'cashier')->assertStatus(422);

    $closed = makeShift($store, $cashier, ['closed_at' => now(), 'closing_cash_counted' => 100000]);
    signAs($other, $closed, 'cashier')->assertForbidden();
    signAs($cashier, $closed, 'manager')->assertForbidden();
    signAs($cashier, $closed, 'cashier', 'data:image/png;base64,'.base64_encode('bukan gambar'))->assertStatus(422);

    expect($closed->fresh()->cashier_signature)->toBeNull();
});

test('tanda tangan digital bawaan nonaktif dan diatur dari pengaturan toko (modul)', function () {
    [$store, $cashier, $admin] = makeUsabilityOrg();
    $store->update(['settings' => []]); // toko baru: belum pernah diatur
    $shift = makeShift($store, $cashier, ['closed_at' => now(), 'closing_cash_counted' => 100000]);
    $admin = $admin->fresh();
    $cashier = $cashier->fresh();

    $saveSetting = fn (User $user, bool $on) => test()->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->put('/settings/store', [
            '_token' => 'csrf-token',
            'name' => $store->name,
            'business_type' => 'general',
            'shift_digital_signature' => $on,
        ]);

    // Bawaan nonaktif: tidak ada tombol, simpan ditolak, sakelar modul mati.
    $this->actingAs($admin)->get('/cash-shifts')
        ->assertInertia(fn (Assert $page) => $page->where('signatureEnabled', false));
    $this->actingAs($admin)->get('/settings/store/fitur')
        ->assertInertia(fn (Assert $page) => $page->where('store.shift_digital_signature', false));
    $this->actingAs($cashier)->get("/cash-shifts/{$shift->id}/print")
        ->assertOk()
        ->assertDontSee('Tanda tangan di layar');
    signAs($cashier, $shift, 'cashier')->assertStatus(422);

    // Kasir tidak bisa menyalakannya.
    $saveSetting($cashier, true); // dialihkan oleh middleware role.store_admin
    expect(($store->fresh()->settings ?? [])['shift_digital_signature'] ?? false)->toBeFalse();

    // Pengelola menyalakan: tombol muncul dan kasir bisa tanda tangan.
    $saveSetting($admin, true)->assertRedirect();
    expect($store->fresh()->settings['shift_digital_signature'])->toBeTrue();
    $cashier = $cashier->fresh(); // relasi store yang tersimpan di objek tes sudah usang
    $this->actingAs($cashier)->get("/cash-shifts/{$shift->id}/print")->assertSee('Tanda tangan di layar');
    signAs($cashier, $shift, 'cashier')->assertOk();

    // Dimatikan lagi: tanda tangan lama tetap tampil.
    $saveSetting($admin->fresh(), false)->assertRedirect();
    $this->actingAs($admin->fresh())->get("/cash-shifts/{$shift->id}/print")
        ->assertSee('Tanda tangan Kasir', false)
        ->assertDontSee('Tanda tangan di layar');
});
