<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\StaffRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StaffController extends AbstractController
{
    #[Route('/instructores', name: 'instructors', methods: ['GET'])]
    public function index(StaffRepository $staffRepository): Response
    {
        $instructors = $staffRepository->getAllActiveInstructors();

        return $this->render('staff/index.html.twig', [
            'instructors' => $instructors,
            'instructorPhotoAvailable' => $this->buildInstructorPhotoAvailability($instructors),
        ]);
    }

    private function buildInstructorPhotoAvailability(iterable $instructors): array
    {
        $availability = [];

        foreach ($instructors as $instructor) {
            $id = $instructor->getId();
            if (null === $id) {
                continue;
            }

            $photo = $instructor->getProfile()?->getPhoto();
            if (!$photo) {
                $availability[$id] = false;
                continue;
            }

            $photoPath = sprintf(
                '%s/public/media/uploads/instructors/%s',
                dirname(__DIR__, 2),
                $photo,
            );

            $availability[$id] = is_file($photoPath);
        }

        return $availability;
    }
}
