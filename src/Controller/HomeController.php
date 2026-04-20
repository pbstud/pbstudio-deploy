<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BranchOfficeRepository;
use App\Repository\StaffRepository;
use App\Service\HomeContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'homepage', methods: ['GET'])]
    public function index(
        StaffRepository $staffRepository,
        BranchOfficeRepository $branchOfficeRepository,
        HomeContentService $homeContentService,
    ): Response
    {
        $instructors = $staffRepository->getAllActiveInstructors();
        shuffle($instructors);

        return $this->render('home/index.html.twig', [
            'instructors'   => $instructors,
            'branchOffices' => $branchOfficeRepository->getPublic(),
            'homeContent'   => $homeContentService->getTemplateData(),
        ]);
    }
}
