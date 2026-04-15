<?php
/**
 * Created by.
 *
 * User: JCHR <car.chr@gmail.com>
 * Date: 2020-11-05
 * Time: 08:47
 */

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Coupon;
use App\Entity\Package;
use App\Form\Backend\CouponType;
use App\Repository\CouponRepository;
use App\Repository\PackageRepository;
use App\Repository\TransactionRepository;
use App\Service\CouponService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/coupon')]
class CouponController extends AbstractController
{
    #[Route('/', name: 'backend_coupon', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function index(
        PaginatorInterface $paginator,
        CouponRepository $couponRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $pagination = $paginator->paginate(
            $couponRepository->paginate(),
            $page,
            Coupon::NUMBER_OF_ITEMS,
            [
                PaginatorInterface::DEFAULT_SORT_FIELD_NAME => 'c.id',
                PaginatorInterface::DEFAULT_SORT_DIRECTION => 'desc',
            ]
        );

        $paginationFilter = [
            'fields' => [
                'c.name,c.code' => 'filter.name_code',
            ],
            'reset' => $this->generateUrl('backend_coupon'),
        ];

        return $this->render('backend/coupon/index.html.twig', [
            'pagination' => $pagination,
            'pagination_filter' => $paginationFilter,
        ]);
    }

    #[Route('/new', name: 'backend_coupon_new', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $coupon = new Coupon();
        $form = $this->createForm(CouponType::class, $coupon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($coupon);
            $em->flush();

            $this->addFlash('success', 'El cupón ha sido creado.');

            return $this->redirectToRoute('backend_coupon_edit', [
                'id' => $coupon->getId(),
            ]);
        }

        return $this->render('backend/coupon/new.html.twig', [
            'coupon' => $coupon,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/validate', name: 'backend_coupon_validate', methods: ['GET'])]
    public function validate(
        Request $request,
        TranslatorInterface $translator,
        PackageRepository $packageRepository,
        CouponService $couponService
    ): Response {
        $code = $request->query->get('coupon');
        $packageId = $request->query->getInt('package');

        /** @var Package $package */
        $package = $packageRepository->find($packageId);

        $validation = $couponService->validateWithReason($package, $code);
        $coupon = $validation['coupon'];
        $error = $validation['reason'];

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
            $priceDiscount = round(($price * $discount) / 100, 2);
            $totalDiscount = round($price - $priceDiscount, 2);

            $result['data'] = [
                'discount' => $priceDiscount,
                'percentaje' => sprintf('(%s%%)', number_format($discount, 2)),
                'total' => number_format($totalDiscount, 2),
                'usesRemaining' => $coupon->getUsesTotal() - $coupon->getUsed(),
            ];
        }

        return $this->json($result);
    }

    #[Route('/{id}', name: 'backend_coupon_show', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function show(Coupon $coupon, TransactionRepository $transactionRepository): Response
    {
        $transactions = $transactionRepository->getByCoupon($coupon);

        return $this->render('backend/coupon/show.html.twig', [
            'coupon' => $coupon,
            'transactions' => $transactions,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_coupon_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function edit(Request $request, Coupon $coupon, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CouponType::class, $coupon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'El cupón ha sido actualizado.');

            return $this->redirectToRoute('backend_coupon_edit', [
                'id' => $coupon->getId(),
            ]);
        }

        return $this->render('backend/coupon/edit.html.twig', [
            'coupon' => $coupon,
            'form' => $form->createView(),
        ]);
    }
}
