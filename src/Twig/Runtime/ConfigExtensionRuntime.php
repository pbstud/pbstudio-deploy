<?php

declare(strict_types=1);

namespace App\Twig\Runtime;

use App\Entity\Configuration;
use App\Model\ConfigurationFileModel;
use App\Repository\BranchOfficeRepository;
use App\Repository\ConfigurationRepository;
use App\Service\HomeContentService;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

readonly class ConfigExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private HomeContentService $homeContentService,
        private BranchOfficeRepository $branchOfficeRepository,
    )
    {
    }

    public function getHeaderScripts()
    {
        $general = $this->configurationRepository->findGeneral()?->getData();

        return $general['header_scripts'] ?? null;
    }

    public function getFooterScripts()
    {
        $general = $this->configurationRepository->findGeneral()?->getData();

        return $general['footer_scripts'] ?? null;
    }

    public function getNotice(Environment $twig): ?string
    {
        $notice = $this->configurationRepository->findNotice()?->getData();

        if (!$notice || !$notice['active']) {
            return null;
        }

        $imageName = (string) ($notice['image'] ?? '');

        if ('' === $imageName) {
            return null;
        }

        $imagePath = sprintf(
            '%s/public/media/uploads/site/%s',
            dirname(__DIR__, 3),
            $imageName,
        );

        if (!is_file($imagePath)) {
            return null;
        }

        $notice['notice_id'] = sha1(implode('|', [
            $imageName,
            (string) ($notice['url'] ?? ''),
            (string) ($notice['active'] ?? ''),
        ]));

        $configFile = new ConfigurationFileModel();
        $configFile->setName($imageName);
        $notice['image'] = $configFile;

        return $twig->render('default/_modal_notice.html.twig', $notice);
    }

    public function getWhatsappUrl(): string
    {
        $data = $this->homeContentService->getTemplateData();

        return (string) ($data['contactWhatsapp'] ?? HomeContentService::DEFAULT_CONTACT_WHATSAPP);
    }

    /**
     * Extracts and formats the local phone number from the WhatsApp URL.
     * e.g. https://wa.me/525512345678 → 55 1234 5678
     */
    public function getWhatsappPhone(): string
    {
        $url = $this->getWhatsappUrl();
        // Strip everything up to and including 'wa.me/' then remove MX country code '52'
        $digits = preg_replace('/^.*wa\.me\/52/', '', $url);
        $digits = preg_replace('/[^0-9]/', '', (string) $digits);

        if (strlen($digits) === 10) {
            // Format as XX XXXX XXXX
            return substr($digits, 0, 2) . ' ' . substr($digits, 2, 4) . ' ' . substr($digits, 6, 4);
        }

        return $digits;
    }

    public function getFirstPublicBranchSlug(): string
    {
        $branches = $this->branchOfficeRepository->getPublic();

        return $branches[0]?->getSlug() ?? '';
    }
}
