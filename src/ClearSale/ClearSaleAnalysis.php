<?php

namespace ClearSale;

class ClearSaleAnalysis
{
    const APROVADO = 'Aprovado';
    const AGUARDANDO_APROVACAO = 'Aguardando aprovação';
    const REPROVADO = 'Reprovado';
    const ERRO = 'Erro';

    private static $paymentStatus = array(
        self::APROVADO,
        self::REPROVADO,
    );

    private $entityCode;
    private $environment;
    private $isDebug;
    private $clearSaleService;
    private $clearSalePaymentIntegration;
    private $packageStatusResponse;

    /**
     * Construtor para gerar a integração com a ClearSale
     *
     * @param string $entityCode - Código gerado pela ClearSale
     * @param string $environment
     * @param bool $isDebug
     * @throws InvalidArgumentException
     */
    public function __construct($entityCode, $environment, $isDebug = false)
    {
        $this->entityCode = $entityCode;
        $this->isDebug = $isDebug;

        $this->environment = new Environment($environment);
        $this->clearSaleService = new ClearSaleService($this->entityCode, $this->environment, $this->isDebug);
        $this->clearSalePaymentIntegration = new ClearSalePaymentIntegration($this->entityCode, $this->environment, $this->isDebug);
    }

    /**
     * Método para envio de pedidos e retorno do status
     *
     * @param Order $order
     * @return string
     */
    public function analysis(Order $order)
    {
        $this->sendOrder($order);

        return $this->getOrderStatus($order->getId());
    }

    /**
     * Retorna o status de aprovação de um pedido
     *
     * @param string $orderId
     * @return string
     */
    public function getOrderStatus($orderId) {
        $this->packageStatusResponse = $this->clearSaleService->getOrderStatus($orderId);

        // TODO: Implement log -> $this->packageStatusResponse->getOrder()->getStatus()
        // TODO: Implement log -> $this->packageStatusResponse->getOrder()->getScore()

        if ($this->approved($this->packageStatusResponse)) {
            return self::APROVADO;
        } elseif ($this->notApproved($this->packageStatusResponse)) {
            return self::REPROVADO;
        } elseif ($this->waitingForApproval($this->packageStatusResponse)) {
            return self::AGUARDANDO_APROVACAO;
        } else {
            return self::ERRO;
        }
    }

    /**
     * Retorna os detalhes do pedido após o pedido de análise
     *
     * @return \ClearSale\PackageStatus
     */
    public function getPackageStatus()
    {
        return $this->packageStatusResponse;
    }

    /**
     * Método para atualizar o pedido com o status do pagamento
     *
     * @param string $orderId
     * @param string $paymentStatus
     * @return \ClearSale\OrderReturn
     */
    public function updateOrderStatusId($orderId, $paymentStatus)
    {
        if (!in_array($paymentStatus, self::$paymentStatus)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid payment status (%s)', $paymentStatus)
            );
        }

        return $this->clearSalePaymentIntegration->updateOrderStatusId($orderId, $paymentStatus);
    }

    private function sendOrder(Order $order) {
        $packageStatus = $this->clearSaleService->sendOrders($order);

        if ($packageStatus->getStatusCode() != PackageStatus::STATUS_CODE_TRANSACAO_CONCLUIDA) {
            throw new \Exception(
                sprintf('Transaction Failed! (statusCode: %s)', $packageStatus->getStatusCode())
            );
        }
    }

    private function approved(PackageStatus $packageStatus)
    {
        switch ($packageStatus->getOrder()->getStatus()) {
            case OrderReturn::STATUS_SAIDA_APROVACAO_AUTOMATICA:
            case OrderReturn::STATUS_SAIDA_APROVACAO_MANUAL:
                return true;
            default:
                return false;
        }
    }

    private function notApproved(PackageStatus $packageStatus)
    {
        switch ($packageStatus->getOrder()->getStatus()) {
            case OrderReturn::STATUS_SAIDA_REPROVADA_SEM_SUSPEITA:
            case OrderReturn::STATUS_SAIDA_SUSPENSAO_MANUAL:
            case OrderReturn::STATUS_SAIDA_CANCELADO_PELO_CLIENTE:
            case OrderReturn::STATUS_SAIDA_FRAUDE_CONFIRMADA:
            case OrderReturn::STATUS_SAIDA_REPROVACAO_AUTOMATICA:
            case OrderReturn::STATUS_SAIDA_REPROVACAO_POR_POLITICA:
                return true;
            default:
                return false;
        }
    }

    private function waitingForApproval(PackageStatus $packageStatus)
    {
        switch ($packageStatus->getOrder()->getStatus()) {
            case OrderReturn::STATUS_SAIDA_ANALISE_MANUAL:
            case OrderReturn::STATUS_SAIDA_NOVO:
                return true;
            default:
                return false;
        }
    }
}
