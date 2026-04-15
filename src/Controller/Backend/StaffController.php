<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Staff;
use App\Form\Backend\StaffCreateType;
use App\Form\Backend\StaffPasswordType;
use App\Repository\StaffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

#[IsGranted('ROLE_ADMIN')]
#[Route('/backend/staff')]
class StaffController extends AbstractController
{
    #[Route('/', name: 'backend_staff', methods: ['GET'])]
    public function index(
        PaginatorInterface $paginator,
        StaffRepository $staffRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $entities = $staffRepository->getAllStaff();

        $pagination = $paginator->paginate(
            $entities,
            $page,
            Staff::NUMBER_OF_ITEMS
        );

        return $this->render('backend/staff/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'backend_staff_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        $staff = new Staff();
        $form = $this->createForm(StaffCreateType::class, $staff, [
            'permissions' => $this->getPermissions(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $passwordHasher->hashPassword($staff, $staff->getPassword());
            $staff->setPassword($password);

            $em->persist($staff);
            $em->flush();

            $this->addFlash('success', 'El Staff ha sido creado.');

            return $this->redirectToRoute('backend_staff_edit', [
                'id' => $staff->getId(),
            ]);
        }

        return $this->render('backend/staff/new.html.twig', [
            'staff' => $staff,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_staff_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Staff $staff, EntityManagerInterface $em): Response
    {
        $deleteForm = $this->createDeleteForm($staff);
        $editForm = $this->createForm(StaffCreateType::class, $staff, [
            'permissions' => $this->getPermissions(),
        ]);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            $this->addFlash('success', 'El Staff ha sido actualizado.');

            return $this->redirectToRoute('backend_staff_edit', [
                'id' => $staff->getId(),
            ]);
        }

        return $this->render('backend/staff/edit.html.twig', [
            'staff' => $staff,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ]);
    }

    #[Route('/{id}', name: 'backend_staff_delete', methods: ['DELETE'])]
    public function delete(
        Request $request,
        Staff $staff,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createDeleteForm($staff);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->remove($staff);
            $em->flush();

            $this->addFlash('success', 'El Staff ha sido eliminado.');
        }

        return $this->redirectToRoute('backend_staff');
    }

    #[Route('/{id}/password', name: 'backend_staff_password', methods: ['GET', 'POST'])]
    public function password(
        Request $request,
        Staff $staff,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createForm(StaffPasswordType::class, $staff, [
            'action' => $this->generateUrl('backend_staff_password', [
                'id' => $staff->getId(),
            ]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $passwordHasher->hashPassword($staff, $staff->getPassword());
            $staff->setPassword($password);

            $em->flush();

            return new JsonResponse();
        }

        return $this->render('backend/staff/password.html.twig', [
            'staff' => $staff,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @return array
     */
    private function getPermissions(): array
    {
        $permissionsPath = sprintf('%s/config/permissions.yaml', $this->getParameter('kernel.project_dir'));
        $permissions = Yaml::parseFile($permissionsPath);
        $data = [];

        if ($permissions['sections']) {
            foreach ($permissions['sections'] as $section) {
                foreach ($section['actions'] as $action) {
                    $data[$section['label']][$action['label']] = $action['route'];
                }
            }
        }

        return $data;
    }

    /**
     * Creates a form to delete a staff entity.
     *
     * @param Staff $staff The staff entity
     *
     * @return FormInterface
     */
    private function createDeleteForm(Staff $staff): FormInterface
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('backend_staff_delete', [
                'id' => $staff->getId(),
            ]))
            ->setMethod('DELETE')
            ->getForm()
        ;
    }
}
