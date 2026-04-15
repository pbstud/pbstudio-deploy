<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CouponRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[UniqueEntity('code')]
class Coupon implements TimestampableInterface
{
    use TimestampableTrait;

    public const NUMBER_OF_ITEMS = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private ?string $code = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateStart = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateEnd = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\GreaterThan(0)]
    #[Assert\LessThanOrEqual(100)]
    private ?string $discount = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\GreaterThan(0)]
    private ?int $usesTotal = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $applySpecialPrice = null;

    #[ORM\Column(options: ['default' => 0])]
    private ?int $used = 0;

    #[ORM\ManyToMany(targetEntity: Package::class)]
    private Collection $packages;

    #[ORM\OneToMany(mappedBy: 'coupon', targetEntity: CouponHistory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $couponHistories;

    public function __construct()
    {
        $this->packages = new ArrayCollection();
        $this->couponHistories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
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

    public function getDateStart(): ?\DateTimeInterface
    {
        return $this->dateStart;
    }

    public function setDateStart(?\DateTimeInterface $dateStart): static
    {
        $this->dateStart = $dateStart;

        return $this;
    }

    public function getDateEnd(): ?\DateTimeInterface
    {
        return $this->dateEnd;
    }

    public function setDateEnd(?\DateTimeInterface $dateEnd): static
    {
        $this->dateEnd = $dateEnd;

        return $this;
    }

    public function getDiscount(): ?string
    {
        return $this->discount;
    }

    public function setDiscount(string $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getUsesTotal(): ?int
    {
        return $this->usesTotal;
    }

    public function setUsesTotal(int $usesTotal): static
    {
        $this->usesTotal = $usesTotal;

        return $this;
    }

    public function isApplySpecialPrice(): ?bool
    {
        return $this->applySpecialPrice;
    }

    public function setApplySpecialPrice(bool $applySpecialPrice): static
    {
        $this->applySpecialPrice = $applySpecialPrice;

        return $this;
    }

    public function getUsed(): ?int
    {
        return $this->used;
    }

    public function setUsed(int $used): static
    {
        $this->used = $used;

        return $this;
    }

    public function incrementUsed(): static
    {
        ++$this->used;

        return $this;
    }

    public function decrementUsed(): static
    {
        --$this->used;

        return $this;
    }

    /**
     * @return Collection<int, Package>
     */
    public function getPackages(): Collection
    {
        return $this->packages;
    }

    public function addPackage(Package $package): static
    {
        if (!$this->packages->contains($package)) {
            $this->packages->add($package);
        }

        return $this;
    }

    public function removePackage(Package $package): static
    {
        $this->packages->removeElement($package);

        return $this;
    }

    public function hasPackage(Package $package): bool
    {
        return $this->packages->contains($package);
    }

    /**
     * @return Collection<int, CouponHistory>
     */
    public function getCouponHistories(): Collection
    {
        return $this->couponHistories;
    }

    public function addCouponHistory(CouponHistory $couponHistory): static
    {
        if (!$this->couponHistories->contains($couponHistory)) {
            $this->couponHistories->add($couponHistory);
            $couponHistory->setCoupon($this);
        }

        return $this;
    }

    public function removeCouponHistory(CouponHistory $couponHistory): static
    {
        if ($this->couponHistories->removeElement($couponHistory)) {
            // set the owning side to null (unless already changed)
            if ($couponHistory->getCoupon() === $this) {
                $couponHistory->setCoupon(null);
            }
        }

        return $this;
    }
}
