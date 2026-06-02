<?php

declare(strict_types=1);

namespace App\Menu;

use App\Security\Voter\RouteAccessVoter;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class BackendBuilder
{
    private ItemInterface $menu;

    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly Security $security,
    ) {
    }

    public function mainMenu(): ItemInterface
    {
        $this->menu = $this->factory->createItem('root', [
            'childrenAttributes' => [
                'class' => 'nav side-menu',
            ],
            'compressed' => true,
        ]);

        // Dashboard
        $this->addMenu('dashboard', 'home', 'backend_dashboard', true);

        // Catalog
        $this->createCatalogMenu();

        // User
        $this->createUserMenu();

        // Payment
        $this->createPaymentMenu();

        // System
        $this->createSystemMenu();

        return $this->menu;
    }

    /**
     * Catalog menu.
     */
    private function createCatalogMenu(): void
    {
        /** @var ItemInterface $menu */
        $menu = $this->addMenu('catalog', 'book');

        $submenu = [
            'backend_branchoffice' => [
                'backend_branchoffice_new',
                'backend_branchoffice_edit',
            ],
            'backend_discipline' => [
                'backend_discipline_new',
                'backend_discipline_edit',
            ],
            'backend_exerciseroom' => [
                'backend_exerciseroom_new',
                'backend_exerciseroom_edit',
            ],
            'backend_package' => [
                'backend_package_new',
                'backend_package_edit',
            ],
            'backend_instructor' => [
                'backend_instructor_new',
                'backend_instructor_edit',
            ],
            'backend_session' => [
                'backend_session_new',
                'backend_session_edit',
                'backend_session_reservations',
                'backend_session_waitinglist',
            ],
            'backend_session_day' => [
                'backend_session_day_new_branch_office',
                'backend_session_day_new',
                'backend_session_day_edit',
            ],
            'backend_stats_ratings' => [],
        ];

        $this->addSubmenu($menu, $submenu);
    }

    /**
     * User menu.
     */
    private function createUserMenu(): void
    {
        /** @var ItemInterface $menu */
        $menu = $this->addMenu('user', 'users');

        $submenu = [
            'backend_user' => [
                'backend_user_show',
                'backend_user_edit',
            ],
            'backend_user_new'     => [],
            'backend_user_ranking' => [],
        ];

        $this->addSubmenu($menu, $submenu);
    }

    /**
     * Payment menu.
     */
    private function createPaymentMenu(): void
    {
        /** @var ItemInterface $menu */
        $menu = $this->addMenu('payment', 'usd');

        $submenu = [
            'backend_transaction' => [
                'backend_transaction_show',
            ],
            'backend_transaction_new' => [],
            'backend_gift_card' => [
                'backend_gift_card_show',
                'backend_gift_card_resend',
                'backend_gift_card_cancel',
            ],
        ];

        $this->addSubmenu($menu, $submenu);
    }

    /**
     * System menu.
     */
    private function createSystemMenu(): void
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            /** @var ItemInterface $menu */
            $menu = $this->addMenu('system', 'cog');

            $submenu = [
                'backend_page' => [
                    'backend_page_new',
                    'backend_page_edit',
                ],
                'backend_staff' => [
                    'backend_staff_new',
                    'backend_staff_edit',
                ],
                'backend_coupon' => [
                    'backend_coupon_new',
                    'backend_coupon_edit',
                    'backend_coupon_show',
                ],
                'backend_achievement' => [
                    'backend_achievement_new',
                    'backend_achievement_edit',
                ],
                'backend_configuration' => [],
            ];

            $this->addSubmenu($menu, $submenu);
        }
    }

    private function addMenu(string $name, string $icon, ?string $route = null, bool $secure = false, array $options = []): ?ItemInterface
    {
        if ($secure && !$this->isAllowed($route)) {
            return null;
        }

        $defaultOptions = [
            'label' => sprintf('main.%s', $name),
            'uri' => 'javascript:void(0);',
            'childrenAttributes' => [
                'class' => 'nav child_menu',
            ],
            'extras' => [
                'icon' => $icon,
            ],
            'route' => $route,
        ];

        $options = array_merge($defaultOptions, $options);

        return $this->menu->addChild($name, $options);
    }

    private function addSubmenu(ItemInterface $item, array $submenu): void
    {
        foreach ($submenu as $route => $children) {
            if (!$this->isAllowed($route)) {
                continue;
            }

            $child = $item->addChild($route, [
                'label' => sprintf('main.%s', $route),
                'route' => $route,
            ]);

            $this->addChildren($child, $children);
        }

        if (0 === $item->count()) {
            $this->menu->removeChild($item);
        }
    }

    private function addChildren(ItemInterface $item, array $children): void
    {
        $item->setDisplayChildren(false);

        // Used for parent active.
        foreach ($children as $routeChild) {
            $item->addChild($routeChild, [
                'label' => sprintf('main.%s', $routeChild),
                'uri' => 'javascript:void(0);',
                'extras' => [
                    'routes' => [$routeChild],
                    'last_child' => true,
                ],
            ]);
        }
    }

    private function isAllowed(?string $route): bool
    {
        return $route && $this->security->isGranted(RouteAccessVoter::ALLOWED_ROUTE_ACCESS, $route);
    }
}
