<?php

namespace App\Mail;

use App\Models\Store;
use App\Services\MailBranding;
use Illuminate\Mail\Mailable;

abstract class BrandedMail extends Mailable
{
    public array $branding = [];

    public function forStore(Store $store): static
    {
        $this->branding = app(MailBranding::class)->store($store);

        return $this;
    }

    protected function brand(): array
    {
        return $this->branding ?: app(MailBranding::class)->platform();
    }
}
