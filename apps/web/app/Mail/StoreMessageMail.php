<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class StoreMessageMail extends Mailable
{
    public function __construct(public string $storeName, public string $messageText) {}

    public function build(): static
    {
        return $this->subject($this->storeName.' · Informasi pesanan')->html('<div style="white-space:pre-wrap">'.nl2br(e($this->messageText)).'</div>');
    }
}
