<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Repository\SessionRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Security\Voter\RouteAccessVoter;
use App\Service\SessionTimeCancel\TimeToCancel;
use App\Util\PackageSessionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('/backend', name: 'backend_dashboard')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_INSTRUCTOR')) {
            return $this->redirectToRoute('backend_session');
        }

        $data = [];

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'dashboard_stats')) {
            $data['stats'] = $this->getStats();
        }

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_transaction')) {
            $data['lastTransactions'] = $this->getLastTransactions();
        }

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_user')) {
            $data['lastUsers'] = $this->getLastUsers();
        }

        return $this->render('backend/dashboard/index.html.twig', $data);
    }

    #[Route('/backend/test', name: 'backend_dashboard_test')]
    public function backTest(Request $request, SessionRepository $sessionRepository, TimeToCancel $sessionTimeToCancel)
    {
        $session = $sessionRepository->find($request->query->get('id'));

        $currentDate = new \DateTime();
        var_dump('Current: ', $currentDate);

        $dateStartValue = $session->getDateStart();
        $timeStartValue = $session->getTimeStart();

        if (!$dateStartValue || !$timeStartValue) {
            throw $this->createNotFoundException('La sesión no tiene fecha u hora de inicio configurada.');
        }

        $dateStart = \DateTimeImmutable::createFromInterface($dateStartValue);
        var_dump('Date start: ', $dateStart);
        $dateStart = $dateStart->setTime((int) $timeStartValue->format('H'), (int) $timeStartValue->format('i'));
        var_dump('Date start 2: ', $dateStart);

        $diffSeconds = $dateStart->getTimestamp() - $currentDate->getTimestamp();
        var_dump('Diff seconds: ', $diffSeconds);

        $timeToCancel = PackageSessionType::TYPE_INDIVIDUAL === $session->getType() ?
            $sessionTimeToCancel->getTimeToCancelIndividual() :
            $sessionTimeToCancel->getTimeToCancelGroup()
        ;

        var_dump('Time cancel: ', $timeToCancel);
        phpinfo();
        exit();
    }

    private function getStats(): array
    {
        $currentDate = new \DateTime('first day of this month');
        $endDate = new \DateTime('last day of this month');

        return [
            'totalAmountProcessed' => $this->transactionRepository->getTotalAmount(),
            'totalAmountInCurrentMonth' => $this->transactionRepository->getTotalAmountForRageDate($currentDate, $endDate),
            'totalActiveUsers' => $this->userRepository->getTotalActiveUsers(),
        ];
    }

    private function getLastTransactions(): array
    {
        return $this->transactionRepository->getLastCompleted();
    }

    private function getLastUsers(): array
    {
        return $this->userRepository->getLastUsers();
    }
}
