<?php

use App\Services\AI\Providers\OpenAiCompatibleProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** @return array<string, mixed> */
function providerConfig(array $overrides = []): array
{
    return array_merge([
        'base_url' => 'https://contoh.test/v1',
        'model_id' => 'claude-sonnet-5-5',
        'api_key' => 'sk-test',
        'max_tokens' => 1000,
        'temperature' => 0.7,
        'supports_function_calling' => false,
    ], $overrides);
}

function okBody(string $text = 'OK'): array
{
    return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
}

function temperatureRejection(): array
{
    return ['error' => [
        'code' => 'invalid_request_error',
        'message' => '`temperature` is deprecated for this model.',
        'type' => 'invalid_request_error',
    ]];
}

it('mengulang tanpa temperature saat model menolaknya', function () {
    $sequence = 0;

    Http::fake(function (Request $request) use (&$sequence) {
        $sequence++;

        return $sequence === 1
            ? Http::response(temperatureRejection(), 400)
            : Http::response(okBody(), 200);
    });

    $response = (new OpenAiCompatibleProvider(providerConfig()))
        ->chat([['role' => 'user', 'content' => 'halo']]);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->getContent())->toBe('OK');

    $sent = [];
    Http::assertSentCount(2);
    Http::assertSent(function (Request $r) use (&$sent) {
        $sent[] = $r->data();

        return true;
    });

    // Percobaan pertama mengirim temperature, percobaan ulang tidak.
    expect($sent[0])->toHaveKey('temperature')
        ->and($sent[1])->not->toHaveKey('temperature')
        // Sisa payload tetap utuh saat diulang.
        ->and($sent[1]['model'])->toBe('claude-sonnet-5-5')
        ->and($sent[1]['max_tokens'])->toBe(1000);
});

it('tidak mengulang untuk error 400 lain', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'model tidak dikenal']], 400),
    ]);

    $response = (new OpenAiCompatibleProvider(providerConfig()))
        ->chat([['role' => 'user', 'content' => 'halo']]);

    Http::assertSentCount(1);
    expect($response->isSuccess())->toBeFalse();
});

it('menyertakan keterangan asli provider pada pesan error', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'This model is currently unavailable.']], 503),
    ]);

    $response = (new OpenAiCompatibleProvider(providerConfig()))
        ->chat([['role' => 'user', 'content' => 'halo']]);

    expect($response->error)
        ->toContain('Provider AI sedang gangguan')
        ->toContain('This model is currently unavailable.');
});

it('tetap mengirim temperature untuk model yang menerimanya', function () {
    Http::fake(['*' => Http::response(okBody(), 200)]);

    (new OpenAiCompatibleProvider(providerConfig(['model_id' => 'gpt-4o'])))
        ->chat([['role' => 'user', 'content' => 'halo']]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->data()['temperature'] === 0.7);
});
