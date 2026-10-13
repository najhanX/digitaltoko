<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class OwnerOtpMail extends Mailable
{
    public function __construct(public readonly string $code) {}

    public function build(): static
    {
        return $this->subject('Kode OTP DigitalToko')->html('<p>Kode OTP DigitalToko: <strong>'.$this->code.'</strong></p><p>Berlaku 5 menit. Jangan bagikan kode ini.</p>');
    }
}
