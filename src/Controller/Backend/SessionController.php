<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\BranchOffice;
use App\Entity\ExerciseRoom;
use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\Staff;
use App\Entity\User;
use App\Event\SessionCanceledEvent;
use App\Form\Backend\SessionType;
use App\Repository\BranchOfficeRepository;
use App\Repository\ExerciseRoomRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionAuditRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Entity\Notification;
use App\Service\Mailer\ReservationMailer;
use App\Service\Notification\NotificationDispatcher;
use App\Service\ReservationCancellationService;
use App\Service\WaitingList\WaitingListService;
use App\Util\SeatLayoutMapper;
use App\Util\Schedule;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/session')]
class SessionController extends AbstractController
{
    #[Route('/get', name: 'backend_session_get', methods: ['GET'])]
    public function getJson(
        SessionRepository $sessionRepository,
        #[MapQueryParameter] array $filters = [],
    ): JsonResponse {
        $json = [];

        if (empty($filters['date_start'])) {
            $filters['date_start'] = (new \DateTime('today'))->format('d/m/Y');
        }

        $filters['date_end'] = $filters['date_start'];

        $sessions = $sessionRepository->findForBackendList($filters);

        if ($sessions) {
            foreach ($sessions as $session) {
                if (Session::STATUS_CANCEL !== $session['status']) {
                    $json[] = [
                        'id' => $session['id'],
                        'type' => $session['type'],
                        'date_start' => $session['dateStart']->format('d/m/Y'),
                        'time_start' => $session['timeStart']->format('h:i a'),
                        'discipline' => $session['discipline'],
                        'instructor' => $session['instructor'],
                        'status' => $session['status'],
                        'branch_office' => $session['branchOffice'],
                    ];
                }
            }
        }

        return new JsonResponse($json);
    }

    #[Route('/', name: 'backend_session', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        SessionRepository $sessionRepository,
        StaffRepository $staffRepository,
        BranchOfficeRepository $branchOfficeRepository,
        ExerciseRoomRepository $exerciseRoomRepository,
        Schedule $schedule,
        #[MapQueryParameter] array $filters = [],
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $isInstructor = $this->isGranted('ROLE_INSTRUCTOR');

        /** @var Staff $staff */
        $staff = $this->getUser();
        $assignedBranches = $staff->getBranchOfficesIds();

        if ($isInstructor) {
            $filters['instructor'] = $staff->getId();
        }

        // Auto-fill dates only on first panel load (no filters submitted yet).
        if (!$request->query->has('filters')) {
            $currentDate = new \DateTime();
            if (empty($filters['date_start'])) {
                $filters['date_start'] = $currentDate->modify('FIRST DAY OF THIS MONTH')->format('d/m/Y');
            }

            if (empty($filters['date_end'])) {
                $filters['date_end'] = $currentDate->modify('LAST DAY OF THIS MONTH')->format('d/m/Y');
            }
        }

        $filters['assigned_branches'] = $assignedBranches;

        $isExport = $request->query->has('export');
        $sessions = $sessionRepository->findForBackendList($filters, $isExport, false);

        // Export
        if ($isExport) {
            $filename = sprintf('Sesiones_%s.xlsx', date('Y-m-d_H-i'));
            $tmpFile = sys_get_temp_dir() . '/' . uniqid('sessions_export_', true) . '.xlsx';

            try {
                $writer = new Writer();
                $writer->openToFile($tmpFile);

                $sheet = $writer->getCurrentSheet();
                $sheet->setName('Sesiones');

                // Header row
                $writer->addRow(Row::fromValues([
                    'ID',
                    'Día',
                    'Hora',
                    'Salón',
                    'Instructor',
                    'Sucursal',
                    'Alumno',
                    'Lugar',
                ]));

                // Data rows
                foreach ($sessions as $session) {
                    $writer->addRow(Row::fromValues([
                        $session['id'],
                        $session['dateStart']->format('d/m/Y'),
                        $session['timeStart']->format('H:i'),
                        $session['exerciseRoom'],
                        $session['instructor'],
                        $session['branchOffice'],
                        sprintf('%s %s', $session['userName'], $session['userLastname']),
                        $session['placeNumber'],
                    ]));
                }

                // Set column widths
                $sheet->setColumnWidth(8, 1); // ID
                $sheet->setColumnWidth(15, 2); // Día
                $sheet->setColumnWidth(12, 3); // Hora
                $sheet->setColumnWidth(16, 4); // Salón
                $sheet->setColumnWidth(20, 5); // Instructor
                $sheet->setColumnWidth(16, 6); // Sucursal
                $sheet->setColumnWidth(20, 7); // Alumno
                $sheet->setColumnWidth(8, 8); // Lugar

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

        $pagination = $paginator->paginate($sessions, $page, Session::NUMBER_OF_ITEMS);
        $urlExport = $this->generateUrl('backend_session', [
            'filters' => $filters,
            'export' => true,
        ]);
        $instructors = $staffRepository->getAllInstructors();
        $branchOffices = $branchOfficeRepository->findAll();

        if (!empty($assignedBranches)) {
            $branchOffices = array_filter($branchOffices, static function (BranchOffice $branchOffice) use ($assignedBranches) {
                return in_array($branchOffice->getId(), $assignedBranches, true);
            });
        }

        $exerciseRoomsGrouped = [];
        $exerciseRooms = $exerciseRoomRepository->getAll();
        foreach ($exerciseRooms as $exerciseRoom) {
            $exerciseRoomsGrouped[$exerciseRoom->getBranchOffice()->getName()][] = $exerciseRoom;
        }

        $filterStatus = Session::statusList();
        $filterStatus[] = Session::STATUS_NOT_CANCELED;

        return $this->render('backend/session/index.html.twig', [
            'url_export' => $urlExport,
            'instructors' => $instructors,
            'pagination' => $pagination,
            'branch_offices' => $branchOffices,
            'filters' => $filters,
            'filter_status' => $filterStatus,
            'filter_exercise_rooms' => $exerciseRoomsGrouped,
            'filter_schedules' => $schedule->getSchedules(),
            'is_instructor' => $isInstructor,
        ]);
    }

    #[Route('/new', name: 'backend_session_new', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $session = new Session();
        $form = $this->createForm(SessionType::class, $session, [
            'use_flexible_time_start' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ExerciseRoom $exerciseRoom */
            $exerciseRoom = $session->getExerciseRoom();
            $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);
            $placesNotAvailable = SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                $exerciseRoom->getPlacesNotAvailable(),
                $capacity,
                (int) ($exerciseRoom->getCapacity() ?? 0)
            );
            $session
                ->setExerciseRoomCapacity($capacity)
                ->setPlacesNotAvailable($placesNotAvailable)
                ->setSeatLayout(SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoom->getSeatLayout(), $capacity))
                ->updateAvailableCapacity()
                ->setType($exerciseRoom->getType())
            ;

            if ((int) $session->getAvailableCapacity() <= 0) {
                $session->setStatus(Session::STATUS_FULL);
            }

            $em->persist($session);
            $em->flush();

            $this->addFlash('success', 'La Clase ha sido creada.');

            return $this->redirectToRoute('backend_session_edit', [
                'id' => $session->getId(),
            ]);
        }

        return $this->render('backend/session/new.html.twig', [
            'session' => $session,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_session_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function edit(
        Request $request,
        Session $session,
        EntityManagerInterface $em,
        ReservationRepository $reservationRepository,
        WaitingListService $waitingListService,
        ReservationMailer $reservationMailer,
        LoggerInterface $logger,
        NotificationDispatcher $notificationDispatcher,
    ): Response {
        // Guardar estado original para detectar cambios
        $originalPlaces = $session->getPlacesNotAvailable() ?? [];
        $originalCapacity = $session->getExerciseRoomCapacity();
        $originalDateStart = $session->getDateStart() ? clone $session->getDateStart() : null;
        $originalTimeStart = $session->getTimeStart() ? clone $session->getTimeStart() : null;
        $originalInstructorId = $session->getInstructor()?->getId();
        $originalDisciplineId = $session->getDiscipline()?->getId();
        $originalExerciseRoomId = $session->getExerciseRoom()?->getId();
        $originalInstructorName = $session->getInstructor()?->getProfile()?->getFirstname();
        $originalDisciplineName = $session->getDiscipline() ? (string) $session->getDiscipline() : null;
        $originalExerciseRoomName = $session->getExerciseRoom() ? (string) $session->getExerciseRoom() : null;

        $cancelForm = $this->createCancelForm($session);
        $editForm = $this->createForm(SessionType::class, $session, [
            'use_flexible_time_start' => true,
        ]);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);
            $session->setSeatLayout(
                $this->completeSeatLayout(
                    $this->sanitizeSeatLayout($session->getSeatLayout(), $capacity),
                    $capacity
                )
            );

            $session->setPlacesNotAvailable(
                SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                    $session->getPlacesNotAvailable(),
                    $capacity,
                    (int) ($session->getExerciseRoom()?->getCapacity() ?? 0)
                )
            );

            $newPlaces = $session->getPlacesNotAvailable() ?? [];

            // Detectar asientos nuevamente deshabilitados
            $disabledPlaces = array_diff($newPlaces, $originalPlaces);

            // Si hay asientos deshabilitados, buscar reservaciones activas
            if (!empty($disabledPlaces)) {
                $affected = $reservationRepository->findActiveBySessionAndPlaces(
                    $session,
                    $disabledPlaces
                );

                // Si hay conflictos y no fue confirmado, mostrar página de confirmación
                if (!empty($affected) && !$request->request->has('session_edit_confirmed')) {
                    $logger->warning('Issue #52: Admin intenta deshabilitar asientos con reservaciones activas', [
                        'session_id' => $session->getId(),
                        'admin_user' => $this->getUser()?->getUserIdentifier(),
                        'disabled_places' => $disabledPlaces,
                        'affected_count' => count($affected),
                    ]);

                    // Guardar estado en sesión para recuperarlo después
                    $request->getSession()->set('session_edit_pending', [
                        'session_id' => $session->getId(),
                        'new_places' => $newPlaces,
                        'disabled_places' => $disabledPlaces,
                    ]);

                    return $this->render('backend/session/edit_confirm.html.twig', [
                        'session' => $session,
                        'affected' => $affected,
                        'disabled_places' => $disabledPlaces,
                        'cancel_form' => $this->createCancelForm($session)->createView(),
                    ]);
                }
            }

            // Actualizar capacidad disponible
            $session->updateAvailableCapacity();
            $this->syncSessionAvailabilityStatus($session, $reservationRepository);

            // Detectar reducción de capacidad con reservas afectadas
            $newCapacity = $session->getExerciseRoomCapacity();
            if (is_int($newCapacity) && is_int($originalCapacity) && $newCapacity < $originalCapacity) {
                $capacityAffected = $reservationRepository->findActiveBySessionAboveCapacity($session, $newCapacity);

                if (!empty($capacityAffected)) {
                    $logger->warning('Issue #51: Admin reduce capacidad con reservaciones activas afectadas', [
                        'session_id' => $session->getId(),
                        'admin_user' => $this->getUser()?->getUserIdentifier(),
                        'old_capacity' => $originalCapacity,
                        'new_capacity' => $newCapacity,
                        'affected_count' => count($capacityAffected),
                    ]);

                    $request->getSession()->set('session_edit_pending', [
                        'type' => 'capacity_change',
                        'session_id' => $session->getId(),
                        'new_capacity' => $newCapacity,
                        'old_capacity' => $originalCapacity,
                        'new_places' => $newPlaces,
                    ]);

                    return $this->render('backend/session/edit_confirm.html.twig', [
                        'session' => $session,
                        'affected' => $capacityAffected,
                        'change_type' => 'capacity_change',
                        'new_capacity' => $newCapacity,
                        'old_capacity' => $originalCapacity,
                        'cancel_form' => $this->createCancelForm($session)->createView(),
                    ]);
                }
            }

            // Guardar cambios normales
            $em->flush();

            $changesSummary = $this->buildSessionChangesSummary(
                $session,
                $originalDateStart,
                $originalTimeStart,
                $originalInstructorId,
                $originalDisciplineId,
                $originalExerciseRoomId,
                $originalCapacity,
                $originalPlaces,
                $originalInstructorName,
                $originalDisciplineName,
                $originalExerciseRoomName,
            );

            if (!empty($changesSummary['labels'])) {
                $activeReservations = $reservationRepository->getReservationsBySession($session);
                foreach ($activeReservations as $reservation) {
                    $reservationMailer->sendSessionUpdatedEmail(
                        $reservation,
                        $changesSummary['labels'],
                        $changesSummary['details'],
                    );

                    $reservationUser = $reservation->getUser();
                    if ($reservationUser !== null) {
                        $disciplineName = $session->getDiscipline()?->getName() ?? 'la clase';
                        $sessionDate    = $session->getDateStart()?->format('d/m/Y') ?? '';
                        $sessionTime    = $session->getTimeStart()?->format('H:i') ?? '';
                        $changesText    = implode(', ', $changesSummary['labels']);
                        $editMinute     = (new \DateTimeImmutable())->format('YmdHi');
                        try {
                            $notificationDispatcher->dispatch(
                                'session_updated',
                                $reservationUser,
                                'Tu clase fue modificada',
                                sprintf('Hubo un cambio en %s del %s a las %s: %s.', $disciplineName, $sessionDate, $sessionTime, $changesText),
                                ['resource_key' => sprintf('session_updated_%d_%d_%s', (int) $session->getId(), (int) $reservation->getId(), $editMinute)],
                                Notification::PRIORITY_HIGH,
                            );
                        } catch (\Throwable) {}
                    }
                }

                $waitingListService->promoteAvailablePlaces($session);
            } else {
                $waitingListService->promoteAvailablePlaces($session);
            }

            // Limpiar sesión si existe
            $request->getSession()->remove('session_edit_pending');

            $logger->info('Issue #52: Sesión actualizada sin conflictos', [
                'session_id' => $session->getId(),
                'admin_user' => $this->getUser()?->getUserIdentifier(),
                'new_places' => $newPlaces,
            ]);

            $this->addFlash('success', 'La Clase ha sido actualizada.');

            return $this->redirectToRoute('backend_session_edit', [
                'id' => $session->getId(),
            ]);
        }

        return $this->render('backend/session/edit.html.twig', [
            'session' => $session,
            'edit_form' => $editForm->createView(),
            'cancel_form' => $cancelForm->createView(),
        ]);
    }

    #[Route('/{id}/edit-confirm', name: 'backend_session_edit_confirm', methods: ['POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function editConfirm(
        Request $request,
        Session $session,
        EntityManagerInterface $em,
        ReservationRepository $reservationRepository,
        ReservationCancellationService $cancellationService,
        WaitingListService $waitingListService,
        LoggerInterface $logger,
        Security $security,
    ): Response {
        $adminUser = $security->getUser();
        
        $logger->info('Issue #52: Procesando confirmación de deshabilitación', [
            'session_id' => $session->getId(),
            'admin_user' => $adminUser?->getUserIdentifier(),
        ]);

        // Validar token CSRF
        if (!$this->isCsrfTokenValid('session_edit_confirm', $request->request->get('_token'))) {
            $logger->error('Issue #52: Token CSRF inválido en confirmación', [
                'session_id' => $session->getId(),
            ]);
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('backend_session_edit', ['id' => $session->getId()]);
        }

        // Recuperar datos de la sesión
        $pendingData = $request->getSession()->get('session_edit_pending');

        if (!$pendingData || $pendingData['session_id'] !== $session->getId()) {
            $this->addFlash('danger', 'Sesión de edición expirada. Intenta nuevamente.');
            return $this->redirectToRoute('backend_session_edit', ['id' => $session->getId()]);
        }

        // Obtener motivo del formulario
        $reason = trim($request->request->get('reason', ''));

        // Validar motivo
        if (strlen($reason) < 10 || strlen($reason) > 500) {
            $logger->warning('Issue #51/#52: Motivo inválido en confirmación', [
                'session_id' => $session->getId(),
                'reason_length' => strlen($reason),
            ]);
            $this->addFlash('danger', 'El motivo debe tener entre 10 y 500 caracteres.');
            return $this->redirectToRoute('backend_session_edit', ['id' => $session->getId()]);
        }

        $pendingType = $pendingData['type'] ?? 'place_disabled';

        // ── Flujo: reducción de capacidad ──────────────────────────────────────
        if ($pendingType === 'capacity_change') {
            $newCapacity = (int) $pendingData['new_capacity'];

            // Restaurar la nueva capacidad en la entidad (cargada fresca desde DB)
            $session->setExerciseRoomCapacity($newCapacity);
            if (isset($pendingData['new_places'])) {
                $session->setPlacesNotAvailable(
                    SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                        is_array($pendingData['new_places']) ? $pendingData['new_places'] : [],
                        $newCapacity,
                        (int) ($session->getExerciseRoom()?->getCapacity() ?? 0)
                    )
                );
            }
            $session->setSeatLayout(
                $this->completeSeatLayout(
                    $this->sanitizeSeatLayout($session->getSeatLayout(), $newCapacity),
                    $newCapacity
                )
            );

            $affected = $reservationRepository->findActiveBySessionAboveCapacity($session, $newCapacity);

            if (empty($affected)) {
                $session->updateAvailableCapacity();
                $this->syncSessionAvailabilityStatus($session, $reservationRepository);
                $em->flush();
                $waitingListService->promoteAvailablePlaces($session);
                $request->getSession()->remove('session_edit_pending');
                $this->addFlash('success', 'La Clase ha sido actualizada.');
                return $this->redirectToRoute('backend_session_edit', ['id' => $session->getId()]);
            }

            try {
                if ($adminUser === null) {
                    throw new \LogicException('No hay usuario autenticado');
                }

                $affectedPlaces = array_map(fn($r) => $r->getPlaceNumber(), $affected);

                $result = $cancellationService->cancelMultipleAndAudit(
                    $affected,
                    $session,
                    $adminUser,
                    $affectedPlaces,
                    $reason,
                    'capacity_changed'
                );

                $session->updateAvailableCapacity();
                $this->syncSessionAvailabilityStatus($session, $reservationRepository);
                $em->flush();
                $waitingListService->promoteAvailablePlaces($session);
                $request->getSession()->remove('session_edit_pending');

                $success = $result['success'];
                $failed  = $result['failed'];

                if ($failed === 0) {
                    $logger->info('Issue #51: Reducción de capacidad procesada exitosamente', [
                        'session_id'   => $session->getId(),
                        'admin_user'   => $adminUser->getUserIdentifier(),
                        'old_capacity' => $pendingData['old_capacity'],
                        'new_capacity' => $newCapacity,
                        'success_count' => $success,
                    ]);
                    $this->addFlash(
                        'success',
                        "✅ Capacidad actualizada. {$success} reservación(es) cancelada(s) y créditos devueltos."
                    );
                } else {
                    $logger->warning('Issue #51: Reducción de capacidad completa parcialmente', [
                        'session_id'    => $session->getId(),
                        'admin_user'    => $adminUser->getUserIdentifier(),
                        'success_count' => $success,
                        'failed_count'  => $failed,
                    ]);
                    $this->addFlash(
                        'warning',
                        "⚠️ Operación completada parcialmente. {$success} canceladas, {$failed} fallidas."
                    );
                }
            } catch (\Exception $e) {
                $logger->error('Issue #51: Error procesando reducción de capacidad', [
                    'session_id' => $session->getId(),
                    'admin_user' => $adminUser?->getUserIdentifier(),
                    'error'      => $e->getMessage(),
                ]);
                $this->addFlash('danger', '❌ Error procesando cancelaciones: '.$e->getMessage());
            }

            return $this->redirectToRoute('backend_session_edit', ['id' => $session->getId()]);
        }

        // ── Flujo: deshabilitación de asientos (place_disabled) ────────────────
        // Buscar reservaciones afectadas
        $affected = $reservationRepository->findActiveBySessionAndPlaces(
            $session,
            $pendingData['disabled_places']
        );

        // Si no hay afectadas, solo guardar cambios
        if (empty($affected)) {
            $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);
            $session->setPlacesNotAvailable(
                SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                    is_array($pendingData['new_places']) ? $pendingData['new_places'] : [],
                    $capacity,
                    (int) ($session->getExerciseRoom()?->getCapacity() ?? 0)
                )
            );
            $session->setSeatLayout(
                $this->completeSeatLayout(
                    $this->sanitizeSeatLayout($session->getSeatLayout(), $capacity),
                    $capacity
                )
            );
            $session->updateAvailableCapacity();
            $this->syncSessionAvailabilityStatus($session, $reservationRepository);
            $em->flush();
            $waitingListService->promoteAvailablePlaces($session);
            $request->getSession()->remove('session_edit_pending');

            $this->addFlash('success', 'La Clase ha sido actualizada.');

            return $this->redirectToRoute('backend_session_edit', ['id' => $session->getId()]);
        }

        // Cancelar y devolver crédito
        try {
            // Validar que existe usuario autenticado
            if ($adminUser === null) {
                throw new \LogicException("No hay usuario autenticado");
            }

            $result = $cancellationService->cancelMultipleAndAudit(
                $affected,
                $session,
                $adminUser,
                $pendingData['disabled_places'],
                $reason
            );

            // Guardar los cambios en placesNotAvailable ahora que todo está procesado
            $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);
            $session->setPlacesNotAvailable(
                SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                    is_array($pendingData['new_places']) ? $pendingData['new_places'] : [],
                    $capacity,
                    (int) ($session->getExerciseRoom()?->getCapacity() ?? 0)
                )
            );
            $session->setSeatLayout(
                $this->completeSeatLayout(
                    $this->sanitizeSeatLayout($session->getSeatLayout(), $capacity),
                    $capacity
                )
            );
            $session->updateAvailableCapacity();
            $this->syncSessionAvailabilityStatus($session, $reservationRepository);
            $em->flush();
            $waitingListService->promoteAvailablePlaces($session);

            // Limpiar sesión
            $request->getSession()->remove('session_edit_pending');

            $success = $result['success'];
            $failed = $result['failed'];

            if ($failed === 0) {
                $logger->info('Issue #52: Deshabilitación completada exitosamente', [
                    'session_id' => $session->getId(),
                    'admin_user' => $adminUser->getUserIdentifier(),
                    'success_count' => $success,
                    'disabled_places' => $pendingData['disabled_places'],
                    'reason' => substr($reason, 0, 100),
                ]);
                $this->addFlash(
                    'success',
                    "✅ La Clase ha sido actualizada. {$success} reservación(es) cancelada(s). Créditos devueltos."
                );
            } else {
                $logger->warning('Issue #52: Deshabilitación completada parcialmente', [
                    'session_id' => $session->getId(),
                    'admin_user' => $adminUser->getUserIdentifier(),
                    'success_count' => $success,
                    'failed_count' => $failed,
                ]);
                $this->addFlash(
                    'warning',
                    "⚠️ Operación completada parcialmente. {$success} canceladas, {$failed} fallidas."
                );
            }

        } catch (\Exception $e) {
            $logger->error('Issue #52: Error procesando cancelaciones', [
                'session_id' => $session->getId(),
                'admin_user' => $adminUser?->getUserIdentifier(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->addFlash(
                'danger',
                "❌ Error procesando cancelaciones: " . $e->getMessage()
            );
        }

        return $this->redirectToRoute('backend_session_edit', [
            'id' => $session->getId(),
        ]);
    }

    #[Route('/{id}/audit', name: 'backend_session_audit', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function audit(
        Session $session,
        SessionAuditRepository $auditRepository,
        LoggerInterface $logger,
    ): Response
    {
        $audits = $auditRepository->findBy(['session' => $session], ['createdAt' => 'DESC']);

        $adminUser = $this->getUser();

        $logger->info('Issue #52: Acceso al panel de auditoria', [
            'session_id' => $session->getId(),
            'admin_user' => $adminUser?->getUserIdentifier(),
            'audit_count' => count($audits),
        ]);

        $usersWithoutId = 0;
        foreach ($audits as $audit) {
            foreach ($audit->getAffectedUsers() as $affectedUser) {
                if (!isset($affectedUser['id']) || empty($affectedUser['id'])) {
                    ++$usersWithoutId;
                }
            }
        }

        if ($usersWithoutId > 0) {
            $logger->warning('Issue #52: Usuarios afectados sin ID en auditoria', [
                'session_id' => $session->getId(),
                'admin_user' => $adminUser?->getUserIdentifier(),
                'users_without_id' => $usersWithoutId,
            ]);
        }

        return $this->render('backend/session/audit.html.twig', [
            'session' => $session,
            'audits' => $audits,
            'cancel_form' => $this->createCancelForm($session)->createView(),
        ]);
    }

    #[Route('/{id}/audit/export', name: 'backend_session_audit_export', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function auditExport(
        Session $session,
        SessionAuditRepository $auditRepository,
    ): Response {
        $audits = $auditRepository->findBy(['session' => $session], ['createdAt' => 'DESC']);

        $filename = sprintf('Auditoria_Sesion_%d_%s.xlsx', $session->getId(), date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('audit_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Auditoría');

            // Header row
            $writer->addRow(Row::fromValues([
                'Fecha',
                'Tipo de Auditoría',
                'Usuario Admin',
                'Usuario Afectado',
                'Motivo',
                'Reservaciones Afectadas',
                'Detalles',
            ]));

            // Data rows
            foreach ($audits as $audit) {
                /** @var \DateTimeInterface|null $createdAt */
                $createdAt = $audit->getCreatedAt();

                $affectedUsersNames = [];
                foreach ($audit->getAffectedUsers() as $affectedUser) {
                    $affectedUsersNames[] = isset($affectedUser['name']) ? $affectedUser['name'] : 'Usuario';
                }
                $usersStr = implode('; ', $affectedUsersNames) ?: 'N/A';

                $auditTypeMap = [
                    'place_disabled' => 'Asiento Deshabilitado',
                    'capacity_changed' => 'Capacidad Modificada',
                    'user_cancelled' => 'Usuario Cancelado',
                    'user_changed' => 'Usuario Cambiado',
                    'user_changed_from' => 'Usuario Movido (Desde)',
                    'user_changed_to' => 'Usuario Movido (Hacia)',
                ];
                $auditType = $auditTypeMap[$audit->getAuditType()] ?? $audit->getAuditType();

                $disabledPlacesStr = '';
                if ($audit->getDisabledPlaces()) {
                    $disabledPlacesStr = 'Asientos: ' . implode(', ', $audit->getDisabledPlaces());
                }

                $details = [];
                if ($audit->getFromPlace() && $audit->getToPlace()) {
                    $details[] = sprintf('Asiento: %d → %d', $audit->getFromPlace(), $audit->getToPlace());
                }
                if ($disabledPlacesStr) {
                    $details[] = $disabledPlacesStr;
                }
                $detailsStr = implode(' | ', $details) ?: 'N/A';

                $writer->addRow(Row::fromValues([
                    $createdAt?->format('Y-m-d H:i:s'),
                    $auditType,
                    $audit->getAdminUserIdentifier() ?? 'N/A',
                    $usersStr,
                    $audit->getReason() ?? 'N/A',
                    $audit->getAffectedReservationsCount() ?? 0,
                    $detailsStr,
                ]));
            }

            // Set column widths
            $sheet->setColumnWidth(20, 1); // Fecha
            $sheet->setColumnWidth(20, 2); // Tipo de Auditoría
            $sheet->setColumnWidth(18, 3); // Usuario Admin
            $sheet->setColumnWidth(20, 4); // Usuario Afectado
            $sheet->setColumnWidth(25, 5); // Motivo
            $sheet->setColumnWidth(18, 6); // Reservaciones Afectadas
            $sheet->setColumnWidth(30, 7); // Detalles

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

    #[Route('/{id}', name: 'backend_session_cancel', methods: ['POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function cancel(
        Request $request,
        Session $session,
        EntityManagerInterface $em,
        EventDispatcherInterface $eventDispatcher
    ): Response {
        $form = $this->createCancelForm($session);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $session->setStatus(Session::STATUS_CANCEL);

            $em->flush();

            $event = new SessionCanceledEvent($session);
            $eventDispatcher->dispatch($event);

            $this->addFlash('danger', 'La Clase ha sido cancelada.');

            return $this->redirectToRoute('backend_session_edit', [
                'id' => $session->getId(),
            ]);
        }

        return $this->redirectToRoute('backend_session');
    }

    #[Route('/{id}/reservations', name: 'backend_session_reservations', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function reservations(Session $session, EntityManagerInterface $em): Response
    {
        /** @var ReservationRepository $reservationsRepository */
        $reservationRepository = $em->getRepository(Reservation::class);
        $reservations = $reservationRepository->getReservationsBySession($session);
        $cancelForm = $this->createCancelForm($session);

        return $this->render('backend/session/reservations.html.twig', [
            'session' => $session,
            'reservations' => $reservations,
            'cancel_form' => $cancelForm,
        ]);
    }

    #[Route('/{id}/waitinglist', name: 'backend_session_waitinglist', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function waitingList(Session $session): Response
    {
        $waitingList = $session->getWaitingList();

        $cancelForm = $this->createCancelForm($session);

        return $this->render('backend/session/waitinglist.html.twig', [
            'cancel_form' => $cancelForm->createView(),
            'session' => $session,
            'waitinglist' => $waitingList,
        ]);
    }

    #[Route('/{id}/seats', name: 'backend_session_seats', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function seats(
        Request $request,
        Session $session,
        EntityManagerInterface $em,
        ReservationRepository $reservationRepository,
        WaitingListService $waitingListService,
    ): Response {
        $canEditSeats = in_array($session->getStatus(), [Session::STATUS_OPEN, Session::STATUS_FULL], true);
        $seatLayoutData = $this->resolveSeatLayoutData($session);

        if ($request->isMethod('POST')) {
            if (!$canEditSeats) {
                $this->addFlash('warning', 'La clase está cerrada/cancelada. No se puede editar la disposición de asientos.');

                return $this->redirectToRoute('backend_session_seats', ['id' => $session->getId()]);
            }

            if (!$this->isCsrfTokenValid('session_seats_' . $session->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad inválido.');
                return $this->redirectToRoute('backend_session_seats', ['id' => $session->getId()]);
            }

            $raw = $request->request->get('places_not_available', '');
            $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);
            $layout = $this->completeSeatLayout(
                $this->sanitizeSeatLayout($request->request->get('seat_layout'), $capacity),
                $capacity
            );
            $saveAsDefault = $request->request->getBoolean('save_as_default');

            $parsed = array_values(array_unique(array_filter(
                array_map('intval', array_filter(explode(',', $raw), 'strlen')),
                fn(int $n) => $n >= 1 && $n <= $capacity
            )));
            sort($parsed);

            $session->setPlacesNotAvailable($parsed ?: null);
            $session->setSeatLayout($layout);
            $session->updateAvailableCapacity();
            $this->syncSessionAvailabilityStatus($session, $reservationRepository);

            if ($saveAsDefault && $session->getExerciseRoom() instanceof ExerciseRoom) {
                $session->getExerciseRoom()
                    ->setCapacity($capacity)
                    ->setPlacesNotAvailable($parsed ?: null)
                    ->setSeatLayout($layout)
                ;
            }

            $em->flush();
            $waitingListService->promoteAvailablePlaces($session);

            $this->addFlash(
                'success',
                $saveAsDefault
                    ? 'Disposición actualizada y guardada como plantilla por defecto del salón (incluye capacidad).'
                    : 'Disposición de asientos actualizada solo para esta clase.'
            );

            return $this->redirectToRoute('backend_session_seats', ['id' => $session->getId()]);
        }

        $reservations   = $reservationRepository->getReservationsBySession($session);
        $reservedPlaces = array_values(array_filter(
            array_map(fn($r) => $r->getPlaceNumber(), $reservations),
            fn($p) => $p !== null
        ));

        return $this->render('backend/session/seats.html.twig', [
            'session'         => $session,
            'seat_layout'     => $seatLayoutData['layout'],
            'seat_layout_source' => $seatLayoutData['source'],
            'default_seat_layout' => $seatLayoutData['default_layout'],
            'reserved_places' => $reservedPlaces,
            'cancel_form'     => $this->createCancelForm($session)->createView(),
        ]);
    }

    #[Route('/{id}/places', name: 'backend_session_places', methods: ['GET'])]
    public function places(Session $session): JsonResponse
    {
        $json = [];
        $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);
        $reservations = $session->getReservations();
        $notAvailable = $session->getPlacesNotAvailable() ?? [];

        $i = 1;
        for (; $i <= $capacity; ++$i) {
            $json[] = [
                'place' => $i,
                'is_available' => !in_array($i, $notAvailable, false),
            ];
        }

        foreach ($reservations as $reservation) {
            if ($reservation->isIsAvailable()) {
                $json[$reservation->getPlaceNumber() - 1]['is_available'] = false;
            }
        }

        return $this->json($json);
    }

    /**
     * Creates a form to delete a session entity.
     *
     * @param Session $session
     *
     * @return FormInterface
     */
    private function createCancelForm(Session $session): FormInterface
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('backend_session_cancel', [
                'id' => $session->getId(),
            ]))
            ->getForm()
        ;
    }

    private function resolveSeatLayoutData(Session $session): array
    {
        $capacity = (int) ($session->getExerciseRoomCapacity() ?? 0);

        $sessionLayout = $this->sanitizeSeatLayout($session->getSeatLayout(), $capacity);
        if (null !== $sessionLayout) {
            return [
                'layout' => $sessionLayout,
                'source' => 'session',
                'default_layout' => null,
            ];
        }

        $exerciseRoom = $session->getExerciseRoom();
        if ($exerciseRoom instanceof ExerciseRoom) {
            $exerciseRoomLayout = $this->sanitizeSeatLayout($exerciseRoom->getSeatLayout(), $capacity);
            if (null !== $exerciseRoomLayout) {
                return [
                    'layout' => $exerciseRoomLayout,
                    'source' => 'exercise_room',
                    'default_layout' => $exerciseRoomLayout,
                ];
            }
        }

        return [
            'layout' => $this->buildSequentialSeatLayout($capacity),
            'source' => 'generated',
            'default_layout' => null,
        ];
    }

    private function syncSessionAvailabilityStatus(
        Session $session,
        ReservationRepository $reservationRepository,
    ): void {
        $status = $session->getStatus();
        if (!in_array($status, [Session::STATUS_OPEN, Session::STATUS_FULL], true)) {
            return;
        }

        $availableCapacity = max(0, (int) $session->getAvailableCapacity());
        $activeReservations = $reservationRepository->getTotalReservationsForSession($session);

        if ($activeReservations >= $availableCapacity) {
            $session->setStatus(Session::STATUS_FULL);

            return;
        }

        $session->setStatus(Session::STATUS_OPEN);
    }

    private function buildSessionChangesSummary(
        Session $session,
        ?\DateTimeInterface $originalDateStart,
        ?\DateTimeInterface $originalTimeStart,
        ?int $originalInstructorId,
        ?int $originalDisciplineId,
        ?int $originalExerciseRoomId,
        ?int $originalCapacity,
        array $originalPlaces,
        ?string $originalInstructorName,
        ?string $originalDisciplineName,
        ?string $originalExerciseRoomName,
    ): array {
        $labels = [];
        $details = [];

        if ($this->hasDateChanged($originalDateStart, $session->getDateStart())) {
            $labels[] = 'Fecha';
            $details[] = [
                'field' => 'Fecha',
                'before' => $originalDateStart?->format('Y-m-d') ?? 'Sin definir',
                'after' => $session->getDateStart()?->format('Y-m-d') ?? 'Sin definir',
            ];
        }

        if ($this->hasTimeChanged($originalTimeStart, $session->getTimeStart())) {
            $labels[] = 'Hora';
            $details[] = [
                'field' => 'Hora',
                'before' => $originalTimeStart?->format('H:i') ?? 'Sin definir',
                'after' => $session->getTimeStart()?->format('H:i') ?? 'Sin definir',
            ];
        }

        if ($originalInstructorId !== $session->getInstructor()?->getId()) {
            $labels[] = 'Instructor';
            $details[] = [
                'field' => 'Instructor',
                'before' => $originalInstructorName ?? 'Sin definir',
                'after' => $session->getInstructor()?->getProfile()?->getFirstname() ?? 'Sin definir',
            ];
        }

        if ($originalDisciplineId !== $session->getDiscipline()?->getId()) {
            $labels[] = 'Disciplina';
            $details[] = [
                'field' => 'Disciplina',
                'before' => $originalDisciplineName ?? 'Sin definir',
                'after' => $session->getDiscipline() ? (string) $session->getDiscipline() : 'Sin definir',
            ];
        }

        if ($originalExerciseRoomId !== $session->getExerciseRoom()?->getId()) {
            $labels[] = 'Salón';
            $details[] = [
                'field' => 'Salón',
                'before' => $originalExerciseRoomName ?? 'Sin definir',
                'after' => $session->getExerciseRoom() ? (string) $session->getExerciseRoom() : 'Sin definir',
            ];
        }

        if ($originalCapacity !== $session->getExerciseRoomCapacity()) {
            $labels[] = 'Capacidad';
            $details[] = [
                'field' => 'Capacidad',
                'before' => null !== $originalCapacity ? (string) $originalCapacity : 'Sin definir',
                'after' => null !== $session->getExerciseRoomCapacity() ? (string) $session->getExerciseRoomCapacity() : 'Sin definir',
            ];
        }

        $normalizedOriginalPlaces = array_values(array_unique(array_map('intval', $originalPlaces)));
        $normalizedNewPlaces = array_values(array_unique(array_map('intval', $session->getPlacesNotAvailable() ?? [])));
        sort($normalizedOriginalPlaces);
        sort($normalizedNewPlaces);

        if ($normalizedOriginalPlaces !== $normalizedNewPlaces) {
            $labels[] = 'Disponibilidad de asientos';
            $details[] = [
                'field' => 'Disponibilidad de asientos',
                'before' => $this->formatUnavailablePlacesSummary($normalizedOriginalPlaces),
                'after' => $this->formatUnavailablePlacesSummary($normalizedNewPlaces),
            ];
        }

        return [
            'labels' => $labels,
            'details' => $details,
        ];
    }

    private function formatUnavailablePlacesSummary(array $places): string
    {
        if ([] === $places) {
            return 'Sin asientos bloqueados';
        }

        return implode(', ', $places);
    }

    private function hasDateChanged(?\DateTimeInterface $original, ?\DateTimeInterface $current): bool
    {
        if ($original === null || $current === null) {
            return $original !== $current;
        }

        return $original->format('Y-m-d') !== $current->format('Y-m-d');
    }

    private function hasTimeChanged(?\DateTimeInterface $original, ?\DateTimeInterface $current): bool
    {
        if ($original === null || $current === null) {
            return $original !== $current;
        }

        return $original->format('H:i:s') !== $current->format('H:i:s');
    }

    private function sanitizeSeatLayout(mixed $rawLayout, int $capacity): ?array
    {
        if (is_string($rawLayout)) {
            $rawLayout = '' !== trim($rawLayout) ? json_decode($rawLayout, true) : null;
        }

        if (!is_array($rawLayout)) {
            return null;
        }

        $maxSlots = 36;
        $maxSeats = max(0, $capacity);
        $usedSlots = [];
        $layout = [];

        foreach ($rawLayout as $seat => $slot) {
            $seatNumber = (int) $seat;
            $slotNumber = (int) $slot;

            if ($seatNumber < 1 || $seatNumber > $maxSeats) {
                continue;
            }

            if ($slotNumber < 1 || $slotNumber > $maxSlots || isset($usedSlots[$slotNumber])) {
                continue;
            }

            $layout[(string) $seatNumber] = $slotNumber;
            $usedSlots[$slotNumber] = true;
        }

        if ([] === $layout) {
            return null;
        }

        ksort($layout, SORT_NUMERIC);

        return $layout;
    }

    private function completeSeatLayout(?array $layout, int $capacity): ?array
    {
        $maxSeats = min(max(0, $capacity), 36);
        if (0 === $maxSeats) {
            return null;
        }

        $completed = [];
        $usedSlots = [];

        foreach (($layout ?? []) as $seat => $slot) {
            $seatNumber = (int) $seat;
            $slotNumber = (int) $slot;

            if ($seatNumber < 1 || $seatNumber > $maxSeats) {
                continue;
            }

            if ($slotNumber < 1 || $slotNumber > 36 || isset($usedSlots[$slotNumber])) {
                continue;
            }

            if (isset($completed[(string) $seatNumber])) {
                continue;
            }

            $completed[(string) $seatNumber] = $slotNumber;
            $usedSlots[$slotNumber] = true;
        }

        $freeSlots = [];
        for ($slot = 1; $slot <= 36; ++$slot) {
            if (!isset($usedSlots[$slot])) {
                $freeSlots[] = $slot;
            }
        }

        for ($seat = 1; $seat <= $maxSeats; ++$seat) {
            $seatKey = (string) $seat;
            if (isset($completed[$seatKey])) {
                continue;
            }

            if ([] === $freeSlots) {
                break;
            }

            $completed[$seatKey] = array_shift($freeSlots);
        }

        ksort($completed, SORT_NUMERIC);

        return $completed;
    }

    private function buildSequentialSeatLayout(int $capacity): ?array
    {
        $limit = min(max(0, $capacity), 36);
        if (0 === $limit) {
            return null;
        }

        $layout = [];
        for ($seat = 1; $seat <= $limit; ++$seat) {
            $layout[(string) $seat] = $seat;
        }

        return $layout;
    }
}
