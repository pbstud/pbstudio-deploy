<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Reservation;
use App\Entity\Session;
use App\Entity\Transaction;
use App\Entity\User;
use App\Event\ReservationCanceledEvent;
use App\Form\Backend\UserType;
use App\Form\RegistrationFormType;
use App\Repository\BranchOfficeRepository;
use App\Repository\DisciplineRepository;
use App\Repository\ExerciseRoomRepository;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Repository\StaffRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\Reservation\ReservationException;
use App\Service\Reservation\ReservationService;
use App\Util\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/user')]
class UserController extends AbstractController
{
    #[Route('/', name: 'backend_user', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function index(
        PaginatorInterface $paginator,
        UserRepository $userRepository,
        BranchOfficeRepository $branchOfficeRepository,
        #[MapQueryParameter] array $filters = [],
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $users = $userRepository->findWithFilters($filters);
        $pagination = $paginator->paginate($users, $page, User::NUMBER_OF_ITEMS);

        return $this->render('backend/user/index.html.twig', [
            'filters' => $filters,
            'filter_enabled' => User::enabledChoices(),
            'branch_offices' => $branchOfficeRepository->getAll(),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'backend_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function new(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $encoded = $passwordHasher->hashPassword($user, $user->getPlainPassword());

            $user
                ->setPassword($encoded)
                ->setEnabled(true)
            ;

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'El Usuario ha sido creado.');

            return $this->redirectToRoute('backend_user_new', [
                'id' => $user->getId(),
            ]);
        }

        return $this->render('backend/user/new.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/export', name: 'backend_user_export', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function export(
        UserRepository $userRepository,
        #[MapQueryParameter] array $filters = [],
    ): Response {
        $rows = $userRepository->export($filters);

        $filename = sprintf('Usuarios_%s.xlsx', date('Y-m-d_H-i'));
        $tmpFile = sys_get_temp_dir() . '/' . uniqid('users_export_', true) . '.xlsx';

        try {
            $writer = new Writer();
            $writer->openToFile($tmpFile);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Usuarios');

            // Header row
            $writer->addRow(Row::fromValues([
                'ID',
                'Nombre',
                'Apellidos',
                'Teléfono',
                'F. Cumpleaños',
                'Email',
                'Estado',
                'Sucursal',
            ]));

            // Data rows
            foreach ($rows as $row) {
                $status = $row['enabled'] ? 'Activo' : 'Inactivo';
                $birthday = '';
                if ($row['birthday']) {
                    [, $month, $day] = explode('-', $row['birthday']);
                    $birthday = sprintf('%s/%s', $day, $month);
                }

                $writer->addRow(Row::fromValues([
                    $row['id'],
                    $row['name'],
                    $row['lastname'],
                    $row['phone'],
                    $birthday,
                    $row['email'],
                    $status,
                    $row['branchOffice'] ?? 'N/A',
                ]));
            }

            // Set column widths
            $sheet->setColumnWidth(8, 1); // ID
            $sheet->setColumnWidth(15, 2); // Nombre
            $sheet->setColumnWidth(20, 3); // Apellidos
            $sheet->setColumnWidth(15, 4); // Teléfono
            $sheet->setColumnWidth(15, 5); // F. Cumpleaños
            $sheet->setColumnWidth(20, 6); // Email
            $sheet->setColumnWidth(12, 7); // Estado
            $sheet->setColumnWidth(16, 8); // Sucursal

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

    #[Route('/{id}', name: 'backend_user_show', methods: ['GET'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function show(User $user): Response
    {
        $toggleEnableForm = $this->createToggleEnableForm($user);

        return $this->render('backend/user/show.html.twig', [
            'user' => $user,
            'toggle_enable_form' => $toggleEnableForm->createView(),
        ]);
    }

    #[Route('/{id}', name: 'backend_user_toggle_enable', methods: ['POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function toggleEnable(Request $request, User $user, EntityManagerInterface $em): Response
    {
        $form = $this->createToggleEnableForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setEnabled(!$user->isEnabled());

            $em->flush();

            $action = $user->isEnabled() ? 'habilitado' : 'deshabilitado';
            $this->addFlash('success', sprintf('El usuario ha sido %s.', $action));
        } else {
            $this->addFlash('danger', 'No se han recibido datos.');
        }

        return $this->redirectToRoute('backend_user_show', [
            'id' => $user->getId(),
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_user_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function edit(Request $request, User $user, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'El usuario ha sido actualizado.');

            return $this->redirectToRoute('backend_user_edit', [
                'id' => $user->getId(),
            ]);
        }

        return $this->render('backend/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/reset-password', name: 'backend_user_reset_password', methods: ['POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function resetPassword(User $user, EntityManagerInterface $em, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backend_user_reset_password', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$user->isPasswordRequestNonExpired($this->getParameter('resetting_retry_ttl'))) {
            $user
                ->setConfirmationToken(TokenGenerator::generateToken())
                ->setPasswordRequestedAt(new \DateTime())
            ;

            $em->persist($user);
            $em->flush();
        }

        $url = $this->generateUrl('resetting_reset', [
            'token' => $user->getConfirmationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json([
            'url' => $url,
        ]);
    }

    #[Route('/{id}/stats', name: 'backend_user_stats', methods: ['GET'])]
    public function stats(
        User $user,
        TransactionRepository $transactionRepository,
        ReservationRepository $reservationRepository,
    ): Response {
        $data = [
            'user' => $user,
            'sessions' => $transactionRepository->getCountSessionsAvailableByUser($user),
            'sessions_used' => $reservationRepository->getSessionsTakenByUser($user, true),
            'reserved_sessions' => $reservationRepository->getReservedSessionsByUser($user, true),
        ];

        $data['sessions_available'] = $data['sessions'] - ($data['sessions_used'] + $data['reserved_sessions']);
        $data['sessions_available'] = max(0, $data['sessions_available']);

        return $this->render('backend/user/stats.html.twig', $data);
    }

    #[Route('/{id}/transactions', name: 'backend_user_transactions', methods: ['GET'])]
    public function transactions(
        User $user,
        TransactionRepository $transactionRepository,
        PaginatorInterface $paginator,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $transactions = $paginator->paginate(
            $transactionRepository->paginateByUser($user),
            $page,
            Transaction::NUMBER_OF_ITEMS_USER
        );

        return $this->render('backend/user/transactions.html.twig', [
            'user' => $user,
            'transactions' => $transactions,
        ]);
    }

    #[Route('/{id}/reservations', name: 'backend_user_reservations', methods: ['GET'])]
    public function reservations(
        Request $request,
        User $user,
        PaginatorInterface $paginator,
        ReservationRepository $reservationRepository,
        BranchOfficeRepository $branchOfficeRepository,
        ExerciseRoomRepository $exerciseRoomRepository,
    ): Response {
        $view = 'wrapper_list.html.twig';
        $data['user'] = $user;

        // Filters
        $filters = [];
        $filters['filter_status'] = $request->query->get('filter_status');
        $filters['filter_session_date_start'] = $request->query->get('filter_session_date_start');
        $filters['filter_session_date_end'] = $request->query->get('filter_session_date_end');
        $filters['filter_branch_office'] = $request->query->get('filter_branch_office');
        $filters['filter_exercise_room'] = $request->query->get('filter_exercise_room');
        $filters['filter_attended'] = $request->query->get('filter_attended');

        if ($request->query->has('filter_status') && !$request->query->has('embedded')) {
            $view = 'list.html.twig';

            $reservations = $reservationRepository->getByUserList($user, $filters);

            $reservations = $paginator->paginate(
                $reservations,
                $request->query->getInt('page', 1),
                User::NUMBER_OF_ITEMS
            );

            $data['reservations'] = $reservations;
        }

        $data['branch_offices'] = $branchOfficeRepository->getAll();

        $exerciseRoomsGrouped = [];
        $exerciseRooms = $exerciseRoomRepository->getAll();
        foreach ($exerciseRooms as $exerciseRoom) {
            $exerciseRoomsGrouped[$exerciseRoom->getBranchOffice()->getName()][] = $exerciseRoom;
        }
        $data['exercise_rooms'] = $exerciseRoomsGrouped;

        return $this->render('backend/user/reservation/'.$view, $filters + $data);
    }

    #[Route('/{id}/reservations/new', name: 'backend_user_reservation_new', methods: ['GET', 'POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function reservationNew(
        Request $request,
        User $user,
        ReservationService $reservationService,
        SessionRepository $sessionRepository,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
        BranchOfficeRepository $branchOfficeRepository,
    ) {
        if ('POST' === $request->getMethod()) {
            /** @var Session $session */
            $session = $sessionRepository->find($request->request->get('session_id'));
            $place = $request->request->getInt('place');
            $json = [];

            try {
                $reservationService->reservate($user, $session, $place);
            } catch (ReservationException $e) {
                $json['error'] = $e->getMessage();
            }

            return $this->json($json);
        }

        $disciplines = $disciplineRepository->findByIsActive(true);
        $instructors = $staffRepository->getAllActiveInstructors();
        $branchOffices = $branchOfficeRepository->findAll();

        return $this->render('backend/user/reservation/new.html.twig', [
            'disciplines' => $disciplines,
            'instructors' => $instructors,
            'branchOffices' => $branchOffices,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/reservations/{reservation}', name: 'backend_user_reservation_detail', methods: ['GET'])]
    public function reservationDetail(User $user, Reservation $reservation): Response
    {
        $cancelForm = $this->createReservationCancelForm($reservation);

        return $this->render('backend/user/reservation/detail.html.twig', [
            'user' => $user,
            'reservation' => $reservation,
            'session' => $reservation->getSession(),
            'cancel_form' => $cancelForm->createView(),
        ]);
    }

    #[Route('/{id}/reservations/{reservation}/cancel', name: 'backend_user_reservation_cancel', methods: ['POST'])]
    #[IsGranted('ALLOWED_ROUTE_ACCESS')]
    public function reservationCancel(Request $request, User $user, Reservation $reservation, ReservationService $reservationService): Response
    {
        $form = $this->createReservationCancelForm($reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $json = [];

                $reservationService->cancel($reservation, ReservationCanceledEvent::SOURCE_STAFF);
            } catch (ReservationException $e) {
                $json['error'] = $e->getMessage();
            }

            return $this->json($json);
        }

        return $this->redirectToRoute('backend_user');
    }

    /**
     * Disable / Enable User.
     *
     * @param User $user
     *
     * @return FormInterface
     */
    private function createToggleEnableForm(User $user): FormInterface
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('backend_user_toggle_enable', [
                'id' => $user->getId(),
            ]))
            ->getForm();
    }

    /**
     * Creates a form to cancel a reservation entity.
     *
     * @param Reservation $reservation The reservation entity
     *
     * @return FormInterface
     */
    private function createReservationCancelForm(Reservation $reservation): FormInterface
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('backend_user_reservation_cancel', [
                'id' => $reservation->getUser()->getId(),
                'reservation' => $reservation->getId(),
            ]))
            ->getForm();
    }
}
