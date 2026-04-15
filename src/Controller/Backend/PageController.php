<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Post;
use App\Form\Backend\PageCreateType;
use App\Form\Backend\PageEditType;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/page')]
class PageController extends AbstractController
{
    #[Route('/', name: 'backend_page', methods: ['GET'])]
    public function index(
        PaginatorInterface $paginator,
        PostRepository $postRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $pages = $postRepository->findBy([
            'type' => Post::TYPE_STATIC,
        ], [
            'title' => 'ASC',
        ]);

        $pagination = $paginator->paginate($pages, $page, Post::NUMBER_OF_ITEMS);

        return $this->render('backend/page/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'backend_page_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $page = new Post();
        $form = $this->createForm(PageCreateType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $page->setType(Post::TYPE_STATIC);

            $em->persist($page);
            $em->flush();

            $this->addFlash('success', 'La Página ha sido creada.');

            return $this->redirectToRoute('backend_page_edit', [
                'id' => $page->getId(),
            ]);
        }

        return $this->render('backend/page/new.html.twig', [
            'page' => $page,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_page_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Post $page, EntityManagerInterface $em): Response
    {
        $deleteForm = $this->createDeleteForm($page);
        $editForm = $this->createForm(PageEditType::class, $page);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            $this->addFlash('success', 'La Página ha sido actualizada.');

            return $this->redirectToRoute('backend_page_edit', [
                'id' => $page->getId(),
            ]);
        }

        return $this->render('backend/page/edit.html.twig', [
            'page' => $page,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ]);
    }

    #[Route('/{id}', name: 'backend_page_delete', methods: ['DELETE'])]
    public function delete(Request $request, Post $page, EntityManagerInterface $em): Response
    {
        $form = $this->createDeleteForm($page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->remove($page);
            $em->flush();

            $this->addFlash('success', 'La Página ha sido eliminada.');
        }

        return $this->redirectToRoute('backend_page');
    }

    /**
     * Creates a form to delete a page entity.
     *
     * @param Post $page The page entity
     *
     * @return FormInterface
     */
    private function createDeleteForm(Post $page): FormInterface
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('backend_page_delete', [
                'id' => $page->getId(),
            ]))
            ->setMethod('DELETE')
            ->getForm()
        ;
    }
}
