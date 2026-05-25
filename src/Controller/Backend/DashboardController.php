<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Security\Voter\RouteAccessVoter;
use App\Service\Stats\StatsService;
use Carbon\CarbonImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StatsService $statsService,
    ) {
    }

    #[Route('/backend', name: 'backend_dashboard')]
    public function index(Request $request): Response
    {
        if ($this->isGranted('ROLE_INSTRUCTOR')) {
            return $this->redirectToRoute('backend_session');
        }

        $data = [];

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_transaction')) {
            $data['billing']          = $this->statsService->getBillingBlock();
            $data['discounts']        = $this->statsService->getBlock3DiscountsBySucursal();
            $data['payments']         = $this->statsService->getBlock4PaymentMethodsBySucursal();
            $data['lastTransactions'] = $this->statsService->getBlock2LastTransactions();
        }

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_session')) {
            $data['classes'] = $this->statsService->getBlock5Classes();
            $data['instructors'] = $this->statsService->getBlock6Instructors();
        }

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_user')) {
            $data['users']     = $this->statsService->getBlock9UserSummary();
            $data['lastUsers'] = $this->statsService->getBlock10LastUsers();
            $data['birthdays'] = $this->statsService->getBlock11Birthdays();
            $data['anniversaries'] = $this->statsService->getBlock12Anniversaries();
        }

        if ($this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_user_show')) {
            $data['fitpass']   = $this->statsService->getBlock7Fitpass();
            $data['wellhub']   = $this->statsService->getBlock7Wellhub();
            $data['totalpass'] = $this->statsService->getBlock7TotalPass();

            $nuFilters = $this->getDashboardNuFilters($request);
            $data['nuRetention']        = $this->statsService->getNewUserRetentionBlock($nuFilters['from'], $nuFilters['to']);
            $data['nuRetentionFilters'] = $nuFilters;
        }

        $rankingPermissions = [
            'weekdays' => $this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_session'),
            'schedules' => $this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_session'),
            'packages' => $this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_transaction'),
            'clients' => $this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_transaction'),
            'attendance' => $this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_session'),
            'ratingsDetail' => $this->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, 'backend_stats_ratings'),
        ];
        $rankingPermissions['showRanking'] =
            $rankingPermissions['weekdays']
            || $rankingPermissions['schedules']
            || $rankingPermissions['packages']
            || $rankingPermissions['clients']
            || $rankingPermissions['attendance'];

        if ($rankingPermissions['showRanking']) {
            $data['rankingPermissions'] = $rankingPermissions;
            $data['ratingFilters'] = $this->getDashboardRatingFilters($request);

            if ($rankingPermissions['weekdays']) {
                $data['ratingsWeekdays'] = $this->statsService->getBlock8WeekdaysBySucursal(
                    $data['ratingFilters']['from'],
                    $data['ratingFilters']['to']
                );
            }

            if ($rankingPermissions['schedules']) {
                $data['ratingsSchedules'] = $this->statsService->getBlock8SchedulesBySucursal(
                    $data['ratingFilters']['from'],
                    $data['ratingFilters']['to']
                );
            }

            if ($rankingPermissions['packages']) {
                $data['ratingsPackages'] = $this->statsService->getBlock8PackagesBySucursal(
                    $data['ratingFilters']['from'],
                    $data['ratingFilters']['to']
                );
            }

            if ($rankingPermissions['clients']) {
                $data['ratingsClients'] = $this->statsService->getBlock8ClientsBySucursal(
                    $data['ratingFilters']['from'],
                    $data['ratingFilters']['to']
                );
            }

            if ($rankingPermissions['attendance']) {
                $data['ratingsTopAttendance'] = $this->statsService->getBlock8TopAttendanceBySucursal(
                    $data['ratingFilters']['from'],
                    $data['ratingFilters']['to']
                );
            }
        }

        return $this->render('backend/dashboard/index.html.twig', $data);
    }

    private function getDashboardNuFilters(Request $request): array
    {
        $defaultDateStart = CarbonImmutable::today()->startOfMonth();
        $defaultDateEnd   = CarbonImmutable::today();

        $dateStartRaw = trim((string) $request->query->get('nu_date_start', ''));
        $dateEndRaw   = trim((string) $request->query->get('nu_date_end', ''));

        $dateStart = $this->parseDashboardRatingDate($dateStartRaw, $defaultDateStart);
        $dateEnd   = $this->parseDashboardRatingDate($dateEndRaw, $defaultDateEnd);

        if ($dateStart > $dateEnd) {
            [$dateStart, $dateEnd] = [$dateEnd, $dateStart];
        }

        return [
            'nu_date_start' => $dateStart->format('d/m/Y'),
            'nu_date_end'   => $dateEnd->format('d/m/Y'),
            'from'          => $dateStart,
            'to'            => $dateEnd,
        ];
    }

    private function getDashboardRatingFilters(Request $request): array
    {
        $defaultDateStart = CarbonImmutable::today()->startOfMonth();
        $defaultDateEnd = CarbonImmutable::today();

        $dateStartRaw = trim((string) $request->query->get('rating_date_start', ''));
        $dateEndRaw = trim((string) $request->query->get('rating_date_end', ''));

        $dateStart = $this->parseDashboardRatingDate($dateStartRaw, $defaultDateStart);
        $dateEnd = $this->parseDashboardRatingDate($dateEndRaw, $defaultDateEnd);

        if ($dateStart > $dateEnd) {
            [$dateStart, $dateEnd] = [$dateEnd, $dateStart];
        }

        return [
            'rating_date_start' => $dateStart->format('d/m/Y'),
            'rating_date_end' => $dateEnd->format('d/m/Y'),
            'from' => $dateStart,
            'to' => $dateEnd,
        ];
    }

    private function parseDashboardRatingDate(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ('' === $value) {
            return $fallback;
        }

        $date = CarbonImmutable::createFromFormat('d/m/Y', $value);
        if (false === $date) {
            return $fallback;
        }

        $today = CarbonImmutable::today();
        return $date->gt($today) ? $today : $date;
    }

    #[Route('/backend/access-denied', name: 'backend_access_denied')]
    public function accessDenied(): Response
    {
        return $this->render(
            'backend/security/access_denied.html.twig',
            [],
            new Response('', Response::HTTP_FORBIDDEN)
        );
    }
}
