<?php

namespace App\Domain;

enum UserRole: string
{
    case Admin = 'admin';
    case Librarian = 'librarian';
}
