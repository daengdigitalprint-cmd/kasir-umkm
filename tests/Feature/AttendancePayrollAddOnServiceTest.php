<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AttendancePayrollAddOnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendancePayrollAddOnServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createStore(): Store
    {
        $plan = SubscriptionPlan::create([
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

        $owner = User::factory()->create();

        $store = Store::create([
            'name' => 'Toko Uji',
            'slug' => 'toko-uji-'.Str::random(5),
            'is_active' => true,
            'subscription_plan_id' => $plan->id,
            'is_subscription_active' => true,
        ]);

        $store->members()->attach($owner->id, ['role' => 'admin']);

        return $store;
    }

    public function test_price_uses_pricing_model_defaults(): void
    {
        $this->assertEquals(49000, AttendancePayrollAddOnService::priceFor('monthly'));
        $this->assertEquals(490000, AttendancePayrollAddOnService::priceFor('yearly'));
    }

    public function test_create_payment_allowed_for_starter(): void
    {
        $store = $this->createStore();
        $admin = $store->members()->first();

        $payment = AttendancePayrollAddOnService::createPayment($store, 'bank_transfer', null, $admin);

        $this->assertEquals('attendance_payroll_addon', $payment->type);
        $this->assertEquals(49000, $payment->amount);
        $this->assertEquals(30, $payment->duration_days);
        $this->assertEquals('pending', $payment->status);
    }

    public function test_double_submit_prevented(): void
    {
        $store = $this->createStore();
        $admin = $store->members()->first();

        AttendancePayrollAddOnService::createPayment($store, 'bank_transfer', null, $admin);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('menunggu konfirmasi');
        AttendancePayrollAddOnService::createPayment($store, 'bank_transfer', null, $admin);
    }

    public function test_confirm_sets_attendance_payroll_until(): void
    {
        $store = $this->createStore();
        $admin = $store->members()->first();
        $payment = AttendancePayrollAddOnService::createPayment($store, 'bank_transfer', null, $admin);

        $this->assertNull($store->attendance_payroll_until);

        AttendancePayrollAddOnService::applyConfirmedAddOn($payment);

        $store->refresh();
        $this->assertNotNull($store->attendance_payroll_until);
        $this->assertTrue($store->attendance_payroll_until->gt(now()->addDays(29)));
    }

    public function test_inactive_store_rejected(): void
    {
        $store = $this->createStore();
        $store->update(['is_active' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Toko tidak aktif');
        AttendancePayrollAddOnService::createPayment($store, 'bank_transfer', null);
    }

    public function test_confirm_activates_all_outlets_in_same_group(): void
    {
        $plan = SubscriptionPlan::create([
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

        $owner = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Grup Uji',
            'slug' => 'grup-uji-'.Str::random(5),
            'owner_user_id' => $owner->id,
        ]);

        $storeA = Store::create([
            'name' => 'Outlet A',
            'slug' => 'outlet-a-'.Str::random(5),
            'is_active' => true,
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'is_subscription_active' => true,
        ]);
        $storeB = Store::create([
            'name' => 'Outlet B',
            'slug' => 'outlet-b-'.Str::random(5),
            'is_active' => true,
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'is_subscription_active' => true,
        ]);

        $storeA->members()->attach($owner->id, ['role' => 'admin']);
        $storeB->members()->attach($owner->id, ['role' => 'admin']);

        $this->assertFalse(AttendancePayrollAddOnService::isPurchased($storeA));
        $this->assertFalse(AttendancePayrollAddOnService::isPurchased($storeB));

        $payment = AttendancePayrollAddOnService::createPayment($storeA, 'bank_transfer', null, $owner);
        AttendancePayrollAddOnService::applyConfirmedAddOn($payment);

        $storeA->refresh();
        $storeB->refresh();

        $this->assertTrue(AttendancePayrollAddOnService::isPurchased($storeA));
        $this->assertTrue(AttendancePayrollAddOnService::isPurchased($storeB));
        $this->assertNull($storeA->attendance_payroll_until);
        $this->assertNull($storeB->attendance_payroll_until);
        $this->assertNotNull($organization->fresh()->attendance_payroll_until);
    }
}
