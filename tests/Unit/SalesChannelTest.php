<?php

use App\Enums\SalesChannel;

it('memiliki label yang benar untuk setiap channel', function (SalesChannel $channel, string $expectedLabel) {
    expect($channel->label())->toBe($expectedLabel);
})->with([
    'walk_in' => [SalesChannel::WalkIn, 'Beli Langsung'],
    'dine_in' => [SalesChannel::DineIn, 'Makan di Tempat'],
    'takeaway' => [SalesChannel::Takeaway, 'Bawa Pulang'],
    'delivery' => [SalesChannel::Delivery, 'Antar Sendiri'],
    'gofood' => [SalesChannel::GoFood, 'GoFood'],
    'grabfood' => [SalesChannel::GrabFood, 'GrabFood'],
    'shopeefood' => [SalesChannel::ShopeeFood, 'ShopeeFood'],
]);

it('mengklasifikasikan offline vs online dengan benar', function (SalesChannel $channel, bool $isOffline, bool $isOnline) {
    expect($channel->isOffline())->toBe($isOffline)
        ->and($channel->isOnline())->toBe($isOnline)
        ->and($channel->group())->toBe($isOffline ? 'offline' : 'online');
})->with([
    'walk_in offline' => [SalesChannel::WalkIn, true, false],
    'dine_in offline' => [SalesChannel::DineIn, true, false],
    'takeaway offline' => [SalesChannel::Takeaway, true, false],
    'delivery offline' => [SalesChannel::Delivery, true, false],
    'gofood online' => [SalesChannel::GoFood, false, true],
    'grabfood online' => [SalesChannel::GrabFood, false, true],
    'shopeefood online' => [SalesChannel::ShopeeFood, false, true],
]);

it('mengidentifikasi aggregator dengan benar', function (SalesChannel $channel, bool $isAggregator) {
    expect($channel->isAggregator())->toBe($isAggregator);
})->with([
    'walk_in bukan aggregator' => [SalesChannel::WalkIn, false],
    'dine_in bukan aggregator' => [SalesChannel::DineIn, false],
    'delivery bukan aggregator' => [SalesChannel::Delivery, false],
    'gofood aggregator' => [SalesChannel::GoFood, true],
    'grabfood aggregator' => [SalesChannel::GrabFood, true],
    'shopeefood aggregator' => [SalesChannel::ShopeeFood, true],
]);

it('mengembalikan komisi default untuk aggregator dan 0 untuk non-aggregator', function (SalesChannel $channel, float $expectedPercent) {
    expect($channel->defaultCommissionPercent())->toBe($expectedPercent);
})->with([
    'walk_in' => [SalesChannel::WalkIn, 0.0],
    'dine_in' => [SalesChannel::DineIn, 0.0],
    'delivery' => [SalesChannel::Delivery, 0.0],
    'gofood 20%' => [SalesChannel::GoFood, 20.0],
    'grabfood 20%' => [SalesChannel::GrabFood, 20.0],
    'shopeefood 15%' => [SalesChannel::ShopeeFood, 15.0],
]);

it('forFnb mengembalikan semua channel F&B', function () {
    $channels = SalesChannel::forFnb();

    expect($channels)->toHaveCount(7)
        ->and($channels)->toContain(SalesChannel::WalkIn)
        ->and($channels)->toContain(SalesChannel::GoFood)
        ->and($channels)->toContain(SalesChannel::GrabFood)
        ->and($channels)->toContain(SalesChannel::ShopeeFood);
});

it('aggregators hanya mengembalikan GoFood, GrabFood, ShopeeFood', function () {
    $aggregators = SalesChannel::aggregators();

    expect($aggregators)->toHaveCount(3)
        ->and($aggregators)->toEqual([SalesChannel::GoFood, SalesChannel::GrabFood, SalesChannel::ShopeeFood]);
});
