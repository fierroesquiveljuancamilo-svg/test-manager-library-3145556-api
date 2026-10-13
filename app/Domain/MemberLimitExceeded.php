<?php

namespace App\Domain;

final class MemberLimitExceeded extends DomainError
{
    public function __construct(public readonly int $currentCount, public readonly int $requestedCount)
    {
        parent::__construct('MEMBER_LIMIT_EXCEEDED', 'El socio no puede tener más de tres libros sin devolver.');
    }
}
