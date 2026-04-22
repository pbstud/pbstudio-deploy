<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Package;
use App\Form\Backend\PackageType;
use App\Repository\PackageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/package')]
class PackageController extends AbstractController
{
    #[Route('/', name: 'backend_package', methods: ['GET'])]
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        PackageRepository $packageRepository,
        #[MapQueryParameter] array $filters = [],
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $packages = $packageRepository->findWithFilters($filters, $request->query->has('sort'));

        $pagination = $paginator->paginate($packages, $page, Package::NUMBER_OF_ITEMS);

        return $this->render('backend/package/index.html.twig', [
            'pagination' => $pagination,
            'filters' => $filters,
            'filter_types' => Package::typeChoices(),
            'filter_status' => Package::statusChoices(),
            'filter_public' => Package::publicChoices(),
        ]);
    }

    #[Route('/new', name: 'backend_package_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $package = new Package();
        $form = $this->createForm(PackageType::class, $package);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($package);
            $em->flush();

            $this->addFlash('success', 'El Paquete ha sido creado.');

            return $this->redirectToRoute('backend_package_edit', [
                'id' => $package->getId(),
            ]);
        }

        return $this->render('backend/package/new.html.twig', [
            'package' => $package,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_package_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Package $package, EntityManagerInterface $em): Response
    {
        $editForm = $this->createForm(PackageType::class, $package);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();
            $this->addFlash('success', 'El Paquete ha sido actualizado.');

            return $this->redirectToRoute('backend_package_edit', [
                'id' => $package->getId(),
            ]);
        }

        return $this->render('backend/package/edit.html.twig', [
            'package' => $package,
            'form' => $editForm,
        ]);
    }

    #[Route('/export', name: 'backend_package_export', methods: ['GET'])]
    public function export(
        Request $request,
        PackageRepository $packageRepository,
        #[MapQueryParameter] array $filters = [],
    ): Response {
        $packages = $packageRepository->findWithFilters($filters);

        $filename = sprintf('Paquetes_%s.xlsx', date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('packages_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Paquetes');

            // Header row
            $writer->addRow(Row::fromValues([
                'ID',
                'Número de Clases',
                'Descripción',
                'Precio',
                'Precio Especial',
                'Modalidad',
                'Vigencia (días)',
                'Para Nuevos Usuarios',
                'Público',
                'Estado',
            ]));

            // Data rows
            foreach ($packages as $package) {
                $numClases = $package->isUnlimited() ? '(∞) Ilimitadas' : $package->getTotalClasses() . ' clase(s)';
                $tipo = 'i' === $package->getType() ? 'Individual' : 'Grupal';
                $precioEspecial = $package->getSpecialPrice() ? '$' . number_format($package->getSpecialPrice(), 2) : '--';
                $nuevoUsuario = $package->isNewUser() ? 'Sí' : 'No';
                $publico = $package->isPublic() ? 'Sí' : 'No';
                $estado = $package->isActive() ? 'Activo' : 'Inactivo';

                $writer->addRow(Row::fromValues([
                    $package->getId(),
                    $numClases,
                    $package->getAltText(),
                    '$' . number_format($package->getAmount(), 2),
                    $precioEspecial,
                    $tipo,
                    $package->getDaysExpiry(),
                    $nuevoUsuario,
                    $publico,
                    $estado,
                ]));
            }

            // Set column widths
            $sheet->setColumnWidth(8, 1); // ID
            $sheet->setColumnWidth(18, 2); // Número de Clases
            $sheet->setColumnWidth(25, 3); // Descripción
            $sheet->setColumnWidth(12, 4); // Precio
            $sheet->setColumnWidth(15, 5); // Precio Especial
            $sheet->setColumnWidth(12, 6); // Modalidad
            $sheet->setColumnWidth(15, 7); // Vigencia (días)
            $sheet->setColumnWidth(18, 8); // Para Nuevos Usuarios
            $sheet->setColumnWidth(12, 9); // Público
            $sheet->setColumnWidth(12, 10); // Estado

            $writer->close();

            $response = new BinaryFileResponse($tmpFile);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            return $response;
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Error generando archivo: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
