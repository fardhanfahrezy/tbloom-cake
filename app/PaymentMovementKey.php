<?php

namespace App;

use InvalidArgumentException;

final class PaymentMovementKey
{
    /** @param list<string> $identity */
    public static function fromIdentity(string $namespace, array $identity): string
    {
        if ($namespace === '' || $identity === [] || ! array_is_list($identity)) {
            throw new InvalidArgumentException('Canonical identity requires a namespace and an ordered identity tuple.');
        }

        $encoded = '';
        foreach ([$namespace, ...$identity] as $component) {
            if (! is_string($component) || $component === '' || strlen($component) > 4294967295) {
                throw new InvalidArgumentException('Canonical identity components must be nonempty byte strings.');
            }

            $encoded .= pack('N', strlen($component)).$component;
        }

        return 'movement:v1:'.hash('sha256', $encoded);
    }
}
