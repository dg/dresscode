<?php declare(strict_types=1);

namespace Acme\Shop;

class OrderException extends \RuntimeException
{
}

class PaymentDeclined extends OrderException
{
}
