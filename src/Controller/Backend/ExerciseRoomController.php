<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\ExerciseRoom;
use App\Form\Backend\ExerciseRoomType;
use App\Repository\ExerciseRoomRepository;
use App\Repository\SessionRepository;
use App\Service\WaitingList\WaitingListService;
use App\Util\SeatLayoutMapper;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Exercise Room Controller.
 */
#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('backend/exerciseroom')]
class ExerciseRoomController extends AbstractController
{
    #[Route('/', name: 'backend_exerciseroom', methods: ['GET'])]
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        ExerciseRoomRepository $exerciseRoomRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $pagination = $paginator->paginate(
            $exerciseRoomRepository->getQueryAll($request->query->has('sort')),
            $page,
            Exerciseroom::NUMBER_OF_ITEMS
        );

        return $this->render('backend/exerciseroom/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'backend_exerciseroom_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $exerciseRoom = new Exerciseroom();
        $form = $this->createForm(ExerciseRoomType::class, $exerciseRoom);
        $form->handleRequest($request);
        $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);

        if ($form->isSubmitted() && $form->isValid()) {
            $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);
            $seatLayout = SeatLayoutMapper::buildPersistedSeatLayout(
                $this->sanitizeSeatLayout($exerciseRoom->getSeatLayout(), $capacity),
                $capacity
            );

            $placesNotAvailable = SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                $exerciseRoom->getPlacesNotAvailable(),
                $capacity,
                $capacity,
            );

            $exerciseRoom
                ->setSeatLayout($seatLayout)
                ->setPlacesNotAvailable($placesNotAvailable)
            ;

            $em->persist($exerciseRoom);
            $em->flush();

            $this->addFlash('success', 'El Salón ha sido creado.');

            return $this->redirectToRoute('backend_exerciseroom_edit', [
                'id' => $exerciseRoom->getId(),
            ]);
        }

        return $this->render('backend/exerciseroom/new.html.twig', [
            'exerciseRoom' => $exerciseRoom,
            'form' => $form,
            'seat_layout' => SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoom->getSeatLayout(), $capacity),
            'not_available' => SeatLayoutMapper::buildPersistedPlacesNotAvailable($exerciseRoom->getPlacesNotAvailable(), $capacity, $capacity) ?? [],
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_exerciseroom_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ExerciseRoom $exerciseRoom,
        EntityManagerInterface $em,
        SessionRepository $sessionRepository,
        WaitingListService $waitingListService,
        LoggerInterface $logger,
    ): Response {
        $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);

        if ($request->isMethod('POST') && $request->request->getBoolean('_seat_editor')) {
            if (!$this->isCsrfTokenValid('exercise_room_seats_' . $exerciseRoom->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad inválido.');

                return $this->redirectToRoute('backend_exerciseroom_edit', [
                    'id' => $exerciseRoom->getId(),
                    '_fragment' => 'seat-layout-editor',
                ]);
            }

            $layout = SeatLayoutMapper::buildPersistedSeatLayout(
                $this->sanitizeSeatLayout($request->request->get('seat_layout'), $capacity),
                $capacity
            );

            $rawPlacesNotAvailable = $request->request->get('places_not_available', '');
            $placesNotAvailable = SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                array_filter(array_map('intval', array_filter(explode(',', (string) $rawPlacesNotAvailable), 'strlen'))),
                $capacity,
                $capacity,
            );

            $exerciseRoom
                ->setSeatLayout($layout)
                ->setPlacesNotAvailable($placesNotAvailable)
            ;

            $em->flush();

            $this->addFlash('success', 'La disposición de asientos del salón ha sido actualizada.');

            return $this->redirectToRoute('backend_exerciseroom_edit', [
                'id' => $exerciseRoom->getId(),
                '_fragment' => 'seat-layout-editor',
            ]);
        }

        $editForm = $this->createForm(ExerciseRoomType::class, $exerciseRoom);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $capacity = (int) ($exerciseRoom->getCapacity() ?? 0);
            $exerciseRoom->setSeatLayout(SeatLayoutMapper::buildPersistedSeatLayout(
                $this->sanitizeSeatLayout($exerciseRoom->getSeatLayout(), $capacity),
                $capacity
            ));
            $exerciseRoom->setPlacesNotAvailable(SeatLayoutMapper::buildPersistedPlacesNotAvailable(
                $exerciseRoom->getPlacesNotAvailable(),
                $capacity,
                $capacity,
            ));

            try {
                $updatedSessions = $sessionRepository->updateCapacity($exerciseRoom);
                $em->flush();

                $promotedReservations = 0;
                foreach ($sessionRepository->getFutureByExerciseRoom($exerciseRoom) as $futureSession) {
                    $promotedReservations += $waitingListService->promoteAvailablePlaces($futureSession);
                }
            } catch (\Throwable $exception) {
                $logger->error('[ExerciseRoomCapacity] Failed to sync session capacities after exercise room update.', [
                    'exerciseRoomId' => $exerciseRoom->getId(),
                    'capacity' => $exerciseRoom->getCapacity(),
                    'placesNotAvailable' => $exerciseRoom->getPlacesNotAvailable(),
                    'exceptionClass' => $exception::class,
                    'exceptionMessage' => $exception->getMessage(),
                    'exception' => $exception,
                ]);

                $this->addFlash('danger', 'No se pudo sincronizar la capacidad de las sesiones relacionadas. Intenta nuevamente.');

                return $this->render('backend/exerciseroom/edit.html.twig', [
                    'exerciseRoom' => $exerciseRoom,
                    'form' => $editForm->createView(),
                    'seat_layout' => SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoom->getSeatLayout(), $capacity),
                    'not_available' => SeatLayoutMapper::buildPersistedPlacesNotAvailable($exerciseRoom->getPlacesNotAvailable(), $capacity, $capacity) ?? [],
                ]);
            }

            $logger->info('[ExerciseRoomCapacity] Session capacities synchronized after exercise room update.', [
                'exerciseRoomId' => $exerciseRoom->getId(),
                'updatedSessions' => $updatedSessions,
                'promotedFromWaitingList' => $promotedReservations,
            ]);

            $this->addFlash('success', 'El Salón ha sido actualizado.');

            return $this->redirectToRoute('backend_exerciseroom_edit', [
                'id' => $exerciseRoom->getId(),
            ]);
        }

        return $this->render('backend/exerciseroom/edit.html.twig', [
            'exerciseRoom' => $exerciseRoom,
            'form' => $editForm->createView(),
            'seat_layout' => SeatLayoutMapper::buildPersistedSeatLayout($exerciseRoom->getSeatLayout(), $capacity),
            'not_available' => SeatLayoutMapper::buildPersistedPlacesNotAvailable($exerciseRoom->getPlacesNotAvailable(), $capacity, $capacity) ?? [],
        ]);
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
}
