<?php

namespace App\Domain;

final readonly class Quantity
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new DomainError('VALIDATION_ERROR', 'La cantidad debe ser mayor que cero.');
        }
    }
}
