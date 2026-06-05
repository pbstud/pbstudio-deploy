<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BranchOfficeRepository;
use App\Repository\DisciplineRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Service\HomeContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Finder\Finder;

class HomeController extends AbstractController
{
    #[Route('/', name: 'homepage', methods: ['GET'])]
    public function index(
        StaffRepository $staffRepository,
        BranchOfficeRepository $branchOfficeRepository,
        HomeContentService $homeContentService,
        SessionRepository $sessionRepository,
        DisciplineRepository $disciplineRepository,
    ): Response
    {
        $instructors = $staffRepository->getAllActiveInstructors();
        shuffle($instructors);

        $firstBranch = $branchOfficeRepository->getFirstPublic();

        // Cargar imágenes de la carpeta gallery para la sección de galería
        $galleryDir = $this->getParameter('kernel.project_dir') . '/public/media/uploads/gallery';
        $galleryImages = [];
        if (is_dir($galleryDir)) {
            $finder = new Finder();
            $finder->files()->in($galleryDir)->name('/\.(webp|jpg|jpeg|png)$/i')->sortByName();
            foreach ($finder as $file) {
                $galleryImages[] = '/media/uploads/gallery/' . $file->getFilename();
            }
        }

        // Cargar FAQ desde archivo JSON
        $faqFile = $this->getParameter('kernel.project_dir') . '/var/data/faq_items.json';
        $faqItems = [];
        if (is_file($faqFile)) {
            $decoded = json_decode((string) file_get_contents($faqFile), true);
            $faqItems = is_array($decoded) ? $decoded : [];
        }

        // Cargar features (info boxes bajo el hero) desde archivo JSON
        $featFile = $this->getParameter('kernel.project_dir') . '/var/data/feature_items.json';
        $featureItems = [];
        if (is_file($featFile)) {
            $decoded = json_decode((string) file_get_contents($featFile), true);
            $featureItems = is_array($decoded) ? $decoded : [];
        }

        return $this->render('home/index.html.twig', [
            'instructors'       => $instructors,
            'branchOffices'     => $branchOfficeRepository->getPublic(),
            'homeContent'       => $homeContentService->getTemplateData(),
            'upcomingSessions'  => $sessionRepository->findNextUpcoming(3),
            'disciplines'       => $disciplineRepository->getAllActives(),
            'galleryImages'     => $galleryImages,
            'faqItems'          => $faqItems,
            'featureItems'      => $featureItems,
            'firstBranchSlug'   => $firstBranch?->getSlug() ?? '',
            // Coordenadas exactas por ID de sucursal (evita geocodificación Nominatim)
            'branchCoords'     => [
                1 => [
                    'lat'      => 19.3673819,
                    'lng'      => -99.2676045,
                    'embedSrc' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3764.0459954668245!2d-99.26987428510839!3d19.367160986921522!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x85d200cff90579b1%3A0x9e1cf9e5d5e1edfe!2sP%26B%20Studio!5e0!3m2!1ses-419!2smx!4v1593362959511!5m2!1ses-419!2smx',
                ], // Santa Fe — Infiniti Center
                2 => [
                    'lat'      => 19.3927248,
                    'lng'      => -99.2808515,
                    'embedSrc' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d957!2d-99.2808515!3d19.3927248!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x85d207a31d7777c3%3A0x94f5fa929ee20f3a!2sCenttral%20Interlomas!5e0!3m2!1ses-419!2smx!4v1748400000000!5m2!1ses-419!2smx',
                ], // Centtral Interlomas
            ],
        ]);
    }
}
