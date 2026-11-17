<?php

/*
 * Perintah demo:central-kitchen: aman untuk server (wajib --production, kata sandi kuat)
 * dan bisa diulang tanpa menumpuk data.
 */

use App\Models\Organization;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    SubscriptionPlan::query()->create([
        'name' => 'GrowUp',
        'slug' => 'bisnis',
        'price' => 99000,
        'billing_cycle' => 'monthly',
        'is_active' => true,
        'max_stores' => 3,
    ]);
});

it('menolak berjalan di production tanpa --production', function () {
    app()->instance('env', 'production');

    $this->artisan('demo:central-kitchen')->assertFailed();

    expect(Organization::query()->where('slug', 'demo-resto-nusantara')->exists())->toBeFalse();
});

it('menolak kata sandi pendek', function () {
    app()->instance('env', 'production');

    $this->artisan('demo:central-kitchen', ['--production' => true, '--password' => 'pendek'])->assertFailed();

    expect(User::query()->where('email', 'demo.owner@kasirumkm.test')->exists())->toBeFalse();
});

it('membuat akun demo di production dengan kata sandi yang diberikan dan bisa diulang', function () {
    app()->instance('env', 'production');

    $this->artisan('demo:central-kitchen', ['--production' => true, '--password' => 'DemoKuat-2026xyz'])->assertSuccessful();
    $this->artisan('demo:central-kitchen', ['--production' => true, '--password' => 'DemoKuat-2026xyz'])->assertSuccessful();

    $owner = User::query()->where('email', 'demo.owner@kasirumkm.test')->sole();
    $org = Organization::query()->where('slug', 'demo-resto-nusantara')->sole();

    expect(Hash::check('DemoKuat-2026xyz', $owner->password))->toBeTrue()
        ->and(Hash::check('password', $owner->password))->toBeFalse()
        ->and(Store::query()->where('organization_id', $org->id)->count())->toBe(3)
        ->and(User::query()->where('email', 'like', 'demo.%@kasirumkm.test')->count())->toBe(5)
        ->and(StockRequest::query()->where('organization_id', $org->id)->count())->toBe(4);
});

it('di production tanpa --password membuat kata sandi acak, bukan "password"', function () {
    app()->instance('env', 'production');

    $this->artisan('demo:central-kitchen', ['--production' => true])->assertSuccessful();

    expect(Hash::check('password', User::query()->where('email', 'demo.gudang@kasirumkm.test')->sole()->password))->toBeFalse();
});
