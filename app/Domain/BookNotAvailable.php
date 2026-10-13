<?php

namespace App\Domain;

final class BookNotAvailable extends DomainError
{
    public function __construct()
    {
        parent::__construct('BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles de este libro.');
    }
}
