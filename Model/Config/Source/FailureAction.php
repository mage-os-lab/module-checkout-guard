<?php

declare(strict_types=1);

namespace MageOS\CheckoutGuard\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FailureAction implements OptionSourceInterface
{
    public const DEACTIVATE = 'deactivate';
    public const RESET = 'reset';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::DEACTIVATE, 'label' => __('Deactivate the cart (customer starts a new cart)')],
            ['value' => self::RESET, 'label' => __('Reset the checkout (items stay, addresses, shipping and payment are cleared)')],
        ];
    }
}
