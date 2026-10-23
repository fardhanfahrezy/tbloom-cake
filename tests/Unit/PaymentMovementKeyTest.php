<?php

use App\PaymentMovementKey;

test('encodes a versioned canonical identity without truncating components', function () {
    $key = PaymentMovementKey::fromIdentity('test-bank', ['account', 'reference']);
    expect($key)->toMatch('/^movement:v1:[a-f0-9]{64}$/');
    expect(PaymentMovementKey::fromIdentity('test-bank', ['account', 'reference']))->toBe($key);
    expect(PaymentMovementKey::fromIdentity('test-bank', ['account', 'Reference']))->not->toBe($key);
    expect(PaymentMovementKey::fromIdentity('test-bank', ['account', 'reference ']))->not->toBe($key);
    expect(PaymentMovementKey::fromIdentity('test-other', ['account', 'reference']))->not->toBe($key);
    expect(PaymentMovementKey::fromIdentity('test-bank', ['a:b', 'c']))->not->toBe(PaymentMovementKey::fromIdentity('test-bank', ['a', 'b:c']));
    expect(PaymentMovementKey::fromIdentity('test-bank', [str_repeat('x', 300).'A']))->not->toBe(PaymentMovementKey::fromIdentity('test-bank', [str_repeat('x', 300).'B']));
});

test('rejects incomplete canonical identities', function (string $namespace, array $identity) {
    expect(fn () => PaymentMovementKey::fromIdentity($namespace, $identity))->toThrow(InvalidArgumentException::class);
})->with([['', ['id']], ['bank', []], ['bank', ['']], ['bank', ['valid', '']]]);
