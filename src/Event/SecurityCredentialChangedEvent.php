<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;

class SecurityCredentialChangedEvent
{
    public const TYPE_PASSWORD = 'password';
    public const TYPE_EMAIL = 'email';

    public function __construct(
        private readonly User $user,
        private readonly string $type,
        private readonly ?string $oldValue = null,
        private readonly ?string $newValue = null,
        private readonly ?string $ip = null,
        private readonly ?string $userAgent = null,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
