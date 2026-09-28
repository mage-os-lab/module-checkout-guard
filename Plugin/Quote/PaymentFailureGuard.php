<?php

declare(strict_types=1);

namespace MageOS\CheckoutGuard\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use MageOS\CheckoutGuard\Model\Config\Source\FailureAction;
use Throwable;

/**
 * Every order path (storefront checkout, REST, GraphQL, PayPal Express) funnels into
 * QuoteManagement::submit, so failed payment attempts are counted there, per quote. At the
 * configured limit the quote is either deactivated (the customer starts a new cart) or reset (the
 * items stay, addresses, shipping and payment are cleared, the counter starts again). Either way a
 * card-testing bot has to rebuild the checkout for every further batch, and each step is logged.
 */
class PaymentFailureGuard
{
    private const XML_PATH_LIMIT = 'checkout/guard/payment_failures_limit';
    private const XML_PATH_ACTION = 'checkout/guard/payment_failures_action';

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
            $this->applyAction($quote, $limit, 'attempt after the limit');
            throw new LocalizedException(__('Too many failed payment attempts. Please review your details and start again.'));
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
        $this->quoteResource->getConnection()->update(
            $this->quoteResource->getMainTable(),
            ['payment_failures' => $failures],
            ['entity_id = ?' => (int)$quote->getId()]
        );
        $this->log('Payment failure', $quote, $limit, mb_substr($exception->getMessage(), 0, 200));

        if ($failures >= $limit) {
            $this->applyAction($quote, $limit, mb_substr($exception->getMessage(), 0, 200));
        }
    }

    private function applyAction(Quote $quote, int $limit, string $reason): void
    {
        if ($this->scopeConfig->getValue(self::XML_PATH_ACTION) === FailureAction::RESET) {
            $this->reset($quote);
            $this->log('Checkout reset after repeated payment failures', $quote, $limit, $reason);
            return;
        }

        $this->deactivate($quote);
        $this->log('Quote deactivated after repeated payment failures', $quote, $limit, $reason);
    }

    private function deactivate(Quote $quote): void
    {
        $quote->setIsActive(false);
        $this->quoteResource->getConnection()->update(
            $this->quoteResource->getMainTable(),
            ['is_active' => 0],
            ['entity_id = ?' => (int)$quote->getId()]
        );
    }

    private function reset(Quote $quote): void
    {
        $blank = array_fill_keys(
            ['firstname', 'lastname', 'company', 'street', 'city', 'region', 'region_id', 'postcode', 'country_id', 'telephone', 'shipping_method', 'shipping_description'],
            null
        );
        $quote->getBillingAddress()->addData($blank);
        $quote->getShippingAddress()->addData($blank)->setCollectShippingRates(true);
        $quote->getPayment()->setMethod(null)->setAdditionalInformation([]);
        $quote->setData('payment_failures', 0);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quoteResource->save($quote);
    }

    private function log(string $message, Quote $quote, int $limit, string $reason): void
    {
        $this->logger->info($message, [
            'quote_id' => (int)$quote->getId(),
            'store_id' => (int)$quote->getStoreId(),
            'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : 'guest',
            'email' => $this->maskEmail((string)$quote->getCustomerEmail()),
            'ip' => (string)$this->remoteAddress->getRemoteAddress(),
            'method' => (string)$quote->getPayment()->getMethod(),
            'failures' => (int)$quote->getData('payment_failures'),
            'limit' => $limit,
            'grand_total' => (float)$quote->getGrandTotal(),
            'reason' => $reason,
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
