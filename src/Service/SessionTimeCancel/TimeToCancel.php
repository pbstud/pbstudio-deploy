<?php

declare(strict_types=1);

namespace App\Service\SessionTimeCancel;

use App\Entity\Configuration;
use App\Entity\Session;
use App\Repository\ConfigurationRepository;

/**
 * Time To Cancel.
 */
class TimeToCancel
{
    private array $configuration = [];

    public function __construct(
        private readonly ConfigurationRepository $configurationRepository,
    ) {
        $this->init();
    }

    /**
     * @return TransformTime
     */
    public function getTimeToCancelIndividual(): TransformTime
    {
        return $this->configuration['time_cancel_individual'];
    }

    /**
     * @return TransformTime
     */
    public function getTimeToCancelGroup(): TransformTime
    {
        return $this->configuration['time_cancel_group'];
    }

    /**
     * Init.
     */
    private function init(): void
    {
        $defaults = [
            'time_cancel_individual' => Session::TIME_CANCEL_INDIVIDUAL,
            'time_cancel_group' => Session::TIME_CANCEL_GROUP,
        ];

        /** @var Configuration $configuration */
        $configuration = $this->configurationRepository->findSessions();

        if ($configuration) {
            $configuration = $configuration->getData();

            if (0 < (int) $configuration['time_cancel_individual']) {
                $defaults['time_cancel_individual'] = (int) $configuration['time_cancel_individual'];
            }

            if (0 < (int) $configuration['time_cancel_group']) {
                $defaults['time_cancel_group'] = (int) $configuration['time_cancel_group'];
            }
        }

        $this->configuration['time_cancel_individual'] = new TransformTime($defaults['time_cancel_individual']);
        $this->configuration['time_cancel_group'] = new TransformTime($defaults['time_cancel_group']);
    }
}
