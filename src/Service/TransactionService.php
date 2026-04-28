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
use App\Entity\Staff;
use App\Entity\Transaction;
use App\Entity\TransactionFreezeLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

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
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
    )
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
        $specialPriceForSnapshot = $package->isSpecialPriceActiveAt(new \DateTime())
            ? $package->getSpecialPrice()
            : null;

        $transaction
            ->setUser($user)
            ->setPackage($package)
            ->setPackageTotalClasses($package->getTotalClasses())
            ->setPackageIsUnlimited($package->isIsUnlimited())
            ->setPackageAmount($package->getAmount())
            ->setPackageSpecialPrice($specialPriceForSnapshot)
            ->setPackageType($package->getType())
            ->setPackageDaysExpiry($package->getDaysExpiry())
            ->setPackageHasRestrictions((bool) $package->isHasRestrictions())
            ->setPackageRestrictionHours($package->getRestrictionHours())
            ->setPackageRestrictionDays($package->getRestrictionDays())
            ->setPackageRestrictionInstructorIds($package->getRestrictionInstructorIds())
            ->setPackageRestrictionDisciplineIds($package->getRestrictionDisciplineIds())
            ->setPackageRestrictionBranchIds($package->getRestrictionBranchIds())
            ->setBranchOffice($branchOffice)
            ->setDiscount($discount)
            ->setChargeMethod($chargeMethod)
        ;

        $transaction->calculateTotal();

        return $transaction;
    }

    /**
     * Congela una transacción activa para impedir nuevas reservaciones y registrar auditoría.
     */
    public function freeze(Transaction $transaction, Staff $staff, string $reason): Transaction
    {
        if ($transaction->isIsFrozen()) {
            throw new \LogicException('La transacción ya se encuentra congelada.');
        }

        if ($transaction->isIsExpired()) {
            throw new \LogicException('No se puede congelar una transacción expirada.');
        }

        if (Transaction::STATUS_PAID !== $transaction->getStatus()) {
            throw new \LogicException('Solo se pueden congelar transacciones pagadas.');
        }

        $cleanReason = $this->normalizeReason($reason);
        if ('' === $cleanReason) {
            throw new \InvalidArgumentException('El motivo de congelación es obligatorio.');
        }

        $now = new \DateTimeImmutable();
        $secondsRemaining = $this->calculateRemainingSeconds($transaction, $now);
        $daysRemaining = $this->secondsToDaysCeil($secondsRemaining);

        $transaction->freeze(\DateTime::createFromImmutable($now), $daysRemaining);
        $transaction->setFrozenSecondsRemaining($secondsRemaining);
        $transaction->setStatus(Transaction::STATUS_FROZEN);

        $audit = new TransactionFreezeLog();
        $audit
            ->setTransaction($transaction)
            ->setStaff($staff)
            ->setAction(TransactionFreezeLog::ACTION_FREEZE)
            ->setReason($cleanReason)
            ->setOriginalExpirationAt($transaction->getExpirationAt())
            ->setDaysRemaining($daysRemaining)
            ->setRemainingSeconds($secondsRemaining)
        ;

        $this->em->persist($transaction);
        $this->em->persist($audit);
        $this->em->flush();

        return $transaction;
    }

    /**
     * Descongela una transacción y recorre su fecha de expiración con los días pendientes al momento de congelar.
     */
    public function unfreeze(Transaction $transaction, Staff $staff, string $reason): Transaction
    {
        if (!$transaction->isIsFrozen()) {
            throw new \LogicException('La transacción no está congelada.');
        }

        if (Transaction::STATUS_FROZEN !== $transaction->getStatus()) {
            throw new \LogicException('La transacción no tiene estado de congelada.');
        }

        $cleanReason = $this->normalizeReason($reason);
        if ('' === $cleanReason) {
            throw new \InvalidArgumentException('El motivo de descongelación es obligatorio.');
        }

        $now = new \DateTimeImmutable();
        $secondsRemaining = $this->getFrozenRemainingSeconds($transaction);
        $daysRemaining = $this->secondsToDaysCeil($secondsRemaining);

        $newExpirationAt = null;
        if (null !== $transaction->getExpirationAt()) {
            $newExpirationAt = $now->modify(sprintf('+%d seconds', $secondsRemaining));
            $transaction->setExpirationAt(\DateTime::createFromImmutable($newExpirationAt));
        }

        $transaction->unfreeze();
        $transaction->setStatus(Transaction::STATUS_PAID);

        $audit = new TransactionFreezeLog();
        $audit
            ->setTransaction($transaction)
            ->setStaff($staff)
            ->setAction(TransactionFreezeLog::ACTION_UNFREEZE)
            ->setReason($cleanReason)
            ->setOriginalExpirationAt(null)
            ->setDaysRemaining($daysRemaining)
            ->setRemainingSeconds($secondsRemaining)
        ;

        $this->em->persist($transaction);
        $this->em->persist($audit);
        $this->em->flush();

        return $transaction;
    }

    private function calculateRemainingSeconds(Transaction $transaction, \DateTimeImmutable $now): int
    {
        $expirationAt = $transaction->getExpirationAt();
        if (null === $expirationAt) {
            return 0;
        }

        $seconds = $expirationAt->getTimestamp() - $now->getTimestamp();

        if ($seconds <= 0) {
            return 0;
        }

        return $seconds;
    }

    private function getFrozenRemainingSeconds(Transaction $transaction): int
    {
        $stored = (int) ($transaction->getFrozenSecondsRemaining() ?? 0);
        if ($stored > 0) {
            return $stored;
        }

        $expirationAt = $transaction->getExpirationAt();
        $frozenAt = $transaction->getFrozenAt();

        if (null !== $expirationAt && null !== $frozenAt) {
            $seconds = $expirationAt->getTimestamp() - $frozenAt->getTimestamp();
            if ($seconds > 0) {
                return $seconds;
            }
        }

        return max(0, (int) ($transaction->getFrozenDaysRemaining() ?? 0)) * 86400;
    }

    private function secondsToDaysCeil(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        return (int) ceil($seconds / 86400);
    }

    private function normalizeReason(string $reason): string
    {
        return trim($reason);
    }
}
