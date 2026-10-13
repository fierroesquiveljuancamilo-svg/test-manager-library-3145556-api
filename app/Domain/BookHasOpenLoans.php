<?php

namespace App\Domain;

final class BookHasOpenLoans extends DomainError
{
    public function __construct()
    {
        parent::__construct('BOOK_HAS_OPEN_LOANS', 'El libro tiene préstamos abiertos.');
    }
}
