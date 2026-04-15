<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/checkout')]
class CheckoutController extends AbstractController
{
    #[Route('/success', name: 'checkout_success')]
    public function success(Request $request): Response
    {
        if (!$request->getSession()->has('transaction')) {
            return $this->redirectToRoute('homepage');
        }

        $request->getSession()->remove('transaction');

        return $this->render('checkout/success.html.twig');
    }
}
