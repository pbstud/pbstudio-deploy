<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Discipline;
use App\Form\Backend\DisciplineType;
use App\Repository\DisciplineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discipline Controller.
 */
#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('backend/discipline')]
class DisciplineController extends AbstractController
{
    #[Route('/', name: 'backend_discipline', methods: ['GET'])]
    public function index(
        PaginatorInterface $paginator,
        DisciplineRepository $disciplineRepository,
        #[MapQueryParameter] int $page = 1
    ): Response {
        $pagination = $paginator->paginate(
            $disciplineRepository->paginate(),
            $page,
            Discipline::NUMBER_OF_ITEMS,
            [
                PaginatorInterface::DEFAULT_SORT_FIELD_NAME => 'd.id',
                PaginatorInterface::DEFAULT_SORT_DIRECTION => 'desc',
            ]
        );

        $paginationFilter = [
            'fields' => [
                'd.name' => 'filter.name',
            ],
            'reset' => $this->generateUrl('backend_discipline'),
        ];

        return $this->render('backend/discipline/index.html.twig', [
            'pagination' => $pagination,
            'pagination_filter' => $paginationFilter,
        ]);
    }

    #[Route('/new', name: 'backend_discipline_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $discipline = new Discipline();
        $form = $this->createForm(DisciplineType::class, $discipline);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($discipline);
            $em->flush();

            $this->addFlash('success', 'La Disciplina ha sido creada.');

            return $this->redirectToRoute('backend_discipline_edit', [
                'id' => $discipline->getId(),
            ]);
        }

        return $this->render('backend/discipline/new.html.twig', [
            'discipline' => $discipline,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_discipline_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Discipline $discipline, EntityManagerInterface $em): Response
    {
        $editForm = $this->createForm(DisciplineType::class, $discipline);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            $this->addFlash('success', 'La Disciplina ha sido actualizada.');

            return $this->redirectToRoute('backend_discipline_edit', [
                'id' => $discipline->getId(),
            ]);
        }

        return $this->render('backend/discipline/edit.html.twig', [
            'discipline' => $discipline,
            'form' => $editForm,
        ]);
    }
}
