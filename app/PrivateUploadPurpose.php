<?php

namespace App;

enum PrivateUploadPurpose: string
{
    case CustomReference = 'custom-requests';
    case RefundEvidence = 'refund-evidence';

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::CustomReference => ['image/jpeg', 'image/png', 'image/webp'],
            self::RefundEvidence => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        };
    }
}
