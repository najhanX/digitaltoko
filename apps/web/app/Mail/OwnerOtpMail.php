<?php

namespace App\Mail;

class OwnerOtpMail extends BrandedMail
{
    public function __construct(public readonly string $code) {}

    public function build(): static
    {
        return $this->subject('Kode OTP '.'DigitalToko')->view('mail.otp', ['brand' => $this->brand(), 'title' => 'Verifikasi akun Anda', 'code' => $this->code])->text('mail.otp-text', ['storeName' => 'DigitalToko', 'code' => $this->code]);
    }
}
