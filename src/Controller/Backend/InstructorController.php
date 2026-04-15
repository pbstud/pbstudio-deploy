<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Staff;
use App\Form\Backend\InstructorType;
use App\Repository\StaffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/instructor')]
class InstructorController extends AbstractController
{
    #[Route('/', name: 'backend_instructor', methods: ['GET'])]
    public function index(
        PaginatorInterface $paginator,
        StaffRepository $staffRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $instructors = $staffRepository->getAllInstructors();

        $pagination = $paginator->paginate($instructors, $page, Staff::NUMBER_OF_ITEMS);
        $instructorPhotoAvailable = $this->buildInstructorPhotoAvailability($pagination->getItems());

        return $this->render('backend/instructor/index.html.twig', [
            'pagination' => $pagination,
            'instructorPhotoAvailable' => $instructorPhotoAvailable,
        ]);
    }

    #[Route('/new', name: 'backend_instructor_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): Response {
        $instructor = new Staff();
        $form = $this->createForm(InstructorType::class, $instructor);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $photoFile = $instructor->getProfile()?->getPhotoFile();
            $photoDeleteRequested = false;

            if ($form->has('profile') && $form->get('profile')->has('photoFile')) {
                $photoField = $form->get('profile')->get('photoFile');
                if ($photoField->has('delete')) {
                    $photoDeleteRequested = (bool) $photoField->get('delete')->getData();
                }
            }

            $logger->info('[InstructorPhoto] new form submitted', [
                'valid' => $form->isValid(),
                'username' => $instructor->getUsername(),
                'photo_uploaded' => null !== $photoFile,
                'photo_delete_requested' => $photoDeleteRequested,
                'photo_size_bytes' => $photoFile?->getSize(),
                'photo_mime' => $photoFile?->getMimeType(),
                'stored_photo_before_flush' => $instructor->getProfile()?->getPhoto(),
            ]);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $instructor->setPermissions([
                'backend_session',
            ]);

            $encoded = $passwordHasher->hashPassword($instructor, $instructor->getPassword());

            $instructor
                ->setPassword($encoded)
                ->setRoles(['ROLE_INSTRUCTOR'])
            ;

            $em->persist($instructor);
            $em->flush();

            $logger->info('[InstructorPhoto] new instructor persisted', [
                'instructor_id' => $instructor->getId(),
                'username' => $instructor->getUsername(),
                'stored_photo_after_flush' => $instructor->getProfile()?->getPhoto(),
            ]);

            $this->addFlash('success', 'El Instructor ha sido creado.');

            return $this->redirectToRoute('backend_instructor_edit', [
                'id' => $instructor->getId(),
            ]);
        }

        return $this->render('backend/instructor/new.html.twig', [
            'instructor' => $instructor,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_instructor_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Staff $instructor, EntityManagerInterface $em, LoggerInterface $logger): Response
    {
        $editForm = $this->createForm(InstructorType::class, $instructor);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted()) {
            $photoFile = $instructor->getProfile()?->getPhotoFile();
            $photoDeleteRequested = false;

            if ($editForm->has('profile') && $editForm->get('profile')->has('photoFile')) {
                $photoField = $editForm->get('profile')->get('photoFile');
                if ($photoField->has('delete')) {
                    $photoDeleteRequested = (bool) $photoField->get('delete')->getData();
                }
            }

            $logger->info('[InstructorPhoto] edit form submitted', [
                'instructor_id' => $instructor->getId(),
                'valid' => $editForm->isValid(),
                'photo_uploaded' => null !== $photoFile,
                'photo_delete_requested' => $photoDeleteRequested,
                'photo_size_bytes' => $photoFile?->getSize(),
                'photo_mime' => $photoFile?->getMimeType(),
                'stored_photo_before_flush' => $instructor->getProfile()?->getPhoto(),
            ]);
        }

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            $logger->info('[InstructorPhoto] edit instructor persisted', [
                'instructor_id' => $instructor->getId(),
                'stored_photo_after_flush' => $instructor->getProfile()?->getPhoto(),
            ]);

            $this->addFlash('success', 'El Instructor ha sido actualizado.');

            return $this->redirectToRoute('backend_instructor_edit', [
                'id' => $instructor->getId(),
            ]);
        }

        return $this->render('backend/instructor/edit.html.twig', [
            'instructor' => $instructor,
            'form' => $editForm,
            'instructorHasPhotoFile' => $this->hasInstructorPhotoFile($instructor),
        ]);
    }

    /**
     * @param iterable<Staff> $instructors
     *
     * @return array<int, bool>
     */
    private function buildInstructorPhotoAvailability(iterable $instructors): array
    {
        $availability = [];

        foreach ($instructors as $instructor) {
            $availability[$instructor->getId()] = $this->hasInstructorPhotoFile($instructor);
        }

        return $availability;
    }

    private function hasInstructorPhotoFile(Staff $instructor): bool
    {
        $photo = $instructor->getProfile()?->getPhoto();

        if (!$photo) {
            return false;
        }

        $photoPath = sprintf(
            '%s/public/media/uploads/instructors/%s',
            $this->getParameter('kernel.project_dir'),
            $photo,
        );

        return is_file($photoPath);
    }

    #[Route('/{id}', name: 'backend_instructor_delete', methods: ['DELETE'])]
    public function delete(Request $request, Staff $instructor, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$instructor->getId(), $request->request->get('_token'))) {
            $instructor->setDeleted(true);

            $em->persist($instructor);
            $em->flush();

            $this->addFlash('success', 'El Instructor ha sido borrado.');
        } else {
            $this->addFlash('danger', 'Token invalido, intente nuevamente.');
        }

        return $this->redirectToRoute('backend_instructor');
    }
}
