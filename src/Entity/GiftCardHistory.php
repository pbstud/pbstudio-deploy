<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GiftCardHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GiftCardHistoryRepository::class)]
#[ORM\Table(name: 'gift_card_history')]
#[ORM\Index(columns: ['gift_card_id'], name: 'idx_gch_gift_card')]
#[ORM\Index(columns: ['action'], name: 'idx_gch_action')]
#[ORM\Index(columns: ['created_at'], name: 'idx_gch_created_at')]
#[ORM\Index(columns: ['actor_user_id'], name: 'idx_gch_actor_user')]
#[ORM\Index(columns: ['actor_staff_id'], name: 'idx_gch_actor_staff')]
#[ORM\Index(columns: ['transaction_id'], name: 'idx_gch_transaction')]
class GiftCardHistory
{
    public const ACTION_GENERATED = 'generated';
    public const ACTION_EMAIL_SENT = 'email_sent';
    public const ACTION_SHARED_MANUALLY = 'shared_manually';
    public const ACTION_REDEEM_ATTEMPT_INVALID = 'redeem_attempt_invalid';
    public const ACTION_REDEEMED = 'redeemed';
    public const ACTION_CANCELLED = 'cancelled';
    public const ACTION_EXPIRED = 'expired';
    public const ACTION_RESENT = 'resent';
    public const ACTION_CREATED_FROM_BACKEND = 'created_from_backend';

    public const SOURCE_FRONTEND = 'frontend';
    public const SOURCE_BACKEND = 'backend';
    public const SOURCE_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'histories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GiftCard $giftCard = null;

    #[ORM\Column(length: 40)]
    private ?string $action = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actorUser = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Staff $actorStaff = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Transaction $transaction = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['default' => null], columnDefinition: 'LONGTEXT DEFAULT NULL')]
    private ?string $notes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payloadJson = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceRoute = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sourceContext = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGiftCard(): ?GiftCard
    {
        return $this->giftCard;
    }

    public function setGiftCard(?GiftCard $giftCard): static
    {
        $this->giftCard = $giftCard;

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

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function setActorUser(?User $actorUser): static
    {
        $this->actorUser = $actorUser;

        return $this;
    }

    public function getActorStaff(): ?Staff
    {
        return $this->actorStaff;
    }

    public function setActorStaff(?Staff $actorStaff): static
    {
        $this->actorStaff = $actorStaff;

        return $this;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getPayloadJson(): ?array
    {
        return $this->payloadJson;
    }

    public function setPayloadJson(?array $payloadJson): static
    {
        $this->payloadJson = $payloadJson;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getSourceRoute(): ?string
    {
        return $this->sourceRoute;
    }

    public function setSourceRoute(?string $sourceRoute): static
    {
        $this->sourceRoute = $sourceRoute;

        return $this;
    }

    public function getSourceContext(): ?string
    {
        return $this->sourceContext;
    }

    public function setSourceContext(?string $sourceContext): static
    {
        $this->sourceContext = $sourceContext;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
