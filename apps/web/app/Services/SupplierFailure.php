<?php

namespace App\Services;

use RuntimeException;

class SupplierFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $rateLimited = false)
    {
        parent::__construct($message);
    }
}
