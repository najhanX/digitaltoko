<?php

namespace App\Mail;

class StoreMessageMail extends BrandedMail
{
    public function __construct(public string $storeName, public string $messageText) {}

    public function build(): static
    {
        return $this->subject($this->storeName.' · Informasi pesanan')->view('mail.message', ['brand' => $this->brand(), 'title' => 'Informasi pesanan', 'messageText' => $this->messageText])->text('mail.message-text', ['storeName' => $this->storeName, 'messageText' => $this->messageText]);
    }
}
