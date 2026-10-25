<?php

namespace App;

/** Application scope, independent of merchant activation. This does not authorize financial effects. */
final class MidtransPaymentChannels
{
    public const BCA_VA = 'bca_va';

    /** @return list<string> */
    public function enabledForCheckout(): array
    {
        return [self::BCA_VA];
    }

    public function supportsStatusObservation(?string $channel): bool
    {
        return $channel === self::BCA_VA;
    }
}
