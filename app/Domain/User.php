<?php

namespace App\Domain;

use SensitiveParameter;

final readonly class User
{
    public string $id;

    public string $username;

    private string $passwordHash;

    private function __construct(string $id, string $username, #[SensitiveParameter] string $passwordHash, public UserRole $role)
    {
        $this->id = Guard::uuid($id);
        $this->username = mb_strtolower(Guard::text($username, 120), 'UTF-8');
        if ($passwordHash === '' || strlen($passwordHash) > 512) {
            throw new DomainError('VALIDATION_ERROR', 'El hash de la contraseña es obligatorio y no puede superar 512 caracteres.');
        }
        $this->passwordHash = $passwordHash;
    }

    public static function register(string $id, string $username, #[SensitiveParameter] string $passwordHash, string $role = 'librarian'): self
    {
        if ($role !== UserRole::Librarian->value) {
            throw new DomainError('VALIDATION_ERROR', 'Solo se pueden registrar bibliotecarios.');
        }

        return new self($id, $username, $passwordHash, UserRole::Librarian);
    }

    public static function provisionAdmin(string $id, string $username, #[SensitiveParameter] string $passwordHash): self
    {
        return new self($id, $username, $passwordHash, UserRole::Admin);
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /** @return array{id: string, username: string, role: string} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'username' => $this->username, 'role' => $this->role->value];
    }
}
