<?php

namespace App\Domain;

final class MemberHasOverdue extends DomainError
{
    public function __construct()
    {
        parent::__construct('MEMBER_HAS_OVERDUE', 'El socio tiene libros vencidos sin devolver.');
    }
}
