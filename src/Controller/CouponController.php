<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Package;
use App\Service\CouponService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/coupon')]
class CouponController extends AbstractController
{
    #[Route('/{id}/validate', name: 'coupon_validate')]
    public function validate(
        Request $request,
        Package $package,
        CouponService $couponService,
        TranslatorInterface $translator,
    ): Response {
        $code = $request->query->get('coupon');
        $coupon = null;
        $error = null;

        if (!empty($code)) {
            $validation = $couponService->validateWithReason($package, $code);
            $coupon = $validation['coupon'];
            $error = $validation['reason'];
        }

        $result = [
            'success' => null !== $coupon,
            'error' => $error ? $translator->trans($error) : null,
        ];

        if ($coupon) {
            // Validar que el cupón no haya excedido su límite de usos
            if ($coupon->getUsed() >= $coupon->getUsesTotal()) {
                $result['success'] = false;
                $result['error'] = $translator->trans('error.coupon_limit_exceeded');
                return $this->json($result);
            }

            $price = (float) $package->getTotalPrice();
            $discount = (float) $coupon->getDiscount();
            $totalDiscount = round($price - (($price * $discount) / 100), 2);

            $result['data'] = [
                'discount' => number_format($discount, 2).' %',
                'total' => '$'.number_format($totalDiscount, 2),
                'usesRemaining' => $coupon->getUsesTotal() - $coupon->getUsed(),
            ];
        }

        return $this->json($result);
    }
}
