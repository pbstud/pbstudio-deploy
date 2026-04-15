<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Twig\Runtime\ConfigExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class ConfigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('config_header_scripts', [ConfigExtensionRuntime::class, 'getHeaderScripts'], [
                'is_safe' => ['html'],
            ]),
            new TwigFunction('config_footer_scripts', [ConfigExtensionRuntime::class, 'getFooterScripts'], [
                'is_safe' => ['html'],
            ]),
            new TwigFunction('config_notice', [ConfigExtensionRuntime::class, 'getNotice'], [
                'is_safe' => ['html'],
                'needs_environment' => true,
            ]),
        ];
    }
}
