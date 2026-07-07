<?php
namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Staff = 'staff';

    /** @return list<string> */
    public function permissions(): array
    {
        return config("permissions.roles.{$this->value}", []);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
