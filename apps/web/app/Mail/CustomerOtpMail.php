<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CustomerOtpMail extends Mailable
{
    public function __construct(public string $storeName, public string $code) {}

    public function build(): static
    {
        return $this->subject('Kode OTP '.$this->storeName)->html('<p>Kode OTP '.e($this->storeName).': <strong>'.e($this->code).'</strong></p><p>Berlaku 5 menit. Jangan bagikan kode ini.</p>');
    }
}
