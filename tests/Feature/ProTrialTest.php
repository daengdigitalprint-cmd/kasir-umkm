<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\TrialActivationLog;
use App\Models\TrialNotification;
use App\Models\User;
use App\Services\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProTrialTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function createStoreAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_super_admin' => false]);
    }

    private function createStarterPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 0,
            'duration_days' => 0,
            'max_products' => 30,
            'is_active' => true,
            'sort_order' => 1,
            'billing_cycle' => 'monthly',
        ]);
    }

    private function createGrowUpPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'GrowUp',
            'slug' => 'bisnis',
            'price' => 99000,
            'duration_days' => 30,
            'max_products' => null,
            'is_active' => true,
            'is_popular' => true,
            'sort_order' => 2,
            'billing_cycle' => 'monthly',
        ]);
    }

    private function createFreeStore(): Store
    {
        $plan = $this->createStarterPlan();
        $this->createGrowUpPlan();

        return Store::create([
            'name' => 'Toko Gratis',
            'slug' => 'toko-gratis',
            'is_active' => true,
            'subscription_plan_id' => $plan->id,
            'subscription_start' => now()->subDays(10),
            'subscription_days' => 0,
            'is_subscription_active' => true,
        ]);
    }

    // === Service Layer: Aktivasi ===

    public function test_activate_trial_sets_pro_plan(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        $log = TrialService::activate($store, $admin);

        $store->refresh();
        $this->assertTrue($store->pro_trial_active);
        $this->assertTrue($store->pro_trial_used);
        $this->assertEquals('bisnis', $store->subscriptionPlan->slug);
        $this->assertEquals(7, $store->subscription_days);
        $this->assertNotNull($store->pro_trial_start);
        $this->assertNotNull($store->pro_trial_end);
        $this->assertEquals('activated', $log->action);
        $this->assertEquals($admin->id, $log->admin_id);
    }

    public function test_activate_trial_creates_audit_log(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);

        $log = TrialActivationLog::where('store_id', $store->id)->where('action', 'activated')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->admin_id);
        $this->assertEquals('starter', $log->old_plan_slug);
        $this->assertEquals('bisnis', $log->new_plan_slug);
    }

    public function test_cannot_activate_trial_twice(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('sudah pernah menggunakan trial');
        TrialService::activate($store, $admin);
    }

    public function test_cannot_activate_trial_on_inactive_store(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();
        $store->update(['is_active' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Toko tidak aktif');
        TrialService::activate($store, $admin);
    }

    // === Service Layer: Stop Manual ===

    public function test_stop_trial_reverts_to_starter(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);
        $log = TrialService::stop($store, $admin);

        $store->refresh();
        $this->assertFalse($store->pro_trial_active);
        $this->assertEquals('starter', $store->subscriptionPlan->slug);
        $this->assertEquals('stopped', $log->action);
    }

    public function test_cannot_stop_inactive_trial(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak memiliki trial Pro yang aktif');
        TrialService::stop($store, $admin);
    }

    // === Service Layer: Auto-Expire ===

    public function test_trial_expires_after_7_days(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);

        // Simulate 7+ days passing
        $store->update(['pro_trial_end' => now()->subHour()]);

        $count = TrialService::expireExpiredTrials();

        $this->assertEquals(1, $count);

        $store->refresh();
        $this->assertFalse($store->pro_trial_active);
        $this->assertEquals('starter', $store->subscriptionPlan->slug);

        $log = TrialActivationLog::where('store_id', $store->id)->where('action', 'expired')->first();
        $this->assertNotNull($log);
    }

    public function test_active_trial_not_expired(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);

        $count = TrialService::expireExpiredTrials();

        $this->assertEquals(0, $count);
        $this->assertTrue($store->fresh()->pro_trial_active);
    }

    // === Edge case: store sudah tidak aktif tapi trial masih jalan ===

    public function test_stop_trial_when_trial_active_but_store_deactivated(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);

        // Deactivate store while trial active
        $store->update(['is_active' => false]);

        // Stop should still work (trial active regardless of store status)
        $log = TrialService::stop($store, $admin);
        $this->assertNotNull($log);
        $this->assertFalse($store->fresh()->pro_trial_active);
    }

    // === Database Notifications ===

    public function test_activation_creates_notification(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);

        $notif = TrialNotification::where('store_id', $store->id)->where('type', 'trial_activated')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Trial Pro Diaktifkan', $notif->title);
        $this->assertStringContainsString('GrowUp', $notif->body);
    }

    public function test_stop_creates_notification(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);
        TrialService::stop($store, $admin);

        $notif = TrialNotification::where('store_id', $store->id)->where('type', 'trial_stopped')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Dihentikan', $notif->title);
    }

    public function test_expire_creates_notification(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);
        $store->update(['pro_trial_end' => now()->subHour()]);
        TrialService::expireExpiredTrials();

        $notif = TrialNotification::where('store_id', $store->id)->where('type', 'trial_expired')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Berakhir', $notif->title);
    }

    public function test_reminder_creates_notification(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);
        // Set trial end to 12 hours from now
        $store->update(['pro_trial_end' => now()->addHours(12)]);

        $count = TrialService::sendExpiryReminders();
        $this->assertEquals(1, $count);

        $notif = TrialNotification::where('store_id', $store->id)->where('type', 'trial_expiring_soon')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('akan berakhir', $notif->body);
    }

    public function test_reminder_no_duplicate(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createFreeStore();

        TrialService::activate($store, $admin);
        $store->update(['pro_trial_end' => now()->addHours(12)]);

        TrialService::sendExpiryReminders();
        TrialService::sendExpiryReminders(); // second call

        $count = TrialNotification::where('store_id', $store->id)
            ->where('type', 'trial_expiring_soon')
            ->count();
        $this->assertEquals(1, $count);
    }
}
