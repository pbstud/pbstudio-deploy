<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Package;
use App\Form\Backend\PackageType;
use App\Repository\BranchOfficeRepository;
use App\Repository\DisciplineRepository;
use App\Repository\PackageRepository;
use App\Repository\StaffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Psr\Log\LoggerInterface;
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
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

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
    public function new(
        Request $request,
        EntityManagerInterface $em,
        StaffRepository $staffRepository,
        DisciplineRepository $disciplineRepository,
        BranchOfficeRepository $branchOfficeRepository,
    ): Response
    {
        $package = new Package();
        $form = $this->createForm(
            PackageType::class,
            $package,
            $this->buildRestrictionFormOptions($package, $staffRepository, $disciplineRepository, $branchOfficeRepository)
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->applyRestrictionFormData($form, $package)) {
                return $this->render('backend/package/new.html.twig', [
                    'package' => $package,
                    'form' => $form,
                ]);
            }

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
    public function edit(
        Request $request,
        Package $package,
        EntityManagerInterface $em,
        StaffRepository $staffRepository,
        DisciplineRepository $disciplineRepository,
        BranchOfficeRepository $branchOfficeRepository,
    ): Response
    {
        $editForm = $this->createForm(
            PackageType::class,
            $package,
            $this->buildRestrictionFormOptions($package, $staffRepository, $disciplineRepository, $branchOfficeRepository)
        );
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            if (!$this->applyRestrictionFormData($editForm, $package)) {
                return $this->render('backend/package/edit.html.twig', [
                    'package' => $package,
                    'form' => $editForm,
                ]);
            }

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

    #[Route('/{id}/delete', name: 'backend_package_delete', methods: ['POST'])]
    public function delete(Request $request, Package $package, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_package_'.$package->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');

            return $this->redirectToRoute('backend_package_edit', ['id' => $package->getId()]);
        }

        $em->remove($package);
        $em->flush();

        $this->addFlash('success', 'El paquete ha sido eliminado.');

        return $this->redirectToRoute('backend_package');
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

    private function buildRestrictionFormOptions(
        Package $package,
        StaffRepository $staffRepository,
        DisciplineRepository $disciplineRepository,
        BranchOfficeRepository $branchOfficeRepository,
    ): array
    {
        $daysChoices = [
            'Domingo' => 0,
            'Lunes' => 1,
            'Martes' => 2,
            'Miercoles' => 3,
            'Jueves' => 4,
            'Viernes' => 5,
            'Sabado' => 6,
        ];

        $instructorChoices = [];
        foreach ($staffRepository->getAllActiveInstructors() as $instructor) {
            $profile = $instructor->getProfile();
            $fullName = trim(sprintf(
                '%s %s %s',
                (string) ($profile?->getFirstname() ?? ''),
                (string) ($profile?->getPaternalSurname() ?? ''),
                (string) ($profile?->getMaternalSurname() ?? '')
            ));

            if ('' === $fullName) {
                $fullName = sprintf('Instructor %d', $instructor->getId());
            }

            $label = $fullName ?: sprintf('Instructor #%d', $instructor->getId());
            $instructorChoices[$label] = $instructor->getId();
        }

        $disciplineChoices = [];
        foreach ($disciplineRepository->getAllActives() as $discipline) {
            $disciplineChoices[$discipline->getName()] = $discipline->getId();
        }

        $branchChoices = [];
        foreach ($branchOfficeRepository->getPublic() as $branch) {
            $branchChoices[$branch->getName()] = $branch->getId();
        }

        $options = [
            'restriction_hour_slots_selected' => $this->normalizeRestrictionHourSlotsForForm($package->getRestrictionHours()),
            'restriction_days_choices' => $daysChoices,
            'restriction_instructor_choices' => $instructorChoices,
            'restriction_discipline_choices' => $disciplineChoices,
            'restriction_branch_choices' => $branchChoices,
            'restriction_days_selected' => $this->normalizeSelectionValues($package->getRestrictionDays(), array_values($daysChoices)),
            'restriction_instructor_selected' => $this->normalizeSelectionValues($package->getRestrictionInstructorIds(), array_values($instructorChoices)),
            'restriction_discipline_selected' => $this->normalizeSelectionValues($package->getRestrictionDisciplineIds(), array_values($disciplineChoices)),
            'restriction_branch_selected' => $this->normalizeSelectionValues($package->getRestrictionBranchIds(), array_values($branchChoices)),
        ];

        $this->logger->debug('[PackageRestriction][FormOptionsBuilt]', [
            'package_id' => $package->getId(),
            'has_restrictions' => $package->isHasRestrictions(),
            'choice_counts' => [
                'hours' => count($options['restriction_hour_slots_selected']),
                'days' => count($daysChoices),
                'instructors' => count($instructorChoices),
                'disciplines' => count($disciplineChoices),
                'branches' => count($branchChoices),
            ],
            'selected_values' => [
                'hours' => $options['restriction_hour_slots_selected'],
                'days' => $options['restriction_days_selected'],
                'instructors' => $options['restriction_instructor_selected'],
                'disciplines' => $options['restriction_discipline_selected'],
                'branches' => $options['restriction_branch_selected'],
            ],
        ]);

        return $options;
    }

    private function applyRestrictionFormData($form, Package $package): bool
    {
        $specialPrice = (float) ($package->getSpecialPrice() ?? 0);
        $dateStart = $package->getSpecialPriceDateStart();
        $dateEnd = $package->getSpecialPriceDateEnd();

        if ($specialPrice <= 0 && (null !== $dateStart || null !== $dateEnd)) {
            $this->addFlash('error', 'No se puede guardar: hay fechas de descuento pero el campo "Precio especial" está vacío o es 0. Agrégalo primero.');

            return false;
        } elseif ($specialPrice <= 0) {
            $package->setSpecialPriceDateStart(null);
            $package->setSpecialPriceDateEnd(null);
        } elseif (null !== $dateStart && null !== $dateEnd && $dateStart > $dateEnd) {
            $this->addFlash('error', 'La fecha de inicio del descuento no puede ser mayor a la fecha final.');

            return false;
        }

        $rawHours = $form->get('restrictionHoursSelection')->getData();
        $rawDays = $form->get('restrictionDaysSelection')->getData();
        $rawInstructors = $form->get('restrictionInstructorIdsSelection')->getData();
        $rawDisciplines = $form->get('restrictionDisciplineIdsSelection')->getData();
        $rawBranches = $form->get('restrictionBranchIdsSelection')->getData();

        $hours = $this->normalizeRestrictionHourSlotsSelection($form->get('restrictionHoursSelection')->getData());
        $days = $this->normalizeSelectionValues($form->get('restrictionDaysSelection')->getData(), range(0, 6));
        $instructors = $this->normalizeSelectionValues($form->get('restrictionInstructorIdsSelection')->getData());
        $disciplines = $this->normalizeSelectionValues($form->get('restrictionDisciplineIdsSelection')->getData());
        $branches = $this->normalizeSelectionValues($form->get('restrictionBranchIdsSelection')->getData());

        $this->logger->debug('[PackageRestriction][ApplyFormDataStart]', [
            'package_id' => $package->getId(),
            'has_restrictions' => $package->isHasRestrictions(),
            'raw' => [
                'hours' => $rawHours,
                'days' => is_array($rawDays) ? array_values($rawDays) : $rawDays,
                'instructors' => is_array($rawInstructors) ? array_values($rawInstructors) : $rawInstructors,
                'disciplines' => is_array($rawDisciplines) ? array_values($rawDisciplines) : $rawDisciplines,
                'branches' => is_array($rawBranches) ? array_values($rawBranches) : $rawBranches,
            ],
            'normalized' => [
                'hours' => $hours,
                'days' => $days,
                'instructors' => $instructors,
                'disciplines' => $disciplines,
                'branches' => $branches,
            ],
        ]);

        $package->setRestrictionHours($hours);
        $package->setRestrictionDays($days);
        $package->setRestrictionInstructorIds($instructors);
        $package->setRestrictionDisciplineIds($disciplines);
        $package->setRestrictionBranchIds($branches);

        if (!$package->isHasRestrictions()) {
            $package->setRestrictionHours(null);
            $package->setRestrictionDays(null);
            $package->setRestrictionInstructorIds(null);
            $package->setRestrictionDisciplineIds(null);
            $package->setRestrictionBranchIds(null);

            $this->logger->info('[PackageRestriction][ApplyFormDataCleared]', [
                'package_id' => $package->getId(),
                'reason' => 'hasRestrictions=false',
            ]);

            return true;
        }

        if (($package->getDaysExpiry() ?? 0) <= 0) {
            $this->logger->warning('[PackageRestriction][ValidationFailed]', [
                'package_id' => $package->getId(),
                'reason' => 'days_expiry_required',
                'days_expiry' => $package->getDaysExpiry(),
            ]);
            $this->addFlash('error', 'Days expiry es obligatorio para paquetes con restricciones.');

            return false;
        }

        $hasAnyCriteria = !empty($hours)
            || !empty($days)
            || !empty($instructors)
            || !empty($disciplines)
            || !empty($branches);

        if (!$hasAnyCriteria) {
            $this->logger->warning('[PackageRestriction][ValidationFailed]', [
                'package_id' => $package->getId(),
                'reason' => 'at_least_one_criteria_required',
            ]);
            $this->addFlash('error', 'Debes configurar al menos una restricción para guardar el paquete restringido.');

            return false;
        }

        $this->logger->info('[PackageRestriction][ApplyFormDataOk]', [
            'package_id' => $package->getId(),
            'has_restrictions' => $package->isHasRestrictions(),
            'saved' => [
                'hours' => $package->getRestrictionHours(),
                'days' => $package->getRestrictionDays(),
                'instructors' => $package->getRestrictionInstructorIds(),
                'disciplines' => $package->getRestrictionDisciplineIds(),
                'branches' => $package->getRestrictionBranchIds(),
            ],
        ]);

        return true;
    }

    /**
     * @param array<int, int>|null $allowedValues
     *
     * @return array<int>|null
     */
    private function normalizeSelectionValues($values, ?array $allowedValues = null): ?array
    {
        if (!is_array($values) || count($values) === 0) {
            return null;
        }

        $numbers = [];

        foreach ($values as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $number = (int) $value;
            if ($number <= 0 && null === $allowedValues) {
                continue;
            }

            if (null !== $allowedValues && !in_array($number, $allowedValues, true)) {
                continue;
            }

            $numbers[] = $number;
        }

        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return count($numbers) > 0 ? $numbers : null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRestrictionHourSlotsForForm(?array $values): array
    {
        if (!$values) {
            return [];
        }

        $slots = [];

        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $value)) {
                [$hh, $mm] = explode(':', $value);
                $slots[] = sprintf('%02d:%02d', (int) $hh, (int) $mm);

                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $number = (int) $value;
            if ($number >= 0 && $number <= 23) {
                // Compatibilidad con valores legacy guardados por hora.
                $slots[] = sprintf('%02d:00', $number);

                continue;
            }

            if ($number >= 0 && $number <= 1439) {
                $hh = (int) floor($number / 60);
                $mm = $number % 60;
                $slots[] = sprintf('%02d:%02d', $hh, $mm);
            }
        }

        $slots = array_values(array_unique($slots));
        sort($slots);

        return $slots;
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeRestrictionHourSlotsSelection(mixed $value): ?array
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded) || count($decoded) === 0) {
            return null;
        }

        $slots = [];
        foreach ($decoded as $slot) {
            if (!is_string($slot)) {
                continue;
            }

            if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $slot)) {
                continue;
            }

            [$hh, $mm] = explode(':', $slot);
            $slots[] = sprintf('%02d:%02d', (int) $hh, (int) $mm);
        }

        $slots = array_values(array_unique($slots));
        sort($slots);

        return count($slots) > 0 ? $slots : null;
    }
}
