<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GiftCard;
use App\Entity\GiftCardHistory;
use App\Entity\Staff;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\GiftCardRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class GiftCardService
{
    public function __construct(
        private EntityManagerInterface $em,
        private GiftCardRepository $giftCardRepository,
    ) {
    }

    public function createFromPurchaseTransaction(Transaction $purchaseTransaction, string $originChannel): GiftCard
    {
        $transactionId = $purchaseTransaction->getId();
        if (null === $transactionId) {
            throw new \LogicException('La transaccion de compra debe estar persistida antes de generar la gift card.');
        }

        $existingGiftCard = $this->giftCardRepository->findOneByPurchaseTransactionId($transactionId);
        if (null !== $existingGiftCard) {
            return $existingGiftCard;
        }

        $package = $purchaseTransaction->getPackage();
        $purchaser = $purchaseTransaction->getUser();

        if (null === $package) {
            throw new \LogicException('La transaccion de compra no tiene paquete asociado.');
        }

        if (null === $purchaser) {
            throw new \LogicException('La transaccion de compra no tiene usuario comprador asociado.');
        }

        $giftCard = new GiftCard();
        $giftCard
            ->setCode($this->generateUniqueCode())
            ->setPackage($package)
            ->setPurchaserUser($purchaser)
            ->setPurchaseTransaction($purchaseTransaction)
            ->setStatus(GiftCard::STATUS_GENERATED)
            ->setAmountSnapshot($purchaseTransaction->getTotal())
            ->setPackageNameSnapshot(sprintf('package_%d', (int) $package->getId()))
            ->setPackageTypeSnapshot((string) $package->getType())
            ->setPackageTotalClassesSnapshot((int) $purchaseTransaction->getPackageTotalClasses())
            ->setPackageDaysExpirySnapshot((int) $purchaseTransaction->getPackageDaysExpiry())
            ->setCurrencySnapshot('MXN')
            ->setOriginChannel($originChannel)
            ->setPurchasedAt($this->immutableFromNullable($purchaseTransaction->getCreatedAt()) ?? new \DateTimeImmutable())
        ;

        $this->em->persist($giftCard);
        $this->em->persist($this->newHistory($giftCard, GiftCardHistory::ACTION_GENERATED, $purchaser, null, $purchaseTransaction));

        if (GiftCard::ORIGIN_BACKEND === $originChannel) {
            $this->em->persist($this->newHistory($giftCard, GiftCardHistory::ACTION_CREATED_FROM_BACKEND, null, null, $purchaseTransaction));
        }

        $this->em->flush();

        return $giftCard;
    }

    public function assignRecipient(GiftCard $giftCard, User $recipient, ?User $actorUser = null, ?Staff $actorStaff = null): GiftCard
    {
        $giftCard
            ->setRecipientUser($recipient)
            ->setAssignedAt(new \DateTimeImmutable())
        ;

        $this->em->persist($giftCard);
        $this->em->persist($this->newHistory($giftCard, GiftCardHistory::ACTION_SHARED_MANUALLY, $actorUser, $actorStaff));
        $this->em->flush();

        return $giftCard;
    }

    public function redeem(
        GiftCard $giftCard,
        User $recipient,
        Transaction $redemptionTransaction,
        ?User $actorUser = null,
        ?Staff $actorStaff = null
    ): GiftCard {
        if (GiftCard::STATUS_GENERATED !== $giftCard->getStatus()) {
            throw new \LogicException('Solo se pueden canjear gift cards en estado generated.');
        }

        if (null !== $giftCard->getGiftExpiresAt() && new \DateTimeImmutable() > $giftCard->getGiftExpiresAt()) {
            throw new \LogicException('La gift card ya se encuentra expirada.');
        }

        $expiresAt = $redemptionTransaction->getExpirationAt();

        $giftCard
            ->setRecipientUser($recipient)
            ->setRedemptionTransaction($redemptionTransaction)
            ->setStatus(GiftCard::STATUS_REDEEMED)
            ->setRedeemedAt(new \DateTimeImmutable())
            ->setGiftExpiresAt(
                $expiresAt instanceof \DateTimeImmutable
                    ? $expiresAt
                    : ($expiresAt !== null ? \DateTimeImmutable::createFromInterface($expiresAt) : null)
            )
        ;

        $this->em->persist($giftCard);
        $this->em->persist($this->newHistory($giftCard, GiftCardHistory::ACTION_REDEEMED, $actorUser, $actorStaff, $redemptionTransaction));
        $this->em->flush();

        return $giftCard;
    }

    public function redeemByCodeForUser(string $code, User $recipient, TransactionService $transactionService): GiftCard
    {
        $normalizedCode = strtoupper(trim($code));
        if ('' === $normalizedCode) {
            throw new \InvalidArgumentException('Debes ingresar un codigo de regalo.');
        }

        $giftCard = $this->giftCardRepository->findOneByCode($normalizedCode);
        if (null === $giftCard) {
            throw new \LogicException('El codigo de regalo es invalido.');
        }

        if (GiftCard::STATUS_REDEEMED === $giftCard->getStatus()) {
            throw new \LogicException('Este codigo de regalo ya fue canjeado.');
        }

        if (GiftCard::STATUS_CANCELLED === $giftCard->getStatus()) {
            throw new \LogicException('Este codigo de regalo fue cancelado.');
        }

        if (GiftCard::STATUS_EXPIRED === $giftCard->getStatus()) {
            throw new \LogicException('Este codigo de regalo esta expirado.');
        }

        if (null !== $giftCard->getGiftExpiresAt() && new \DateTimeImmutable() > $giftCard->getGiftExpiresAt()) {
            throw new \LogicException('Este codigo de regalo esta expirado.');
        }

        $assignedRecipient = $giftCard->getRecipientUser();
        if (null !== $assignedRecipient && $assignedRecipient->getId() !== $recipient->getId()) {
            throw new \LogicException('Este codigo de regalo esta asignado a otro usuario.');
        }

        $redemptionTransaction = $transactionService->create(
            $giftCard->getPackage(),
            Transaction::CHARGE_METHOD_GIFT,
            $recipient,
            $recipient->getBranchOffice()
        );

        $daysExpiry = max(0, (int) $giftCard->getPackageDaysExpirySnapshot());
        $expirationAt = (new \DateTime())->add(new \DateInterval(sprintf('P%dD', $daysExpiry)));

        $redemptionTransaction
            ->setPackageDaysExpiry($daysExpiry)
            ->setTotal('0.00')
            ->setStatus(Transaction::STATUS_PAID)
            ->setExpirationAt($expirationAt)
            ->setHaveSessionsAvailable(true)
        ;

        $this->em->persist($redemptionTransaction);
        $this->em->flush();

        return $this->redeem($giftCard, $recipient, $redemptionTransaction, $recipient, null);
    }

    public function cancel(GiftCard $giftCard, ?string $reason = null, ?User $actorUser = null, ?Staff $actorStaff = null): GiftCard
    {
        if (GiftCard::STATUS_REDEEMED === $giftCard->getStatus()) {
            throw new \LogicException('No se puede cancelar una gift card ya canjeada.');
        }

        if (GiftCard::STATUS_CANCELLED === $giftCard->getStatus()) {
            throw new \LogicException('La gift card ya esta cancelada.');
        }

        $giftCard
            ->setStatus(GiftCard::STATUS_CANCELLED)
            ->setCancelledAt(new \DateTimeImmutable())
            ->setCancellationReason(null !== $reason ? trim($reason) : null)
        ;

        $history = $this->newHistory($giftCard, GiftCardHistory::ACTION_CANCELLED, $actorUser, $actorStaff);
        $history->setNotes($giftCard->getCancellationReason());

        $this->em->persist($giftCard);
        $this->em->persist($history);
        $this->em->flush();

        return $giftCard;
    }

    public function registerResend(GiftCard $giftCard, ?User $actorUser = null, ?Staff $actorStaff = null): GiftCard
    {
        if (GiftCard::STATUS_REDEEMED === $giftCard->getStatus()) {
            throw new \LogicException('No se puede reenviar una gift card ya canjeada.');
        }

        if (GiftCard::STATUS_CANCELLED === $giftCard->getStatus()) {
            throw new \LogicException('No se puede reenviar una gift card cancelada.');
        }

        if (GiftCard::STATUS_EXPIRED === $giftCard->getStatus()) {
            throw new \LogicException('No se puede reenviar una gift card expirada.');
        }

        $history = $this->newHistory($giftCard, GiftCardHistory::ACTION_RESENT, $actorUser, $actorStaff);

        $this->em->persist($history);
        $this->em->flush();

        return $giftCard;
    }

    private function generateUniqueCode(int $length = 12): string
    {
        $length = max(8, $length);
        $tries = 0;

        do {
            ++$tries;
            $code = strtoupper(substr(bin2hex(random_bytes(16)), 0, $length));
        } while (null !== $this->giftCardRepository->findOneByCode($code) && $tries < 10);

        if (null !== $this->giftCardRepository->findOneByCode($code)) {
            throw new \RuntimeException('No fue posible generar un codigo unico para la gift card.');
        }

        return $code;
    }

    private function newHistory(
        GiftCard $giftCard,
        string $action,
        ?User $actorUser = null,
        ?Staff $actorStaff = null,
        ?Transaction $transaction = null
    ): GiftCardHistory {
        $history = new GiftCardHistory();
        $history
            ->setGiftCard($giftCard)
            ->setAction($action)
            ->setActorUser($actorUser)
            ->setActorStaff($actorStaff)
            ->setTransaction($transaction)
        ;

        return $history;
    }

    private function immutableFromNullable(?\DateTimeInterface $date): ?\DateTimeImmutable
    {
        if (null === $date) {
            return null;
        }

        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        return \DateTimeImmutable::createFromMutable($date);
    }
}
