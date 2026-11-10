<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminExtendSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function createStore(): Store
    {
        $plan = SubscriptionPlan::create([
            'name' => 'GrowUp',
            'slug' => 'bisnis',
            'price' => 99000,
            'duration_days' => 30,
            'max_products' => null,
            'is_active' => true,
            'sort_order' => 2,
            'billing_cycle' => 'monthly',
        ]);

        return Store::create([
            'name' => 'Toko Uji',
            'slug' => 'toko-uji',
            'is_active' => true,
            'subscription_plan_id' => $plan->id,
            'subscription_start' => now()->subDays(40),
            'subscription_end' => now()->subDays(10),
            'subscription_days' => 30,
            'is_subscription_active' => true,
        ]);
    }

    public function test_extend_subscription_updates_store_dates(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        $response = $this->actingAs($admin)->postJson("/superadmin/stores/{$store->slug}/extend", [
            'days' => 30,
            'notes' => 'perpanjangan manual',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Masa aktif toko Toko Uji berhasil diperpanjang 30 hari.',
            ])
            ->assertJsonPath('store.subscription_days', 60)
            ->assertJsonPath('store.is_subscription_active', true);

        $store->refresh();

        $this->assertTrue($store->subscription_end->isSameDay(now()->addDays(30)));
        $this->assertSame(60, $store->subscription_days);
        $this->assertTrue($store->is_subscription_active);
        $this->assertSame('perpanjangan manual', $store->subscription_notes);
    }

    public function test_extend_subscription_is_reflected_in_user_account_subscription_page(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $owner = User::factory()->create([
            'role' => 'admin',
            'is_super_admin' => false,
            'store_id' => $store->id,
        ]);

        $this->actingAs($admin)->postJson("/superadmin/stores/{$store->slug}/extend", ['days' => 30])
            ->assertOk()
            ->assertJson(['success' => true]);

        $response = $this->actingAs($owner)->get('/subscription');
        $response->assertSuccessful();
        $response->assertInertia(
            fn ($page) => $page->where('store.subscription_end', $store->refresh()->subscription_end->toISOString())
        );
    }

    public function test_extend_subscription_rejects_invalid_days(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        $response = $this->actingAs($admin)->postJson("/superadmin/stores/{$store->slug}/extend", [
            'days' => 400,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('days');
        $this->assertTrue($store->refresh()->subscription_end->isSameDay(now()->subDays(10)));
    }

    public function test_extend_subscription_requires_super_admin(): void
    {
        $store = $this->createStore();
        $owner = User::factory()->create([
            'role' => 'admin',
            'is_super_admin' => false,
            'store_id' => $store->id,
        ]);

        $this->actingAs($owner)->postJson("/superadmin/stores/{$store->slug}/extend", ['days' => 30])
            ->assertForbidden();

        $this->assertTrue($store->refresh()->subscription_end->isSameDay(now()->subDays(10)));
    }

    public function test_reduce_subscription_updates_store_dates(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        // Set subscription_end di masa depan agar bisa dikurangi
        $store->update([
            'subscription_end' => now()->addDays(60),
            'subscription_days' => 90,
        ]);

        $response = $this->actingAs($admin)->postJson("/superadmin/stores/{$store->slug}/reduce", [
            'days' => 30,
            'notes' => 'pengurangan manual',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Masa aktif toko Toko Uji berhasil dikurangi 30 hari.',
            ])
            ->assertJsonPath('store.subscription_days', 60)
            ->assertJsonPath('store.is_subscription_active', true);

        $store->refresh();

        $this->assertTrue($store->subscription_end->isSameDay(now()->addDays(30)));
        $this->assertSame(60, $store->subscription_days);
        $this->assertTrue($store->is_subscription_active);
        $this->assertSame('pengurangan manual', $store->subscription_notes);
    }

    public function test_reduce_subscription_can_expire_store(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        // Set subscription_end 15 hari ke depan
        $store->update([
            'subscription_end' => now()->addDays(15),
            'subscription_days' => 45,
            'is_subscription_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson("/superadmin/stores/{$store->slug}/reduce", [
            'days' => 30,
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('store.is_subscription_active', false);

        $store->refresh();

        $this->assertFalse($store->is_subscription_active);
        $this->assertTrue($store->subscription_end->isSameDay(now()->subDays(15)));
        $this->assertSame(15, $store->subscription_days);
    }

    public function test_reduce_subscription_rejects_invalid_days(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        $store->update([
            'subscription_end' => now()->addDays(60),
            'subscription_days' => 90,
        ]);

        $response = $this->actingAs($admin)->postJson("/superadmin/stores/{$store->slug}/reduce", [
            'days' => 400,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('days');

        $store->refresh();
        $this->assertTrue($store->subscription_end->isSameDay(now()->addDays(60)));
    }

    public function test_reduce_subscription_requires_super_admin(): void
    {
        $store = $this->createStore();
        $owner = User::factory()->create([
            'role' => 'admin',
            'is_super_admin' => false,
            'store_id' => $store->id,
        ]);

        $store->update([
            'subscription_end' => now()->addDays(60),
            'subscription_days' => 90,
        ]);

        $this->actingAs($owner)->postJson("/superadmin/stores/{$store->slug}/reduce", ['days' => 30])
            ->assertForbidden();

        $store->refresh();
        $this->assertTrue($store->subscription_end->isSameDay(now()->addDays(60)));
    }
}
