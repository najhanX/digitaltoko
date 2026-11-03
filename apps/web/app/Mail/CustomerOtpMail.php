<?php

namespace App\Mail;

class CustomerOtpMail extends BrandedMail
{
    public function __construct(public string $storeName, public string $code) {}

    public function build(): static
    {
        return $this->subject('Kode OTP '.$this->storeName)->view('mail.otp', ['brand' => $this->brand(), 'title' => 'Verifikasi akun Anda', 'code' => $this->code])->text('mail.otp-text', ['storeName' => $this->storeName, 'code' => $this->code]);
    }
}
