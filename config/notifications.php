<?php

return [
    'recipient_hmac_key' => env('NOTIFICATION_RECIPIENT_HMAC_KEY'),

    'previous_recipient_hmac_keys' => array_values(array_filter(
        explode(',', (string) env('NOTIFICATION_PREVIOUS_RECIPIENT_HMAC_KEYS', '')),
        fn (string $key): bool => $key !== '',
    )),
];
