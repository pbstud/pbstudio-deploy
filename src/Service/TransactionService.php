<?php
/**
 * Created by.
 *
 * User: JCHR <car.chr@gmail.com>
 * Date: 2020-11-09
 * Time: 7:58
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\BranchOffice;
use App\Entity\Package;
use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Transaction Service.
 *
 * @author JCHR <car.chr@gmail.com>
 */
readonly class TransactionService
{
    /**
     * TransactionS ervice constructor.
     *
     * @param Security $security
     */
    public function __construct(private Security $security)
    {
    }

    /**
     * @param Package                 $package
     * @param string                  $chargeMethod
     * @param User|null               $user
     * @param BranchOffice|null       $branchOffice
     * @param int                     $discount
     *
     * @return Transaction
     */
    public function create(
        Package $package,
        string $chargeMethod,
        ?User $user = null,
        ?BranchOffice $branchOffice = null,
        int $discount = 0
    ): Transaction {
        if (!$user) {
            $currentUser = $this->security->getUser();
            if ($currentUser instanceof User) {
                $user = $currentUser;
            }
        }

        if (!$user) {
            throw new \InvalidArgumentException('Usuario autenticado requerido para crear transacción');
        }

        if (!$branchOffice) {
            $branchOffice = $user->getBranchOffice();
        }

        $transaction = new Transaction();
        $transaction
            ->setUser($user)
            ->setPackage($package)
            ->setPackageTotalClasses($package->getTotalClasses())
            ->setPackageIsUnlimited($package->isIsUnlimited())
            ->setPackageAmount($package->getAmount())
            ->setPackageSpecialPrice($package->getSpecialPrice())
            ->setPackageType($package->getType())
            ->setPackageDaysExpiry($package->getDaysExpiry())
            ->setBranchOffice($branchOffice)
            ->setDiscount($discount)
            ->setChargeMethod($chargeMethod)
        ;

        $transaction->calculateTotal();

        return $transaction;
    }
}
