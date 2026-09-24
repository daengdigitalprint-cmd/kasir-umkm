<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OutletAddOnService;
use App\Support\StorePlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutletAddOnTest extends TestCase
{
    use RefreshDatabase;

    private function createGrowUpPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'GrowUp',
            'slug' => 'bisnis',
            'price' => 99000,
            'duration_days' => 30,
            'max_products' => null,
            'max_stores' => 3,
            'is_active' => true,
            'is_popular' => true,
            'sort_order' => 2,
            'billing_cycle' => 'monthly',
        ]);
    }

    private function createStarterPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 0,
            'duration_days' => 0,
            'max_products' => 30,
            'max_stores' => 1,
            'is_active' => true,
            'sort_order' => 1,
            'billing_cycle' => 'monthly',
        ]);
    }

    private function createGrowUpStore(?Organization $org = null): Store
    {
        $plan = $this->createGrowUpPlan();
        $owner = User::factory()->create();

        $organization = $org ?? Organization::create([
            'name' => 'Grup Uji',
            'slug' => 'grup-uji-'.Str::random(5),
            'owner_user_id' => $owner->id,
        ]);

        $store = Store::create([
            'name' => 'Toko Uji',
            'slug' => 'toko-uji-'.Str::random(5),
            'is_active' => true,
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'subscription_start' => now(),
            'subscription_end' => now()->addDays(30),
            'subscription_days' => 30,
            'is_subscription_active' => true,
            'extra_outlets' => 0,
        ]);

        $store->members()->attach($owner->id, ['role' => 'admin']);

        return $store;
    }

    private function createStarterStore(?Organization $org = null): Store
    {
        $plan = $this->createStarterPlan();
        $owner = User::factory()->create();

        $organization = $org ?? Organization::create([
            'name' => 'Grup Starter',
            'slug' => 'grup-starter-'.Str::random(5),
            'owner_user_id' => $owner->id,
        ]);

        $store = Store::create([
            'name' => 'Toko Starter',
            'slug' => 'toko-starter-'.Str::random(5),
            'is_active' => true,
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'subscription_days' => 0,
            'is_subscription_active' => true,
            'extra_outlets' => 0,
        ]);

        $store->members()->attach($owner->id, ['role' => 'admin']);

        return $store;
    }

    // === Pricing ===

    public function test_outlet_price_calculation(): void
    {
        $this->assertEquals(85000, OutletAddOnService::priceFor(1));
        $this->assertEquals(170000, OutletAddOnService::priceFor(2));
        $this->assertEquals(850000, OutletAddOnService::priceFor(10));
    }

    public function test_outlet_price_yearly(): void
    {
        $this->assertEquals(85000 * 12, OutletAddOnService::priceFor(1, 'yearly'));
    }

    // === Limit Calculation ===

    public function test_base_limit_is_3_for_growup(): void
    {
        $org = Organization::create(['name' => 'Grup', 'slug' => 'grup-'.Str::random(5)]);
        $store = $this->createGrowUpStore($org);

        $max = StorePlanLimits::maxOutletsAllowedForOrganization($org->id);
        $this->assertEquals(3, $max);
    }

    public function test_extra_outlets_increase_limit(): void
    {
        $org = Organization::create(['name' => 'Grup', 'slug' => 'grup-'.Str::random(5)]);
        $store = $this->createGrowUpStore($org);
        $store->update(['extra_outlets' => 5]);

        $max = StorePlanLimits::maxOutletsAllowedForOrganization($org->id);
        $this->assertEquals(8, $max); // 3 base + 5 extra
    }

    public function test_unlimited_outlets_with_enough_addons(): void
    {
        $org = Organization::create(['name' => 'Grup', 'slug' => 'grup-'.Str::random(5)]);
        $store = $this->createGrowUpStore($org);
        $store->update(['extra_outlets' => 1000]);

        $max = StorePlanLimits::maxOutletsAllowedForOrganization($org->id);
        $this->assertEquals(1003, $max);
    }

    // === Enforcement (assertCanAddStoreToOrganization) ===

    public function test_central_warehouse_does_not_consume_outlet_quota(): void
    {
        $org = Organization::create(['name' => 'Grup', 'slug' => 'grup-'.Str::random(5)]);
        $plan = $this->createGrowUpPlan();

        // 3 outlet penuh + 1 gudang pusat
        for ($i = 0; $i < 3; $i++) {
            Store::create([
                'name' => "Outlet {$i}",
                'slug' => 'outlet-'.Str::random(6),
                'is_active' => true,
                'organization_id' => $org->id,
                'subscription_plan_id' => $plan->id,
                'location_type' => \App\Support\StoreLocationType::OUTLET,
                'extra_outlets' => 0,
            ]);
        }
        Store::create([
            'name' => 'Gudang Pusat',
            'slug' => 'gudang-'.Str::random(6),
            'is_active' => true,
            'organization_id' => $org->id,
            'subscription_plan_id' => $plan->id,
            'location_type' => \App\Support\StoreLocationType::CENTRAL_WAREHOUSE,
            'extra_outlets' => 0,
        ]);

        // Gudang tidak dihitung: outlet terpakai = 3 (bukan 4).
        $this->assertEquals(3, Store::where('organization_id', $org->id)->outlet()->count());

        // Outlet ke-4 harus diblokir.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        StorePlanLimits::assertCanAddStoreToOrganization($org->id);
    }

    public function test_new_empty_group_enforces_limit_via_fallback_plan(): void
    {
        $org = Organization::create(['name' => 'Grup Baru', 'slug' => 'grup-'.Str::random(5)]);
        $plan = $this->createGrowUpPlan();

        // Grup kosong (belum ada outlet): tanpa fallback → lolos (unlimited).
        StorePlanLimits::assertCanAddStoreToOrganization($org->id);
        $this->assertTrue(true);

        // Isi hingga batas 3 lalu uji outlet ke-4 dengan fallback plan.
        for ($i = 0; $i < 3; $i++) {
            Store::create([
                'name' => "Outlet {$i}",
                'slug' => 'outlet-'.Str::random(6),
                'is_active' => true,
                'organization_id' => $org->id,
                'subscription_plan_id' => $plan->id,
                'location_type' => \App\Support\StoreLocationType::OUTLET,
                'extra_outlets' => 0,
            ]);
        }

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        StorePlanLimits::assertCanAddStoreToOrganization($org->id, $plan);
    }

    // === Create Payment ===

    public function test_create_outlet_addon_payment(): void
    {
        $store = $this->createGrowUpStore();
        $admin = $store->members()->first();

        $payment = OutletAddOnService::createPayment($store, 3, 'bank_transfer', null, $admin);

        $this->assertEquals('outlet_addon', $payment->type);
        $this->assertEquals(3, $payment->extra_outlets_count);
        $this->assertEquals(255000, $payment->amount);
        $this->assertEquals('pending', $payment->status);
    }

    public function test_cannot_buy_addon_for_starter(): void
    {
        $store = $this->createStarterStore();

        $this->expectException(\Exception::class);
        OutletAddOnService::createPayment($store, 1, 'bank_transfer', null);
    }

    public function test_cannot_buy_zero_outlets(): void
    {
        $store = $this->createGrowUpStore();

        $this->expectException(\Exception::class);
        OutletAddOnService::createPayment($store, 0, 'bank_transfer', null);
    }

    public function test_double_submit_prevented(): void
    {
        $store = $this->createGrowUpStore();
        $admin = $store->members()->first();

        OutletAddOnService::createPayment($store, 1, 'bank_transfer', null, $admin);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('menunggu konfirmasi');
        OutletAddOnService::createPayment($store, 1, 'bank_transfer', null);
    }

    // === Confirm Add-On ===

    public function test_confirm_addon_increments_outlets(): void
    {
        $store = $this->createGrowUpStore();
        $admin = $store->members()->first();
        $payment = OutletAddOnService::createPayment($store, 4, 'bank_transfer', null, $admin);

        OutletAddOnService::applyConfirmedAddOn($payment);

        $store->refresh();
        $this->assertEquals(4, $store->extra_outlets);
        // subscription_days increased
        $this->assertGreaterThan(30, $store->subscription_days);
    }

    public function test_confirm_multiple_addons_accumulates(): void
    {
        $store = $this->createGrowUpStore();

        $admin = $store->members()->first();
        $p1 = OutletAddOnService::createPayment($store, 2, 'bank_transfer', null, $admin);
        OutletAddOnService::applyConfirmedAddOn($p1);
        $store->update(['is_active' => true]); // reset for next payment

        // Remove pending by simulating confirm
        $p1->update(['status' => 'confirmed']);

        $p2 = OutletAddOnService::createPayment($store, 3, 'bank_transfer', null, $admin);
        OutletAddOnService::applyConfirmedAddOn($p2);

        $store->refresh();
        $this->assertEquals(5, $store->extra_outlets); // 2 + 3
    }

    // === Edge Case: inactive store ===

    public function test_cannot_buy_addon_for_inactive_store(): void
    {
        $store = $this->createGrowUpStore();
        $store->update(['is_active' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Toko tidak aktif');
        OutletAddOnService::createPayment($store, 1, 'bank_transfer', null);
    }
}
