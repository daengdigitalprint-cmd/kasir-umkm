<?php

it('skips broadcast channel registration when reverb secret is missing', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.secret' => null,
    ]);

    expect(fn () => require base_path('routes/channels.php'))
        ->not->toThrow(Throwable::class);
});

it('skips broadcast channel registration when pusher secret is missing', function () {
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.secret' => null,
    ]);

    expect(fn () => require base_path('routes/channels.php'))
        ->not->toThrow(Throwable::class);
});

it('registers broadcast channels when reverb credentials are configured', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    expect(fn () => require base_path('routes/channels.php'))
        ->not->toThrow(Throwable::class);
});
