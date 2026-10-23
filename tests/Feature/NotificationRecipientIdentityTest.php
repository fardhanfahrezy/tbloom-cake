<?php

use App\NotificationChannel;
use App\NotificationRecipientIdentity;

beforeEach(function () {
    config([
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'notifications.previous_recipient_hmac_keys' => [],
    ]);
});

test('uses a stable versioned HMAC identity without exposing the canonical recipient', function () {
    $identity = app(NotificationRecipientIdentity::class);
    $key = $identity->key(NotificationChannel::WhatsApp, '+628111111111');
    expect($key)->toBe('4e8c006d72dea15122840c1b72be756449c9fc2aecb5936b013ae35f5b73a59d');
    expect($key)->toMatch('/\A[a-f0-9]{64}\z/')->not->toContain('628111111111');
    expect($identity->key(NotificationChannel::WhatsApp, '+628111111111'))->toBe($key);
    expect($identity->key(NotificationChannel::Email, '+628111111111'))->not->toBe($key);
});

test('preserves complete canonical recipient bytes when deriving identity', function () {
    $identity = app(NotificationRecipientIdentity::class);
    expect($identity->key(NotificationChannel::Email, 'Customer@example.test'))->not->toBe($identity->key(NotificationChannel::Email, 'customer@example.test'));
    expect($identity->key(NotificationChannel::Email, str_repeat('x', 300).'a'))->not->toBe($identity->key(NotificationChannel::Email, str_repeat('x', 300).'b'));
});

test('retains recipient identity when the application encryption key rotates', function () {
    $identity = app(NotificationRecipientIdentity::class);
    $before = $identity->key(NotificationChannel::WhatsApp, '+628111111111');
    config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32)), 'app.previous_keys' => []]);
    expect($identity->key(NotificationChannel::WhatsApp, '+628111111111'))->toBe($before);
});

test('includes historical digests during an explicitly configured HMAC key rotation', function () {
    $identity = app(NotificationRecipientIdentity::class);
    $old = $identity->key(NotificationChannel::Email, 'customer@example.test');
    $previous = config('notifications.recipient_hmac_key');
    config([
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('b', 32)),
        'notifications.previous_recipient_hmac_keys' => [$previous, $previous],
    ]);
    $active = $identity->key(NotificationChannel::Email, 'customer@example.test');
    expect($active)->not->toBe($old);
    expect($identity->candidateKeys(NotificationChannel::Email, 'customer@example.test'))->toBe([$active, $old]);
});

test('rejects missing or invalid dedicated HMAC keys without a fallback to APP_KEY', function (mixed $key) {
    config(['notifications.recipient_hmac_key' => $key, 'app.key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    expect(fn () => app(NotificationRecipientIdentity::class)->key(NotificationChannel::Email, 'customer@example.test'))->toThrow(LogicException::class);
})->with([[null], [''], ['plain-text-key-is-not-accepted'], ['base64:AAAA'], ['base64:!!!']]);

test('rejects malformed previous keyrings rather than losing historical deduplication', function (mixed $keys) {
    config(['notifications.previous_recipient_hmac_keys' => $keys]);
    expect(fn () => app(NotificationRecipientIdentity::class)->key(NotificationChannel::Email, 'customer@example.test'))->toThrow(LogicException::class);
})->with([
    'non-array' => ['not-an-array'],
    'invalid member' => [['invalid-previous-key']],
    'associative' => [['alias' => 'base64:'.base64_encode(str_repeat('a', 32))]],
]);

test('requires callers to provide nonempty canonical recipients without trimming them silently', function (string $recipient) {
    expect(fn () => app(NotificationRecipientIdentity::class)->key(NotificationChannel::Email, $recipient))->toThrow(InvalidArgumentException::class);
})->with(['', ' ', ' customer@example.test', 'customer@example.test ']);
