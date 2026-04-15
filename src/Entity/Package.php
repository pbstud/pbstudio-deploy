<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PackageRepository;
use App\Util\PackageSessionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PackageRepository::class)]
class Package implements TimestampableInterface
{
    use TimestampableTrait;

    public const NUMBER_OF_ITEMS = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(0)]
    private ?int $totalClasses = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank]
    private ?string $amount = null;

    #[ORM\Column(length: 25)]
    #[Assert\NotBlank]
    private ?string $type = PackageSessionType::TYPE_INDIVIDUAL;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotBlank]
    private ?int $daysExpiry = null;

    #[ORM\Column]
    private ?bool $isUnlimited = false;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $newUser = false;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $public = true;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $specialPrice = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $discountInfo = null;

    public static function typeChoices(): array
    {
        return [
            PackageSessionType::TYPE_INDIVIDUAL => 'package_type.individual',
            PackageSessionType::TYPE_GROUP => 'package_type.group',
        ];
    }

    public static function statusChoices(): array
    {
        return [
            true => 'label.active',
            false => 'label.inactive',
        ];
    }

    public static function publicChoices(): array
    {
        return [
            true => 'label.yes',
            false => 'label.no',
        ];
    }

    public function getTotalPrice(): string
    {
        return $this->hasSpecialPrice() ? $this->specialPrice : $this->amount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTotalClasses(): ?int
    {
        return $this->totalClasses;
    }

    public function setTotalClasses(int $totalClasses): static
    {
        $this->totalClasses = $totalClasses;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDaysExpiry(): ?int
    {
        return $this->daysExpiry;
    }

    public function setDaysExpiry(int $daysExpiry): static
    {
        $this->daysExpiry = $daysExpiry;

        return $this;
    }

    public function isIsUnlimited(): ?bool
    {
        return $this->isUnlimited;
    }

    public function setIsUnlimited(bool $isUnlimited): static
    {
        $this->isUnlimited = $isUnlimited;

        return $this;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): static
    {
        $this->altText = $altText;

        return $this;
    }

    public function isIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isNewUser(): ?bool
    {
        return $this->newUser;
    }

    public function setNewUser(bool $newUser): static
    {
        $this->newUser = $newUser;

        return $this;
    }

    public function isPublic(): ?bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;

        return $this;
    }

    public function hasSpecialPrice(): bool
    {
        return $this->specialPrice > 0;
    }

    public function getSpecialPrice(): ?string
    {
        return $this->specialPrice;
    }

    public function setSpecialPrice(?string $specialPrice): static
    {
        $this->specialPrice = $specialPrice;

        return $this;
    }

    public function getDiscountInfo(): ?string
    {
        return $this->discountInfo;
    }

    public function setDiscountInfo(?string $discountInfo): static
    {
        $this->discountInfo = $discountInfo;

        return $this;
    }
}
