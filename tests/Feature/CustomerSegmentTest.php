<?php

/*
 * F9 — Segmentasi pelanggan: Setia, Baru, Lama tidak datang + nomor WhatsApp.
 */

use App\Models\Customer;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CustomerSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function segStore(): array
{
    $store = Store::query()->create([
        'name' => 'Kopi Senja',
        'slug' => 'senja-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $user];
}

function segCustomer(Store $store, string $name, ?string $phone = '081234567890'): Customer
{
    return Customer::query()->create(['store_id' => $store->id, 'name' => $name, 'phone' => $phone]);
}

/** Satu transaksi selesai untuk pelanggan, $daysAgo hari lalu. */
function segVisit(Store $store, User $user, Customer $customer, int $daysAgo, array $overrides = []): void
{
    $t = Transaction::query()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'transaction_number' => 'TXN-SEG-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 20000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 20000,
        'amount_paid' => 20000,
        'change_amount' => 0,
    ], $overrides));
    $when = now()->subDays($daysAgo)->setTime(10, 0);
    $t->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
}

function segIds(Store $store, string $segment): array
{
    $settings = CustomerSegments::settings($store);

    return CustomerSegments::applySegment(CustomerSegments::customersWithStats($store, $settings), $segment, $settings)
        ->pluck('customers.id')->sort()->values()->all();
}

it('mengelompokkan pelanggan ke Setia, Baru, dan Lama tidak datang', function () {
    [$store, $user] = segStore();

    // Setia: 4 hari berbeda dalam 30 hari (pelanggan lama).
    $setia = segCustomer($store, 'Budi');
    foreach ([90, 20, 10, 5, 1] as $d) {
        segVisit($store, $user, $setia, $d);
    }

    // Baru: pertama datang 7 hari lalu.
    $baru = segCustomer($store, 'Sari');
    segVisit($store, $user, $baru, 7);

    // Lama tidak datang: 3 kunjungan, terakhir 45 hari lalu.
    $lama = segCustomer($store, 'Tono');
    foreach ([100, 70, 45] as $d) {
        segVisit($store, $user, $lama, $d);
    }

    // Tidak masuk segmen mana pun: 2 kunjungan lama, terakhir 40 hari lalu.
    $biasa = segCustomer($store, 'Ani');
    segVisit($store, $user, $biasa, 60);
    segVisit($store, $user, $biasa, 40);

    expect(segIds($store, 'setia'))->toBe([$setia->id])
        ->and(segIds($store, 'baru'))->toBe([$baru->id])
        ->and(segIds($store, 'lama'))->toBe([$lama->id]);
});

it('menghitung kunjungan per hari, bukan per struk', function () {
    [$store, $user] = segStore();
    $c = segCustomer($store, 'Rina');
    // 4 struk tapi hanya 2 hari berbeda → belum Setia.
    foreach ([3, 3, 2, 2] as $d) {
        segVisit($store, $user, $c, $d);
    }

    expect(segIds($store, 'setia'))->toBe([]);
});

it('mengabaikan transaksi batal, belum selesai, dan toko lain', function () {
    [$store, $user] = segStore();
    $c = segCustomer($store, 'Dodi');
    foreach ([1, 2, 3, 4] as $d) {
        segVisit($store, $user, $c, $d, ['is_void' => true]);
    }
    foreach ([5, 6, 7, 8] as $d) {
        segVisit($store, $user, $c, $d, ['status' => 'pending']);
    }

    expect(segIds($store, 'setia'))->toBe([])
        ->and(segIds($store, 'baru'))->toBe([]);
});

it('ambang segmen bisa diubah dan halaman menampilkan jumlah per segmen', function () {
    [$store, $user] = segStore();
    $c = segCustomer($store, 'Lia');
    foreach ([40, 9, 2] as $d) {
        segVisit($store, $user, $c, $d);
    }

    $this->actingAs($user)->get('/customers/segments?segment=setia')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customers/Segments')->where('counts.setia', 0));

    $this->actingAs($user)->put('/customers/segments/settings', [
        'loyal_visits' => 2,
        'loyal_days' => 30,
        'new_days' => 30,
        'lapsed_min_visits' => 3,
        'lapsed_days' => 30,
        'messages' => ['setia' => 'Hai {nama}', 'baru' => 'Halo {nama}', 'lama' => 'Kangen {nama}'],
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get('/customers/segments?segment=setia')->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.setia', 1)
            ->where('customers.data.0.name', 'Lia')
            ->where('customers.data.0.whatsapp', '6281234567890')
            ->where('settings.messages.setia', 'Hai {nama}'));
});

it('kasir tidak bisa mengubah pengaturan segmen', function () {
    [$store] = segStore();
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $cashier->stores()->attach($store->id, ['role' => 'cashier']);

    $this->actingAs($cashier)->put('/customers/segments/settings', [
        'loyal_visits' => 2, 'loyal_days' => 30, 'new_days' => 30, 'lapsed_min_visits' => 3, 'lapsed_days' => 30,
        'messages' => ['setia' => 'a', 'baru' => 'b', 'lama' => 'c'],
    ]);

    expect($store->fresh()->settings['customer_segments'] ?? null)->toBeNull();
});

it('menormalkan nomor untuk tautan WhatsApp', function (?string $raw, ?string $expected) {
    expect(CustomerSegments::whatsappNumber($raw))->toBe($expected);
})->with([
    ['0812-3456-7890', '6281234567890'],
    ['+62 812 3456 7890', '6281234567890'],
    ['81234567890', '6281234567890'],
    ['021555', null],
    [null, null],
]);

it('rute segmen tidak tertangkap sebagai detail pelanggan', function () {
    [, $user] = segStore();

    $this->actingAs($user)->get('/customers/segments')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customers/Segments')->where('segment', 'setia'));
});
