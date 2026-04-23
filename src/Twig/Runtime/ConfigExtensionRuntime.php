<?php

declare(strict_types=1);

namespace App\Twig\Runtime;

use App\Entity\Configuration;
use App\Model\ConfigurationFileModel;
use App\Repository\ConfigurationRepository;
use App\Service\HomeContentService;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

readonly class ConfigExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private HomeContentService $homeContentService,
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
}
