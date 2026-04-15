<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HandlerContactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contacto')]
class ContactController extends AbstractController
{
    #[Route('/', name: 'contact', methods: ['GET'])]
    public function index(HandlerContactService $contactService): Response
    {
        $contactForm = $contactService->createContactForm();

        return $this->render('contact/index.html.twig', [
            'form' => $contactForm->createView(),
        ]);
    }

    #[Route('/enviar', name: 'contact_send', methods: ['POST'])]
    public function send(HandlerContactService $contactService): RedirectResponse
    {
        if ($contactService->processForm()) {
            $this->addFlash('success', '¡El mensaje de contacto ha sido enviado!');
        } else {
            $this->addFlash('danger', 'Lo sentimos, hubo un error al enviar su mensaje, intente nuevamente.');
        }

        return $this->redirectToRoute('contact');
    }
}
