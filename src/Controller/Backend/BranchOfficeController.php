<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\BranchOffice;
use App\Form\Backend\BranchOfficeType;
use App\Repository\BranchOfficeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/branchoffice')]
class BranchOfficeController extends AbstractController
{
    #[Route('/', name: 'backend_branchoffice', methods: ['GET'])]
    public function index(
        PaginatorInterface $paginator,
        BranchOfficeRepository $branchOfficeRepository,
        #[MapQueryParameter] int $page = 1
    ): Response {

        $pagination = $paginator->paginate(
            $branchOfficeRepository->getQueryAll(),
            $page,
            BranchOffice::NUMBER_OF_ITEMS,
        );

        return $this->render('backend/branch_office/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'backend_branchoffice_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $branchOffice = new BranchOffice();
        $form = $this->createForm(BranchOfficeType::class, $branchOffice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($branchOffice);
            $em->flush();

            $this->addFlash('success', 'La Sucursal ha sido creada.');

            return $this->redirectToEdit($branchOffice);
        }

        return $this->render('backend/branch_office/new.html.twig', [
            'branchOffice' => $branchOffice,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_branchoffice_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BranchOffice $branchOffice, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BranchOfficeType::class, $branchOffice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'La Sucursal ha sido actualizada.');

            return $this->redirectToEdit($branchOffice);
        }

        return $this->render('backend/branch_office/edit.html.twig', [
            'branchOffice' => $branchOffice,
            'form' => $form,
        ]);
    }

    private function redirectToEdit(BranchOffice $branchOffice): RedirectResponse
    {
        return $this->redirectToRoute('backend_branchoffice_edit', [
            'id' => $branchOffice->getId(),
        ], Response::HTTP_SEE_OTHER);
    }
}
