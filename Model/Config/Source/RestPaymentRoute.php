<?php

declare(strict_types=1);

namespace MageOS\CheckoutGuard\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class RestPaymentRoute implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'payment-information', 'label' => 'payment-information (set payment and place order)'],
            ['value' => 'order', 'label' => 'order (place order, PUT)'],
            ['value' => 'set-payment-information', 'label' => 'set-payment-information'],
            ['value' => 'selected-payment-method', 'label' => 'selected-payment-method'],
        ];
    }
}
