<?php

namespace App\Domain;

use DomainException;

class DomainError extends DomainException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
