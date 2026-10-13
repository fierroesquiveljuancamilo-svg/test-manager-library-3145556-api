<?php

namespace App\Domain;

final class Guard
{
    public static function uuid(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) !== 1) {
            throw new DomainError('VALIDATION_ERROR', 'El identificador debe ser un UUID v4.');
        }

        return strtolower($value);
    }

    public static function text(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new DomainError('VALIDATION_ERROR', 'El texto es obligatorio y debe respetar su longitud máxima.');
        }

        return $value;
    }
}
