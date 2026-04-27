<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[Index(columns: ['status'], name: 'status_idx')]
#[Index(columns: ['charge_method'], name: 'charge_method_idx')]
#[Index(columns: ['created_at'], name: 'created_at_idx')]
#[Index(columns: ['status', 'charge_method', 'created_at'], name: 'status_charge_created_idx')]
#[Index(columns: ['user_id', 'status', 'is_expired', 'expiration_at'], name: 'user_status_expiration_idx')]
#[Index(columns: ['branch_office_id', 'created_at'], name: 'branch_created_at_idx')]
class Transaction implements TimestampableInterface
{
    use TimestampableTrait;

    public const CHARGE_METHOD_FREE = 'payment.free';
    public const CHARGE_METHOD_CASH = 'payment.cash';
    public const CHARGE_METHOD_CARD = 'payment.card';
    public const CHARGE_METHOD_POS = 'payment.pos';

    public const NUMBER_OF_ITEMS = 10;
    public const NUMBER_OF_ITEMS_USER = 10;

    public const STATUS_PENDING = 0;
    public const STATUS_PAID = 1;
    public const STATUS_DENIED = -1;
    public const STATUS_CANCEL = 2;

    public const STATUS_FROZEN = 3;

    public const DATE_START = '2017-06-01 00:00:00';

    public const WITH_DISCOUNT = 1;
    public const WITHOUT_DISCOUNT = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Package $package = null;

    #[ORM\Column]
    private ?int $packageTotalClasses = null;

    #[ORM\Column]
    private ?bool $packageIsUnlimited = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $packageAmount = null;

    #[ORM\Column(length: 25)]
    private ?string $packageType = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $packageDaysExpiry = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $packageHasRestrictions = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $packageRestrictionHours = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $packageRestrictionDays = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $packageRestrictionInstructorIds = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $packageRestrictionDisciplineIds = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $packageRestrictionBranchIds = null;

    #[ORM\Column(length: 25)]
    private ?string $chargeMethod = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $chargeId = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $chargeAuthCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cardName = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $cardType = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $cardBrand = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $cardIssuer = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $cardLast4 = null;

    #[ORM\Column]
    private ?bool $isExpired = false;

    #[ORM\Column]
    private ?bool $isFrozen = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $frozenAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $frozenDaysRemaining = null;

    #[ORM\Column(nullable: true)]
    private ?int $frozenSecondsRemaining = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expirationAt = null;

    #[ORM\Column]
    private ?bool $isCompleted = false;

    #[ORM\Column]
    private ?int $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $refundedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private ?bool $haveSessionsAvailable = false;

    #[ORM\OneToMany(mappedBy: 'transaction', targetEntity: Reservation::class)]
    private Collection $reservations;

    #[ORM\ManyToOne]
    private ?BranchOffice $branchOffice = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private ?int $discount = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $packageSpecialPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 2, nullable: true)]
    private ?string $couponDiscount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private ?string $total = null;

    #[ORM\ManyToOne]
    private ?Coupon $coupon = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
    }

    public static function statusChoices(): array
    {
        return [
            self::STATUS_PAID => 'transaction_status.paid',
            self::STATUS_DENIED => 'transaction_status.denied',
            self::STATUS_CANCEL => 'transaction_status.cancel',
            self::STATUS_FROZEN => 'transaction_status.frozen',
        ];
    }

    public static function chargeMethodChoices(): array
    {
        return [
            self::CHARGE_METHOD_FREE => 'payment.free',
            self::CHARGE_METHOD_CASH => 'payment.cash',
            self::CHARGE_METHOD_CARD => 'payment.card',
            self::CHARGE_METHOD_POS => 'payment.pos',
        ];
    }

    public function calculateTotal(): self
    {
        $total = (float) (!empty($this->packageSpecialPrice) ? $this->packageSpecialPrice : $this->packageAmount);

        if ($this->couponDiscount) {
            $discount = (float) $this->couponDiscount;
            $total -= ($discount * $total) / 100;
        }

        if ($this->discount > 0) {
            $total -= ($this->discount * $total) / 100;
        }

        $this->total = (string) round($total, 2);

        return $this;
    }

    public function isPaid(): bool
    {
        return self::STATUS_PAID === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

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

    public function getPackageTotalClasses(): ?int
    {
        return $this->packageTotalClasses;
    }

    public function setPackageTotalClasses(int $packageTotalClasses): static
    {
        $this->packageTotalClasses = $packageTotalClasses;

        return $this;
    }

    public function isPackageIsUnlimited(): ?bool
    {
        return $this->packageIsUnlimited;
    }

    public function setPackageIsUnlimited(bool $packageIsUnlimited): static
    {
        $this->packageIsUnlimited = $packageIsUnlimited;

        return $this;
    }

    public function getPackageAmount(): ?string
    {
        return $this->packageAmount;
    }

    public function setPackageAmount(string $packageAmount): static
    {
        $this->packageAmount = $packageAmount;

        return $this;
    }

    public function getPackageType(): ?string
    {
        return $this->packageType;
    }

    public function setPackageType(string $packageType): static
    {
        $this->packageType = $packageType;

        return $this;
    }

    public function getPackageDaysExpiry(): ?int
    {
        return $this->packageDaysExpiry;
    }

    public function setPackageDaysExpiry(int $packageDaysExpiry): static
    {
        $this->packageDaysExpiry = $packageDaysExpiry;

        return $this;
    }

    public function isPackageHasRestrictions(): ?bool
    {
        return $this->packageHasRestrictions;
    }

    public function setPackageHasRestrictions(bool $packageHasRestrictions): static
    {
        $this->packageHasRestrictions = $packageHasRestrictions;

        return $this;
    }

    public function getPackageRestrictionHours(): ?array
    {
        return $this->packageRestrictionHours;
    }

    public function setPackageRestrictionHours(?array $packageRestrictionHours): static
    {
        $this->packageRestrictionHours = $packageRestrictionHours;

        return $this;
    }

    public function getPackageRestrictionDays(): ?array
    {
        return $this->packageRestrictionDays;
    }

    public function setPackageRestrictionDays(?array $packageRestrictionDays): static
    {
        $this->packageRestrictionDays = $packageRestrictionDays;

        return $this;
    }

    public function getPackageRestrictionInstructorIds(): ?array
    {
        return $this->packageRestrictionInstructorIds;
    }

    public function setPackageRestrictionInstructorIds(?array $packageRestrictionInstructorIds): static
    {
        $this->packageRestrictionInstructorIds = $packageRestrictionInstructorIds;

        return $this;
    }

    public function getPackageRestrictionDisciplineIds(): ?array
    {
        return $this->packageRestrictionDisciplineIds;
    }

    public function setPackageRestrictionDisciplineIds(?array $packageRestrictionDisciplineIds): static
    {
        $this->packageRestrictionDisciplineIds = $packageRestrictionDisciplineIds;

        return $this;
    }

    public function getPackageRestrictionBranchIds(): ?array
    {
        return $this->packageRestrictionBranchIds;
    }

    public function setPackageRestrictionBranchIds(?array $packageRestrictionBranchIds): static
    {
        $this->packageRestrictionBranchIds = $packageRestrictionBranchIds;

        return $this;
    }

    public function getChargeMethod(): ?string
    {
        return $this->chargeMethod;
    }

    public function setChargeMethod(string $chargeMethod): static
    {
        $this->chargeMethod = $chargeMethod;

        return $this;
    }

    public function getChargeId(): ?string
    {
        return $this->chargeId;
    }

    public function setChargeId(?string $chargeId): static
    {
        $this->chargeId = $chargeId;

        return $this;
    }

    public function getChargeAuthCode(): ?string
    {
        return $this->chargeAuthCode;
    }

    public function setChargeAuthCode(?string $chargeAuthCode): static
    {
        $this->chargeAuthCode = $chargeAuthCode;

        return $this;
    }

    public function getCardName(): ?string
    {
        return $this->cardName;
    }

    public function setCardName(?string $cardName): static
    {
        $this->cardName = $cardName;

        return $this;
    }

    public function getCardType(): ?string
    {
        return $this->cardType;
    }

    public function setCardType(?string $cardType): static
    {
        $this->cardType = $cardType;

        return $this;
    }

    public function getCardBrand(): ?string
    {
        return $this->cardBrand;
    }

    public function setCardBrand(?string $cardBrand): static
    {
        $this->cardBrand = $cardBrand;

        return $this;
    }

    public function getCardIssuer(): ?string
    {
        return $this->cardIssuer;
    }

    public function setCardIssuer(?string $cardIssuer): static
    {
        $this->cardIssuer = $cardIssuer;

        return $this;
    }

    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    public function setCardLast4(?string $cardLast4): static
    {
        $this->cardLast4 = $cardLast4;

        return $this;
    }

    public function isIsExpired(): ?bool
    {
        return $this->isExpired;
    }

    public function setIsExpired(bool $isExpired): static
    {
        $this->isExpired = $isExpired;

        return $this;
    }

    public function isIsFrozen(): ?bool
    {
        return $this->isFrozen;
    }

    public function setIsFrozen(bool $isFrozen): static
    {
        $this->isFrozen = $isFrozen;

        return $this;
    }

    public function getFrozenAt(): ?\DateTimeInterface
    {
        return $this->frozenAt;
    }

    public function setFrozenAt(?\DateTimeInterface $frozenAt): static
    {
        $this->frozenAt = $frozenAt;

        return $this;
    }

    public function getFrozenDaysRemaining(): ?int
    {
        return $this->frozenDaysRemaining;
    }

    public function setFrozenDaysRemaining(?int $frozenDaysRemaining): static
    {
        $this->frozenDaysRemaining = $frozenDaysRemaining;

        return $this;
    }

    public function getFrozenSecondsRemaining(): ?int
    {
        return $this->frozenSecondsRemaining;
    }

    public function setFrozenSecondsRemaining(?int $frozenSecondsRemaining): static
    {
        $this->frozenSecondsRemaining = $frozenSecondsRemaining;

        return $this;
    }

    public function getFrozenRemainingSeconds(): int
    {
        if (null !== $this->frozenSecondsRemaining && $this->frozenSecondsRemaining > 0) {
            return $this->frozenSecondsRemaining;
        }

        if (null !== $this->frozenAt && null !== $this->expirationAt) {
            $seconds = $this->expirationAt->getTimestamp() - $this->frozenAt->getTimestamp();

            if ($seconds > 0) {
                return $seconds;
            }
        }

        return max(0, (int) ($this->frozenDaysRemaining ?? 0)) * 86400;
    }

    public function getFrozenRemainingDetailed(): string
    {
        return self::formatSecondsToDaysHoursMinutes($this->getFrozenRemainingSeconds());
    }

    public function freeze(?\DateTimeInterface $frozenAt = null, ?int $daysRemaining = null): static
    {
        $this->isFrozen = true;
        $this->frozenAt = $frozenAt ?? new \DateTime();
        $this->frozenDaysRemaining = $daysRemaining;

        return $this;
    }

    public function unfreeze(): static
    {
        $this->isFrozen = false;
        $this->frozenAt = null;
        $this->frozenDaysRemaining = null;
        $this->frozenSecondsRemaining = null;

        return $this;
    }

    public function getExpirationAt(): ?\DateTimeInterface
    {
        return $this->expirationAt;
    }

    public function setExpirationAt(?\DateTimeInterface $expirationAt): static
    {
        $this->expirationAt = $expirationAt;

        return $this;
    }

    public function isIsCompleted(): ?bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRefundedAt(): ?\DateTimeInterface
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeInterface $refundedAt): static
    {
        $this->refundedAt = $refundedAt;

        return $this;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): static
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function isHaveSessionsAvailable(): ?bool
    {
        return $this->haveSessionsAvailable;
    }

    public function setHaveSessionsAvailable(bool $haveSessionsAvailable): static
    {
        $this->haveSessionsAvailable = $haveSessionsAvailable;

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setTransaction($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getTransaction() === $this) {
                $reservation->setTransaction(null);
            }
        }

        return $this;
    }

    public function getBranchOffice(): ?BranchOffice
    {
        return $this->branchOffice;
    }

    public function setBranchOffice(?BranchOffice $branchOffice): static
    {
        $this->branchOffice = $branchOffice;

        return $this;
    }

    public function getDiscount(): ?int
    {
        return $this->discount;
    }

    public function setDiscount(int $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getPackageSpecialPrice(): ?string
    {
        return $this->packageSpecialPrice;
    }

    public function setPackageSpecialPrice(?string $packageSpecialPrice): static
    {
        $this->packageSpecialPrice = $packageSpecialPrice;

        return $this;
    }

    public function getCouponDiscount(): ?string
    {
        return $this->couponDiscount;
    }

    public function setCouponDiscount(?string $couponDiscount): static
    {
        $this->couponDiscount = $couponDiscount;

        return $this;
    }

    public function getTotal(): ?string
    {
        return $this->total;
    }

    public function setTotal(string $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getCoupon(): ?Coupon
    {
        return $this->coupon;
    }

    public function setCoupon(?Coupon $coupon): static
    {
        $this->coupon = $coupon;

        return $this;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function setExpiredAt(?\DateTimeImmutable $expiredAt): static
    {
        $this->expiredAt = $expiredAt;

        return $this;
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
