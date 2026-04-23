<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionFreezeLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionFreezeLogRepository::class)]
#[ORM\Table(name: 'transaction_freeze_log')]
#[ORM\Index(columns: ['transaction_id'], name: 'idx_tfl_transaction')]
#[ORM\Index(columns: ['staff_id'], name: 'idx_tfl_staff')]
#[ORM\Index(columns: ['action'], name: 'idx_tfl_action')]
#[ORM\Index(columns: ['created_at'], name: 'idx_tfl_created_at')]
class TransactionFreezeLog
{
    public const ACTION_FREEZE   = 'freeze';
    public const ACTION_UNFREEZE = 'unfreeze';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Transaction $transaction = null;

    /**
     * Staff member who performed the freeze / unfreeze action.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Staff $staff = null;

    /**
     * 'freeze' or 'unfreeze'.
     */
    #[ORM\Column(length: 20)]
    private ?string $action = null;

    /**
     * Reason provided by staff (required for freeze, stored for both actions).
     */
    #[ORM\Column(type: Types::TEXT)]
    private ?string $reason = null;

    /**
     * Snapshot of the transaction's expirationAt value at the moment of freeze.
     * Null when action = 'unfreeze'.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $originalExpirationAt = null;

    /**
     * Full calendar days remaining between freeze date and originalExpirationAt.
     * Null when action = 'unfreeze'.
     */
    #[ORM\Column(nullable: true)]
    private ?int $daysRemaining = null;

    #[ORM\Column(nullable: true)]
    private ?int $remainingSeconds = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): static
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getStaff(): ?Staff
    {
        return $this->staff;
    }

    public function setStaff(?Staff $staff): static
    {
        $this->staff = $staff;

        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getOriginalExpirationAt(): ?\DateTimeInterface
    {
        return $this->originalExpirationAt;
    }

    public function setOriginalExpirationAt(?\DateTimeInterface $originalExpirationAt): static
    {
        $this->originalExpirationAt = $originalExpirationAt;

        return $this;
    }

    public function getDaysRemaining(): ?int
    {
        return $this->daysRemaining;
    }

    public function setDaysRemaining(?int $daysRemaining): static
    {
        $this->daysRemaining = $daysRemaining;

        return $this;
    }

    public function getRemainingSecondsStored(): ?int
    {
        return $this->remainingSeconds;
    }

    public function setRemainingSeconds(?int $remainingSeconds): static
    {
        $this->remainingSeconds = $remainingSeconds;

        return $this;
    }

    public function getRemainingSeconds(): int
    {
        if (null !== $this->remainingSeconds && $this->remainingSeconds > 0) {
            return $this->remainingSeconds;
        }

        if (null !== $this->originalExpirationAt && null !== $this->createdAt) {
            $seconds = $this->originalExpirationAt->getTimestamp() - $this->createdAt->getTimestamp();

            if ($seconds > 0) {
                return $seconds;
            }
        }

        return max(0, (int) ($this->daysRemaining ?? 0)) * 86400;
    }

    public function getRemainingDetailed(): string
    {
        return self::formatSecondsToDaysHoursMinutes($this->getRemainingSeconds());
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function isFreeze(): bool
    {
        return self::ACTION_FREEZE === $this->action;
    }

    public function isUnfreeze(): bool
    {
        return self::ACTION_UNFREEZE === $this->action;
    }

    private static function formatSecondsToDaysHoursMinutes(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $days = intdiv($seconds, 86400);
        $remainder = $seconds % 86400;
        $hours = intdiv($remainder, 3600);
        $minutes = intdiv($remainder % 3600, 60);

        return sprintf('%dd %02dh %02dm', $days, $hours, $minutes);
    }
}
