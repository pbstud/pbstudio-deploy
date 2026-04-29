<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GiftCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GiftCardRepository::class)]
#[ORM\Table(name: 'gift_card')]
#[ORM\Index(columns: ['status'], name: 'idx_gift_card_status')]
#[ORM\Index(columns: ['origin_channel'], name: 'idx_gift_card_origin_channel')]
#[ORM\Index(columns: ['purchaser_user_id'], name: 'idx_gift_card_purchaser_user')]
#[ORM\Index(columns: ['recipient_user_id'], name: 'idx_gift_card_recipient_user')]
#[ORM\Index(columns: ['package_id'], name: 'idx_gift_card_package')]
#[ORM\UniqueConstraint(name: 'uniq_gift_card_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_gift_card_purchase_transaction', columns: ['purchase_transaction_id'])]
#[ORM\UniqueConstraint(name: 'uniq_gift_card_redemption_transaction', columns: ['redemption_transaction_id'])]
#[ORM\HasLifecycleCallbacks]
class GiftCard
{
    public const STATUS_GENERATED = 'generated';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FROZEN = 'frozen';

    public const ORIGIN_FRONTEND = 'frontend';
    public const ORIGIN_BACKEND = 'backend';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $code = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Package $package = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $purchaserUser = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Transaction $purchaseTransaction = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recipientUser = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Transaction $redemptionTransaction = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_GENERATED])]
    private string $status = self::STATUS_GENERATED;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amountSnapshot = null;

    #[ORM\Column(length: 255)]
    private ?string $packageNameSnapshot = null;

    #[ORM\Column(length: 25)]
    private ?string $packageTypeSnapshot = null;

    #[ORM\Column]
    private ?int $packageTotalClassesSnapshot = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $packageDaysExpirySnapshot = null;

    #[ORM\Column(length: 10, options: ['default' => 'MXN'])]
    private string $currencySnapshot = 'MXN';

    #[ORM\Column(length: 20)]
    private ?string $originChannel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $redeemedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $giftExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['default' => null], columnDefinition: 'LONGTEXT DEFAULT NULL')]
    private ?string $cancellationReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'giftCard', targetEntity: GiftCardHistory::class, orphanRemoval: false)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $histories;

    public function __construct()
    {
        $this->histories = new ArrayCollection();
    }

    public static function statusChoices(): array
    {
        return [
            self::STATUS_GENERATED => self::STATUS_GENERATED,
            self::STATUS_REDEEMED => self::STATUS_REDEEMED,
            self::STATUS_FROZEN => self::STATUS_FROZEN,
            self::STATUS_EXPIRED => self::STATUS_EXPIRED,
            self::STATUS_CANCELLED => self::STATUS_CANCELLED,
        ];
    }

    public static function originChoices(): array
    {
        return [
            self::ORIGIN_FRONTEND => self::ORIGIN_FRONTEND,
            self::ORIGIN_BACKEND => self::ORIGIN_BACKEND,
        ];
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getPackage(): ?Package
    {
        return $this->package;
    }

    public function setPackage(?Package $package): static
    {
        $this->package = $package;

        return $this;
    }

    public function getPurchaserUser(): ?User
    {
        return $this->purchaserUser;
    }

    public function setPurchaserUser(?User $purchaserUser): static
    {
        $this->purchaserUser = $purchaserUser;

        return $this;
    }

    public function getPurchaseTransaction(): ?Transaction
    {
        return $this->purchaseTransaction;
    }

    public function setPurchaseTransaction(?Transaction $purchaseTransaction): static
    {
        $this->purchaseTransaction = $purchaseTransaction;

        return $this;
    }

    public function getRecipientUser(): ?User
    {
        return $this->recipientUser;
    }

    public function setRecipientUser(?User $recipientUser): static
    {
        $this->recipientUser = $recipientUser;

        return $this;
    }

    public function getRedemptionTransaction(): ?Transaction
    {
        return $this->redemptionTransaction;
    }

    public function setRedemptionTransaction(?Transaction $redemptionTransaction): static
    {
        $this->redemptionTransaction = $redemptionTransaction;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAmountSnapshot(): ?string
    {
        return $this->amountSnapshot;
    }

    public function setAmountSnapshot(string $amountSnapshot): static
    {
        $this->amountSnapshot = $amountSnapshot;

        return $this;
    }

    public function getPackageNameSnapshot(): ?string
    {
        return $this->packageNameSnapshot;
    }

    public function setPackageNameSnapshot(string $packageNameSnapshot): static
    {
        $this->packageNameSnapshot = $packageNameSnapshot;

        return $this;
    }

    public function getPackageTypeSnapshot(): ?string
    {
        return $this->packageTypeSnapshot;
    }

    public function setPackageTypeSnapshot(string $packageTypeSnapshot): static
    {
        $this->packageTypeSnapshot = $packageTypeSnapshot;

        return $this;
    }

    public function getPackageTotalClassesSnapshot(): ?int
    {
        return $this->packageTotalClassesSnapshot;
    }

    public function setPackageTotalClassesSnapshot(int $packageTotalClassesSnapshot): static
    {
        $this->packageTotalClassesSnapshot = $packageTotalClassesSnapshot;

        return $this;
    }

    public function getPackageDaysExpirySnapshot(): ?int
    {
        return $this->packageDaysExpirySnapshot;
    }

    public function setPackageDaysExpirySnapshot(int $packageDaysExpirySnapshot): static
    {
        $this->packageDaysExpirySnapshot = $packageDaysExpirySnapshot;

        return $this;
    }

    public function getCurrencySnapshot(): string
    {
        return $this->currencySnapshot;
    }

    public function setCurrencySnapshot(string $currencySnapshot): static
    {
        $this->currencySnapshot = $currencySnapshot;

        return $this;
    }

    public function getOriginChannel(): ?string
    {
        return $this->originChannel;
    }

    public function setOriginChannel(string $originChannel): static
    {
        $this->originChannel = $originChannel;

        return $this;
    }

    public function getPurchasedAt(): ?\DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getAssignedAt(): ?\DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(?\DateTimeImmutable $assignedAt): static
    {
        $this->assignedAt = $assignedAt;

        return $this;
    }

    public function getRedeemedAt(): ?\DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function setRedeemedAt(?\DateTimeImmutable $redeemedAt): static
    {
        $this->redeemedAt = $redeemedAt;

        return $this;
    }

    public function getGiftExpiresAt(): ?\DateTimeImmutable
    {
        return $this->giftExpiresAt;
    }

    public function setGiftExpiresAt(?\DateTimeImmutable $giftExpiresAt): static
    {
        $this->giftExpiresAt = $giftExpiresAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): static
    {
        $this->cancellationReason = $cancellationReason;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, GiftCardHistory>
     */
    public function getHistories(): Collection
    {
        return $this->histories;
    }

    public function addHistory(GiftCardHistory $history): static
    {
        if (!$this->histories->contains($history)) {
            $this->histories->add($history);
            $history->setGiftCard($this);
        }

        return $this;
    }

    public function removeHistory(GiftCardHistory $history): static
    {
        if ($this->histories->removeElement($history) && $history->getGiftCard() === $this) {
            $history->setGiftCard(null);
        }

        return $this;
    }
}
