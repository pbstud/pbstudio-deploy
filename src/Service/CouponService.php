<?php
/**
 * Created by.
 *
 * User: JCHR <car.chr@gmail.com>
 * Date: 2020-11-07
 * Time: 16:14
 */

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Entity\CouponHistory;
use App\Entity\Package;
use App\Entity\Transaction;
use App\Repository\CouponRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Coupon Service.
 *
 * @author JCHR <car.chr@gmail.com>
 */
readonly class CouponService
{
    /**
     * Coupon Service constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param TranslatorInterface    $translator
     * @param CouponRepository       $couponRepository
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private CouponRepository $couponRepository
    ) {
    }

    /**
     * @param Package $package
     * @param string  $code
     *
     * @return Coupon|null
     */
    public function validate(Package $package, string $code): ?Coupon
    {
        $coupon = $this->couponRepository->getActiveByCode($code);

        // Coupon not found.
        if (!$coupon) {
            return null;
        }

        // Validate coupon apply in special price.
        if ($package->isSpecialPriceActiveAt(new \DateTime()) && !$coupon->isApplySpecialPrice()) {
            return null;
        }

        // Validate has package in coupon.
        if (!$coupon->hasPackage($package) && $coupon->getPackages()->count() > 0) {
            return null;
        }

        return $coupon;
    }

    /**
     * Valida un cupón y devuelve el motivo de rechazo cuando aplica.
     *
     * @return array{coupon: ?Coupon, reason: ?string}
     */
    public function validateWithReason(Package $package, string $code): array
    {
        // Obtener el cupón sin filtrar (para poder reportar el motivo exacto)
        $coupon = $this->couponRepository->getByCode($code);

        if (!$coupon) {
            return ['coupon' => null, 'reason' => 'error.invalid_coupon'];
        }

        $now = new \DateTimeImmutable('now');

        if ($coupon->getDateStart() && $coupon->getDateStart() > $now) {
            return ['coupon' => null, 'reason' => 'error.coupon_not_active'];
        }

        if ($coupon->getDateEnd() && $coupon->getDateEnd() < $now) {
            return ['coupon' => null, 'reason' => 'error.coupon_expired'];
        }

        // Validar que el cupón no haya excedido su límite de usos
        if ($coupon->getUsed() >= $coupon->getUsesTotal()) {
            return ['coupon' => null, 'reason' => 'error.coupon_limit_exceeded'];
        }

        // Validate coupon apply in special price.
        if ($package->isSpecialPriceActiveAt(new \DateTime()) && !$coupon->isApplySpecialPrice()) {
            return ['coupon' => null, 'reason' => 'error.invalid_coupon'];
        }

        // Validate has package in coupon.
        if (!$coupon->hasPackage($package) && $coupon->getPackages()->count() > 0) {
            return ['coupon' => null, 'reason' => 'error.coupon_not_valid_for_package'];
        }

        return ['coupon' => $coupon, 'reason' => null];
    }

    /**
     * @param Transaction $transaction
     * @param string|null $code
     *
     * @throws \Exception
     */
    public function apply(Transaction $transaction, string $code = null): void
    {
        if (empty($code)) {
            return;
        }

        $validation = $this->validateWithReason($transaction->getPackage(), $code);
        $coupon = $validation['coupon'];
        $reason = $validation['reason'];

        if (!$coupon) {
            $message = $reason ? $this->translator->trans($reason) : $this->translator->trans('error.invalid_coupon');
            throw new \Exception($message);
        }

        $transaction
            ->setCouponDiscount($coupon->getDiscount())
            ->setCoupon($coupon)
        ;

        $transaction->calculateTotal();
        $this->entityManager->persist($transaction);
    }

    /**
     * Registra el uso del cupón en el historial y sincroniza el contador.
     *
     * @param Transaction $transaction
     *
     * @throws \Exception si el cupón ha excedido su límite
     */
    public function addHistory(Transaction $transaction): void
    {
        /** @var Coupon $coupon */
        $coupon = $transaction->getCoupon();

        // Validación defensiva: evitar exceder límite de usos
        if ($coupon->getUsed() >= $coupon->getUsesTotal()) {
            throw new \Exception(
                sprintf(
                    'Cupón #%d ya ha excedido su límite de usos (%d/%d)',
                    $coupon->getId(),
                    $coupon->getUsed(),
                    $coupon->getUsesTotal()
                )
            );
        }

        // Incrementar contador
        $coupon->incrementUsed();

        // Crear registro de historial
        $history = new CouponHistory();
        $history
            ->setDiscount($coupon->getDiscount())
            ->setCoupon($coupon)
            ->setTransaction($transaction)
            ->setUser($transaction->getUser())
        ;

        // Agregar al historial de cupón
        $coupon->addCouponHistory($history);

        // Persistir y sincronizar
        $this->entityManager->persist($coupon);
        $this->entityManager->persist($history);
        $this->entityManager->flush();
    }
}
