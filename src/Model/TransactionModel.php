<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\BranchOffice;
use App\Entity\Package;
use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Transaction Model.
 */
class TransactionModel
{
    #[Assert\NotBlank]
    private ?User $user = null;

    #[Assert\NotBlank]
    private ?Package $package = null;

    #[Assert\Choice(['payment.free', 'payment.cash', 'payment.card', 'payment.pos'])]
    private ?string $chargeMethod = null;

    #[Assert\Length(max: 6)]
    private ?string $chargeAuthCode = null;

    #[Assert\Length(min: 4, max: 4)]
    private ?string $cardLast4 = null;

    #[Assert\NotBlank]
    private ?BranchOffice $branchOffice = null;

    #[Assert\Range(min: 0, max: 99)]
    private ?int $discount = 0;

    /**
     * @var string|null
     */
    private ?string $coupon = null;

    /**
     * Set user.
     *
     * @param User $user
     *
     * @return TransactionModel
     */
    public function setUser(User $user): TransactionModel
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user.
     *
     * @return User|null
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Set package.
     *
     * @param Package $package
     *
     * @return TransactionModel
     */
    public function setPackage(Package $package): TransactionModel
    {
        $this->package = $package;

        return $this;
    }

    /**
     * Get package.
     *
     * @return Package|null
     */
    public function getPackage(): ?Package
    {
        return $this->package;
    }

    /**
     * Set chargeMethod.
     *
     * @param string $chargeMethod
     *
     * @return TransactionModel
     */
    public function setChargeMethod(string $chargeMethod): TransactionModel
    {
        $this->chargeMethod = $chargeMethod;

        return $this;
    }

    /**
     * Get chargeMethod.
     *
     * @return string|null
     */
    public function getChargeMethod(): ?string
    {
        return $this->chargeMethod;
    }

    /**
     * Set chargeAuthCode.
     *
     * @param string $chargeAuthCode
     *
     * @return TransactionModel
     */
    public function setChargeAuthCode(string $chargeAuthCode): TransactionModel
    {
        $this->chargeAuthCode = $chargeAuthCode;

        return $this;
    }

    /**
     * Get chargeAuthCode.
     *
     * @return string|null
     */
    public function getChargeAuthCode(): ?string
    {
        return $this->chargeAuthCode;
    }

    /**
     * Set cardLast4.
     *
     * @param string $cardLast4
     *
     * @return TransactionModel
     */
    public function setCardLast4(string $cardLast4): TransactionModel
    {
        $this->cardLast4 = $cardLast4;

        return $this;
    }

    /**
     * Get cardLast4.
     *
     * @return string|null
     */
    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    /**
     *
     * @return BranchOffice|null
     */
    public function getBranchOffice(): ?BranchOffice
    {
        return $this->branchOffice;
    }

    /**
     * @param BranchOffice|null $branchOffice
     *
     * @return TransactionModel
     */
    public function setBranchOffice(?BranchOffice $branchOffice): TransactionModel
    {
        $this->branchOffice = $branchOffice;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getDiscount(): ?int
    {
        return $this->discount;
    }

    /**
     * @param int|null $discount
     *
     * @return TransactionModel
     */
    public function setDiscount(?int $discount): TransactionModel
    {
        $this->discount = $discount;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCoupon(): ?string
    {
        return $this->coupon;
    }

    /**
     * @param string|null $coupon
     *
     * @return TransactionModel
     */
    public function setCoupon(?string $coupon): TransactionModel
    {
        $this->coupon = $coupon;

        return $this;
    }
}
