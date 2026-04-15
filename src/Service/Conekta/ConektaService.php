<?php

declare(strict_types=1);

namespace App\Service\Conekta;

use App\Entity\Transaction;
use App\Entity\User;
use App\Util\PackageSessionType;
use Conekta\Handler;

/**
 * Conekta Service.
 */
class ConektaService extends ConektaBase
{
    /**
     * Realiza la petición de una transacción a conekta.
     *
     * @param Transaction $transaction
     * @param string      $conektaCardToken
     *
     * @return Transaction
     */
    public function chargeCard(Transaction $transaction, string $conektaCardToken): Transaction
    {
        try {
            /** @var User $user */
            $user = $transaction->getUser();

            // Conekta customer
            if (!$user->getConektaId()) {
                $conektaCustomer = $this->createConektaCustomer($user, $transaction, $conektaCardToken);
                $conektaCustomerCard = $conektaCustomer->payment_sources[0] ?? null;
            } else {
                $conektaCustomer = $this->getCustomer($user->getConektaId());

                $conektaCustomerCard = $conektaCustomer->createPaymentSource([
                    'type' => 'card',
                    'token_id' => $conektaCardToken,
                ]);
            }

            if (null === $conektaCustomerCard) {
                $transaction
                    ->setStatus(Transaction::STATUS_DENIED)
                    ->setErrorMessage('No se pudo crear el metodo de pago en Conekta.');

                throw new \Exception('No se pudo crear el metodo de pago en Conekta.');
            }

            // Conekta customer card
            $transaction
                ->setCardName($conektaCustomerCard->name ?? null)
                ->setCardType($conektaCustomerCard->type ?? null)
                ->setCardBrand(isset($conektaCustomerCard->brand) ? mb_strtolower((string) $conektaCustomerCard->brand) : null)
                ->setCardLast4($conektaCustomerCard->last4 ?? null)
            ;

            // Save partial data.
            $this->em->persist($transaction);
            $this->em->flush();

            // Conekta order
            $conektaOrder = $this->createConektaOrder($transaction, $conektaCustomer->id, $conektaCustomerCard->id);

            // Se elimina la tarjeta asociada al customer.
            $conektaCustomerCard->delete();

            if (isset($conektaOrder->errorCode)) {
                $transaction
                    ->setStatus(Transaction::STATUS_DENIED)
                    ->setErrorCode($conektaOrder->errorCode)
                    ->setErrorMessage($conektaOrder->errorMessage)
                ;

                throw new \Exception($conektaOrder->errorMessage);
            }

            $paymentStatus = (string) ($conektaOrder->payment_status ?? '');
            $chargeStatus = (string) ($conektaOrder->charges[0]->status ?? '');
            $failureCode = (string) ($conektaOrder->charges[0]->failure_code ?? '');
            $failureMessage = (string) ($conektaOrder->charges[0]->failure_message ?? '');

            $this->logger->info('Conekta order status evaluation', [
                'transactionId' => $transaction->getId(),
                'orderId' => $conektaOrder->id ?? null,
                'paymentStatus' => $paymentStatus,
                'chargeStatus' => $chargeStatus,
                'failureCode' => $failureCode,
                'failureMessage' => $failureMessage,
            ]);

            if ('paid' !== mb_strtolower($paymentStatus) && 'paid' !== mb_strtolower($chargeStatus)) {
                $errorMessage = (string) ($conektaOrder->charges[0]->failure_message
                    ?? $conektaOrder->errorMessage
                    ?? 'Tu banco/proveedor rechazó el cargo.');
                $errorCode = (string) ($conektaOrder->charges[0]->failure_code
                    ?? $conektaOrder->errorCode
                    ?? $chargeStatus
                    ?? $paymentStatus);

                $transaction
                    ->setStatus(Transaction::STATUS_DENIED)
                    ->setErrorCode('' !== $errorCode ? $errorCode : null)
                    ->setErrorMessage($errorMessage)
                ;

                throw new \Exception($errorMessage);
            }

            $expirationAt = new \DateTime();
            $expirationAt->add(new \DateInterval(sprintf('P%sD', $transaction->getPackageDaysExpiry())));

            $transaction
                ->setChargeId($conektaOrder->id)
                ->setChargeAuthCode($conektaOrder->charges[0]->payment_method->auth_code)
                ->setStatus(Transaction::STATUS_PAID)
                ->setExpirationAt($expirationAt)
                ->setHaveSessionsAvailable(true)
            ;
        } catch (\Exception $e) {
            $this->processError('Error de Conekta al cobrar con tarjeta.', $e);

            if (empty($transaction->getErrorMessage())) {
                $transaction
                    ->setStatus(Transaction::STATUS_DENIED)
                    ->setErrorCode($e->getCode())
                    ->setErrorMessage($e->getMessage())
                ;
            }
        }

        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    /**
     * @param Transaction $transaction
     *
     * @return array
     */
    public function chargeRefund(Transaction $transaction): array
    {
        $response = [];

        try {
            $order = $this->getOrder($transaction->getChargeId());

            $order->refund();
        } catch (Handler $error) {
            $this->processError('Error de Conekta al reembolsar cargo.', $error);

            $response['error'] = [
                'code' => $error->getCode(),
                'message' => $error->getMessage(),
            ];
        } catch (\Throwable $error) {
            $this->processError('Error inesperado al reembolsar cargo.', $error);

            $response['error'] = [
                'code' => $error->getCode(),
                'message' => $error->getMessage(),
            ];
        }

        return $response;
    }

    /**
     * @param User        $user
     * @param Transaction $transaction
     * @param string      $conektaCardToken
     *
     * @return mixed|\stdClass
     *
     * @throws \Exception
     */
    private function createConektaCustomer(User $user, Transaction $transaction, string $conektaCardToken)
    {
        $conektaCustomer = $this->createCustomer([
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'payment_sources' => [
                [
                    'type' => 'card',
                    'token_id' => $conektaCardToken,
                ],
            ],
        ]);

        if (isset($conektaCustomer->errorCode) || isset($conektaCustomer->errorMessage) || empty($conektaCustomer->id)) {
            $errorMessage = (string) ($conektaCustomer->errorMessage ?? 'No se pudo crear el cliente en Conekta.');
            $errorCode = $conektaCustomer->errorCode ?? null;

            $transaction
                ->setStatus(Transaction::STATUS_DENIED)
                ->setErrorCode(null !== $errorCode ? (string) $errorCode : null)
                ->setErrorMessage($errorMessage)
            ;

            throw new \Exception($errorMessage);
        }

        $user->setConektaId($conektaCustomer->id);

        return $conektaCustomer;
    }

    /**
     * @param Transaction $transaction
     * @param string      $conektaCustomerId
     * @param string      $conektaCardId
     *
     * @return mixed|\stdClass
     */
    private function createConektaOrder(Transaction $transaction, string $conektaCustomerId, string $conektaCardId)
    {
        $total = $this->formatDecimalAmount($transaction->getTotal());

        // Conekta order
        $orderData = [
            'currency' => 'MXN',
            'line_items' => [
                [
                    'name' => sprintf(
                        'Paquete %s clase(s) %s(es)',
                        $transaction->isPackageIsUnlimited() ? '∞' : $transaction->getPackageTotalClasses(),
                        PackageSessionType::getDescription($transaction->getPackageType())
                    ),
                    'unit_price' => $total,
                    'quantity' => 1,
                ],
            ],
            'customer_info' => [
                'customer_id' => $conektaCustomerId,
            ],
            'metadata' => [
                'transaction_id' => $transaction->getId(),
            ],
            'charges' => [
                [
                    'payment_method' => [
                        'payment_source_id' => $conektaCardId,
                        'type' => 'card',
                    ],
                    'amount' => $total,
                ],
            ],
        ];

        $this->logger->info('Create order', $orderData);

        return $this->createOrder($orderData);
    }
}
