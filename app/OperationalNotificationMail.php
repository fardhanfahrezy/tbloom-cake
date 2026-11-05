<?php

namespace App;

use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;

class OperationalNotificationMail extends Mailable
{
    public function __construct(public readonly string $bodyText) {}

    public function build(): self
    {
        return $this->subject('TBloom Cake — Notifikasi transaksi')->text(new HtmlString($this->bodyText));
    }
}
