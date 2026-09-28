<?php

declare(strict_types=1);

namespace MageOS\CheckoutGuard\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Throwable;

/**
 * Every order path (storefront checkout, REST, GraphQL, PayPal Express) funnels into
 * QuoteManagement::submit, so failed payment attempts are counted there, per quote. A quote that
 * fails the configured number of times is deactivated: a card-testing bot then needs a new cart,
 * address and shipping rate for every further attempt, and each step is logged.
 */
class PaymentFailureGuard
{
    private const XML_PATH_LIMIT = 'checkout/guard/payment_failures_limit';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly \Magento\Quote\Model\ResourceModel\Quote $quoteResource,
        private readonly \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException
     * @throws Throwable
     */
    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, array $orderData = [])
    {
        $limit = (int)$this->scopeConfig->getValue(self::XML_PATH_LIMIT);
        if ($limit > 0 && (int)$quote->getData('payment_failures') >= $limit) {
            $this->deactivate($quote);
            throw new LocalizedException(__('This cart can no longer be used. Please start a new order.'));
        }

        try {
            return $proceed($quote, $orderData);
        } catch (Throwable $exception) {
            if ($limit > 0 && $quote->getId()) {
                $this->recordFailure($quote, $limit, $exception);
            }
            throw $exception;
        }
    }

    private function recordFailure(Quote $quote, int $limit, Throwable $exception): void
    {
        $failures = (int)$quote->getData('payment_failures') + 1;
        $quote->setData('payment_failures', $failures);
        $data = ['payment_failures' => $failures];
        $deactivated = $failures >= $limit;
        if ($deactivated) {
            $quote->setIsActive(false);
            $data['is_active'] = 0;
        }
        $this->quoteResource->getConnection()->update(
            $this->quoteResource->getMainTable(),
            $data,
            ['entity_id = ?' => (int)$quote->getId()]
        );

        $this->logger->info($deactivated ? 'Quote deactivated after repeated payment failures' : 'Payment failure', [
            'quote_id' => (int)$quote->getId(),
            'store_id' => (int)$quote->getStoreId(),
            'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : 'guest',
            'email' => $this->maskEmail((string)$quote->getCustomerEmail()),
            'ip' => (string)$this->remoteAddress->getRemoteAddress(),
            'method' => (string)$quote->getPayment()->getMethod(),
            'failures' => $failures,
            'limit' => $limit,
            'grand_total' => (float)$quote->getGrandTotal(),
            'reason' => mb_substr($exception->getMessage(), 0, 200),
        ]);
    }

    private function deactivate(Quote $quote): void
    {
        if (!$quote->getIsActive()) {
            return;
        }
        $quote->setIsActive(false);
        $this->quoteResource->getConnection()->update(
            $this->quoteResource->getMainTable(),
            ['is_active' => 0],
            ['entity_id = ?' => (int)$quote->getId()]
        );
        $this->logger->info('Quote deactivated: attempt after the limit', [
            'quote_id' => (int)$quote->getId(),
            'ip' => (string)$this->remoteAddress->getRemoteAddress(),
        ]);
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return $email === '' ? '' : '***';
        }

        return substr($email, 0, min(2, $at)) . '***' . substr($email, $at);
    }
}
