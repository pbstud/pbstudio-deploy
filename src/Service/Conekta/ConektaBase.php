<?php

declare(strict_types=1);

namespace App\Service\Conekta;

use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;
use Conekta\Conekta;
use Conekta\Customer;
use Conekta\Handler;
use Conekta\Order;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Conekta Base.
 */
class ConektaBase extends Conekta
{
    private bool $sandboxMode;

    private string $sandboxSecretKey;

    private string $sandboxPublicKey;

    private string $productionSecretKey;

    private string $productionPublicKey;

    /**
     * Conekta Base constructor.
     */
    public function __construct(
        protected readonly EntityManagerInterface $em,
        private readonly ConfigurationRepository $configurationRepository,
        protected readonly LoggerInterface $logger
    ) {
        $this->init();
    }

    /**
     * @return string
     */
    public function getPublicKey(): string
    {
        return $this->getSandboxMode() ? $this->sandboxPublicKey : $this->productionPublicKey;
    }

    public function isSandboxModeEnabled(): bool
    {
        return $this->getSandboxMode();
    }

    /**
     * @param mixed $customerId
     *
     * @return mixed
     */
    public function getCustomer($customerId)
    {
        return Customer::find($customerId);
    }

    /**
     * @return string
     */
    protected function getSecretKey(): string
    {
        return $this->getSandboxMode() ? $this->sandboxSecretKey : $this->productionSecretKey;
    }

    /**
     * @param int|float $amount
     *
     * @return float|int
     */
    protected function formatDecimalAmount($amount)
    {
        $amount = (float) $amount * 1000;

        return intval(round((float) $amount / 10), 2);
    }

    /**
     * @param array $customerData
     *
     * @return mixed|\stdClass
     */
    protected function createCustomer(array $customerData)
    {
        $result = new \stdClass();

        try {
            $result = Customer::create($customerData);
        } catch (Handler $error) {
            $this->processError('Error de Conekta al crear cliente.', $error);

            $result->errorCode = $error->getCode();
            $result->errorMessage = $error->getMessage();
        }

        return $result;
    }

    /**
     * @param array $orderData
     *
     * @return mixed|\stdClass
     */
    protected function createOrder(array $orderData)
    {
        $result = new \stdClass();

        try {
            $result = Order::create($orderData);
        } catch (Handler $error) {
            $this->processError('Error de Conekta al crear orden.', $error);

            $result->errorCode = $error->getCode();
            $result->errorMessage = $error->getMessage();
        }

        return $result;
    }

    /**
     * @param string $orderId
     *
     * @return mixed
     */
    protected function getOrder($orderId)
    {
        return Order::find($orderId);
    }

    /**
     * @param string     $message
     * @param \Exception $exception
     */
    protected function processError(string $message, \Exception $exception): void
    {
        $this->logger->error($message, [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Initialize.
     */
    private function init(): void
    {
        /** @var Configuration $conekta */
        $conekta = $this->configurationRepository->findConekta();

        if (!$conekta) {
            throw new \RuntimeException('¡La configuración para conekta no existe!');
        }

        $conekta = $conekta->getData();
        $this->sandboxMode = (bool) $conekta['sandbox_mode'];
        $this->sandboxSecretKey = $conekta['sandbox_secret_key'];
        $this->sandboxPublicKey = $conekta['sandbox_public_key'];
        $this->productionSecretKey = $conekta['production_secret_key'];
        $this->productionPublicKey = $conekta['production_public_key'];

        self::setApiKey($this->getSecretKey());
    }

    /**
     * @return bool
     */
    private function getSandboxMode(): bool
    {
        return $this->sandboxMode;
    }
}
