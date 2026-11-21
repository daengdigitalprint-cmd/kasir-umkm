<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\TrialNotification;
use App\Models\User;
use App\Services\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trial Pro otomatis saat daftar (reverse trial) dan rangkaian pengingatnya.
 */
class TrialOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionPlan::create(['name' => 'Starter', 'slug' => 'starter', 'price' => 0, 'duration_days' => 0, 'max_products' => 30, 'max_customers' => 50, 'is_active' => true, 'sort_order' => 1, 'billing_cycle' => 'monthly']);
        SubscriptionPlan::create(['name' => 'GrowUp', 'slug' => 'bisnis', 'price' => 99000, 'duration_days' => 30, 'is_active' => true, 'sort_order' => 2, 'billing_cycle' => 'monthly']);
    }

    private function newStore(string $name = 'Toko Baru'): Store
    {
        return Store::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'is_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);
    }

    private function ownerWith(Store $store): User
    {
        $user = User::factory()->create(['role' => 'admin', 'store_id' => $store->id]);
        $user->stores()->attach($store->id, ['role' => 'admin']);

        return $user;
    }

    private function promoPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create(['name' => 'Promo tahunan', 'slug' => 'promo-onboarding-tahunan', 'price' => 792000, 'duration_days' => 365, 'is_active' => true, 'sort_order' => 9, 'billing_cycle' => 'yearly']);
    }

    public function test_first_store_gets_fourteen_day_trial_with_welcome_message(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $store = $this->newStore();

        $this->assertTrue(TrialService::startForNewStore($store, $owner));

        $store->refresh();
        $this->assertTrue($store->pro_trial_active);
        $this->assertSame('bisnis', $store->subscriptionPlan->slug);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $store->pro_trial_end->timestamp, 5);
        $this->assertDatabaseHas('trial_notifications', ['store_id' => $store->id, 'type' => 'trial_activated']);
        $this->assertDatabaseHas('trial_activation_logs', ['store_id' => $store->id, 'action' => 'activated', 'admin_id' => null]);
    }

    public function test_owner_who_already_had_trial_gets_free_plan_on_next_store(): void
    {
        $first = $this->newStore('Toko Pertama');
        $owner = $this->ownerWith($first);
        TrialService::startForNewStore($first, $owner);

        $second = $this->newStore('Toko Kedua');
        $this->assertFalse(TrialService::startForNewStore($second, $owner));

        $second->refresh();
        $this->assertFalse((bool) $second->pro_trial_active);
        $this->assertSame('starter', $second->subscriptionPlan->slug);
        $this->assertNull($second->subscription_end);
    }

    public function test_recap_three_days_before_end_mentions_usage(): void
    {
        $store = $this->newStore();
        TrialService::startForNewStore($store, User::factory()->create());
        foreach (range(1, 35) as $i) {
            Product::create(['store_id' => $store->id, 'name' => 'Produk '.$i, 'sku' => 'P-'.$i, 'price' => 1000]);
        }
        $store->update(['pro_trial_end' => now()->addDays(3)->subHour()]);

        $this->assertSame(1, TrialService::sendReminders());

        $recap = TrialNotification::where('store_id', $store->id)->where('type', 'trial_recap')->firstOrFail();
        $this->assertStringContainsString('35 produk', $recap->body);
        $this->assertStringContainsString('tetap bisa dijual', $recap->body);

        // Tiap jenis hanya sekali.
        $this->assertSame(0, TrialService::sendReminders());
    }

    public function test_last_day_reminder_promises_data_safety(): void
    {
        $store = $this->newStore();
        TrialService::startForNewStore($store, User::factory()->create());
        $store->update(['pro_trial_end' => now()->addHours(10)]);

        TrialService::sendReminders();

        $notice = TrialNotification::where('store_id', $store->id)->where('type', 'trial_expiring_soon')->firstOrFail();
        $this->assertStringContainsString('Data Anda aman', $notice->body);
        $this->assertDatabaseMissing('trial_notifications', ['store_id' => $store->id, 'type' => 'trial_recap']);
    }

    public function test_expiry_downgrades_and_retires_unread_trial_messages(): void
    {
        $store = $this->newStore();
        TrialService::startForNewStore($store, User::factory()->create());
        $store->update(['pro_trial_end' => now()->subMinute()]);

        TrialService::expireExpiredTrials();

        $store->refresh();
        $this->assertSame('starter', $store->subscriptionPlan->slug);
        $this->assertNotNull(TrialNotification::where('store_id', $store->id)->where('type', 'trial_activated')->value('read_at'));
        $payload = TrialService::payload($store);
        $this->assertFalse($payload['active']);
        $this->assertSame('trial_expired', $payload['notice']['type']);
    }

    public function test_followup_three_days_after_expiry_only_for_free_plan(): void
    {
        $store = $this->newStore();
        TrialService::startForNewStore($store, User::factory()->create());
        $store->update(['pro_trial_end' => now()->subMinute()]);
        TrialService::expireExpiredTrials();

        $this->assertSame(0, TrialService::sendReminders());

        $this->travel(TrialService::FOLLOWUP_AFTER_DAYS)->days();
        $this->assertSame(1, TrialService::sendReminders());
        $this->assertDatabaseHas('trial_notifications', ['store_id' => $store->id, 'type' => 'trial_followup']);
    }

    public function test_promo_offer_has_a_real_deadline(): void
    {
        $promo = $this->promoPlan();
        $store = $this->newStore();
        $owner = $this->ownerWith($store);
        TrialService::startForNewStore($store, $owner);

        $this->assertTrue(TrialService::promoEligible($store->refresh()));

        $store->update(['pro_trial_active' => false, 'pro_trial_end' => now()->subDays(TrialService::PROMO_GRACE_DAYS + 1)]);
        $this->assertFalse(TrialService::promoEligible($store->refresh()));

        $this->actingAs($owner)
            ->get(route('subscription.checkout', $promo))
            ->assertRedirect(route('subscription.index'));
    }

    public function test_owner_can_mark_own_trial_message_read_but_not_others(): void
    {
        $store = $this->newStore();
        $owner = $this->ownerWith($store);
        TrialService::startForNewStore($store, $owner);
        $notice = TrialNotification::where('store_id', $store->id)->firstOrFail();

        $this->actingAs($owner)->post(route('trial-notifications.read', $notice))->assertRedirect();
        $this->assertNotNull($notice->refresh()->read_at);

        $other = $this->newStore('Toko Lain');
        $otherOwner = $this->ownerWith($other);
        $this->actingAs($otherOwner)->post(route('trial-notifications.read', $notice))->assertForbidden();
    }
}
