<?php

declare(strict_types=1);

namespace MageOS\CheckoutGuard\Plugin\Webapi;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\RequestValidatorInterface;

/**
 * A storefront checkout that places orders through its own transport (Magewire, a headless app with
 * its own API) leaves the core REST payment and order routes without a legitimate caller. Card
 * testing bots use exactly those routes, because the checkout form and its reCAPTCHA never run
 * there and core reCAPTCHA does not cover `PUT .../order` at all. With the switch on, the routes
 * answer 404 as if they did not exist.
 */
class BlockPaymentRoutes
{
    private const XML_PATH_ENABLED = 'checkout/guard/block_rest_payment_routes';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly array $blockedPathPatterns = []
    ) {
    }

    /**
     * @throws WebapiException
     */
    public function beforeValidate(RequestValidatorInterface $subject, Request $request): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED)) {
            return;
        }

        $path = rtrim((string)$request->getPathInfo(), '/');
        foreach ($this->blockedPathPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                throw new WebapiException(__('Request does not match any route.'), 0, WebapiException::HTTP_NOT_FOUND);
            }
        }
    }
}
