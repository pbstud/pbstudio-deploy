<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HomeContent;
use App\Repository\HomeContentRepository;

/**
 * Resuelve el contenido editable del home.
 *
 * Devuelve el registro HomeContent de la BD, o valores fallback
 * para que el template siempre tenga datos validos.
 */
class HomeContentService
{
    // Valores por defecto (igual que el template hardcodeado original)
    public const DEFAULT_BOX1_TITLE       = 'P&B Studio Online';
    public const DEFAULT_BOX1_DESCRIPTION = 'El ejercicio más completo que te acompaña a donde quiera que vayas';
    public const DEFAULT_BOX1_URL         = 'https://www.pbstudio.online';
    public const DEFAULT_BOX1_LINK_LABEL  = 'Ir a P&B Studio Online';

    public const DEFAULT_BOX2_TITLE       = 'Strong Mom';
    public const DEFAULT_BOX2_DESCRIPTION = 'Te acompañamos durante todo tu embarazo y posparto';
    public const DEFAULT_BOX2_URL         = 'https://www.pbstudio.online';
    public const DEFAULT_BOX2_LINK_LABEL  = 'Ir a Strong Mom by P&B Studio';

    public const DEFAULT_CONTACT_EMAIL     = 'contacto@pbstudio.mx';
    public const DEFAULT_CONTACT_FACEBOOK  = 'https://www.facebook.com/pbstudiomx/';
    public const DEFAULT_CONTACT_INSTAGRAM = 'https://www.instagram.com/pbstudiomx/';
    public const DEFAULT_CONTACT_WHATSAPP  = 'https://wa.me/525552920036';

    public function __construct(private readonly HomeContentRepository $homeContentRepository)
    {
    }

    /**
     * Devuelve el registro HomeContent de la BD, o null si aun no existe.
     */
    public function find(): ?HomeContent
    {
        return $this->homeContentRepository->findSingle();
    }

    /**
     * Devuelve los datos del home para el template frontend.
     * Si hay un registro en BD, usa sus valores; si no, devuelve los defaults.
     *
     * @return array<string, mixed>
     */
    public function getTemplateData(): array
    {
        $hc = $this->homeContentRepository->findSingle();

        return [
            'bannerDesktop' => $hc?->getBannerDesktop(),
            'bannerMobile'  => $hc?->getBannerMobile(),

            'box1Image'       => $hc?->getBox1Image(),
            'box1Title'       => $hc?->getBox1Title()       ?: self::DEFAULT_BOX1_TITLE,
            'box1Description' => $hc?->getBox1Description() ?: self::DEFAULT_BOX1_DESCRIPTION,
            'box1Url'         => $hc?->getBox1Url()         ?: self::DEFAULT_BOX1_URL,
            'box1LinkLabel'   => $hc?->getBox1LinkLabel()   ?: self::DEFAULT_BOX1_LINK_LABEL,

            'box2Image'       => $hc?->getBox2Image(),
            'box2Title'       => $hc?->getBox2Title()       ?: self::DEFAULT_BOX2_TITLE,
            'box2Description' => $hc?->getBox2Description() ?: self::DEFAULT_BOX2_DESCRIPTION,
            'box2Url'         => $hc?->getBox2Url()         ?: self::DEFAULT_BOX2_URL,
            'box2LinkLabel'   => $hc?->getBox2LinkLabel()   ?: self::DEFAULT_BOX2_LINK_LABEL,

            'contactEmail'     => $hc?->getContactEmail()     ?: self::DEFAULT_CONTACT_EMAIL,
            'contactFacebook'  => $hc?->getContactFacebook()  ?: self::DEFAULT_CONTACT_FACEBOOK,
            'contactInstagram' => $hc?->getContactInstagram() ?: self::DEFAULT_CONTACT_INSTAGRAM,
            'contactWhatsapp'  => $hc?->getContactWhatsapp()  ?: self::DEFAULT_CONTACT_WHATSAPP,
        ];
    }
}
