<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\Product\ProductPriceService;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

readonly class OrderItemProcessingService
{
    public function __construct(
        private OrderRepositoryInterface    $orderRepository,
        private ProductRepositoryInterface  $productRepository,
        private TaxAndFeeCalculationService $taxCalculationService,
        private ProductPriceService         $productPriceService,
    )
    {
    }

    /**
     * @param OrderDomainObject $order
     * @param Collection<ProductOrderDetailsDTO> $productsOrderDetails
     * @param EventDomainObject $event
     * @param PromoCodeDomainObject|null $promoCode
     * @return Collection
     */
    public function process(
        OrderDomainObject      $order,
        Collection             $productsOrderDetails,
        EventDomainObject      $event,
        ?PromoCodeDomainObject $promoCode
    ): Collection
    {
        $productPrices = collect();

        foreach ($productsOrderDetails as $productOrderDetail) {
            $product = $this->productRepository
                ->loadRelation(TaxAndFeesDomainObject::class)
                ->loadRelation(ProductPriceDomainObject::class)
                ->findFirstWhere([
                    ProductDomainObjectAbstract::ID => $productOrderDetail->product_id,
                    ProductDomainObjectAbstract::EVENT_ID => $event->getId(),
                ]);

            if ($product === null) {
                throw new ResourceNotFoundException(
                    __('Product with id :id not found', ['id' => $productOrderDetail->product_id])
                );
            }

            $productOrderDetail->quantities->each(function (OrderProductPriceDTO $productPrice) use ($promoCode, $order, $productPrices, $product) {
                if ($productPrice->quantity === 0) {
                    return;
                }
                $productPrices->push($this->getPricesForProduct($product, $productPrice, $promoCode));
            });
        }

        # Sort by savings per product, descending
        $sortedProductPrices = $productPrices->sortByDesc('savingsPerProduct');

        # Limit the promo code to the number of products configured. The products with the most savings should be prioritized.
        if ($promoCode) {
            $productsRemaining = $promoCode->getProductLimitPerUse();
            $sortedProductPrices->transform(function (array $productPrice, int $key) use (&$productsRemaining) {
                if ($productPrice['priceBeforeDiscount'] == null) {
                    # product is not discountable
                    return $productPrice;
                }

                if ($productsRemaining === null) {
                    # there is no per-use product limit imposed
                    $productPrice['discountedQuantity'] = $productPrice['quantity'];
                } elseif ($productPrice['quantity'] > $productsRemaining) {
                    # use up the remaining discountable quantity
                    $productPrice['discountedQuantity'] = $productsRemaining;
                    $productsRemaining = 0;
                } else {
                    # discount all of the products
                    $productPrice['discountedQuantity'] = $productPrice['quantity'];
                    $productsRemaining -= $productPrice['quantity'];
                }

                return $productPrice;
            });
        }

        $orderItems = $sortedProductPrices->flatMap(function (array $productPrice, int $key) use ($order) {
            $result = collect();

            if ($productPrice['priceBeforeDiscount'] == null) {
                $orderItemData = $this->calculateOrderItemData(
                    null,
                    $productPrice['priceWithDiscount'],
                    $productPrice['quantity'] - $productPrice['discountedQuantity'],
                    $productPrice['product'],
                    $productPrice['productPriceDetails'],
                    $order
                );
                $result->push($this->orderRepository->addOrderItem($orderItemData));
            } else {
                if ($productPrice['discountedQuantity'] > 0) {
                    $orderItemData = $this->calculateOrderItemData(
                        $productPrice['priceBeforeDiscount'],
                        $productPrice['priceWithDiscount'],
                        $productPrice['discountedQuantity'],
                        $productPrice['product'],
                        $productPrice['productPriceDetails'],
                        $order
                    );
                    $result->push($this->orderRepository->addOrderItem($orderItemData));
                }

                if ($productPrice['quantity'] - $productPrice['discountedQuantity'] > 0) {
                    $orderItemData = $this->calculateOrderItemData(
                        null,
                        $productPrice['priceBeforeDiscount'],
                        $productPrice['quantity'] - $productPrice['discountedQuantity'],
                        $productPrice['product'],
                        $productPrice['productPriceDetails'],
                        $order
                    );
                    $result->push($this->orderRepository->addOrderItem($orderItemData));
                }
            }

            return $result;
        });

        return $orderItems;
    }

    private function getPricesForProduct(
        ProductDomainObject    $product,
        OrderProductPriceDTO   $productPriceDetails,
        ?PromoCodeDomainObject $promoCode
    ): array
    {
        $prices = $this->productPriceService->getPrice($product, $productPriceDetails, $promoCode);
        $priceWithDiscount = $prices->price;
        $priceBeforeDiscount = $prices->price_before_discount;
        $savingsPerProduct = ($prices->price_before_discount - $prices->price);


        return [
            'product' => $product,
            'productPriceDetails' => $productPriceDetails,
            'priceBeforeDiscount' => $priceBeforeDiscount,
            'priceWithDiscount' => $priceWithDiscount,
            'savingsPerProduct' => $savingsPerProduct,
            'quantity' => $productPriceDetails->quantity,
            'discountedQuantity' => 0,
        ];
    }

    private function calculateOrderItemData(
        ?float                 $priceBeforeDiscount,
        float                  $priceWithDiscount,
        int                    $quantity,
        ProductDomainObject    $product,
        OrderProductPriceDTO   $productPriceDetails,
        OrderDomainObject      $order,
    ): array
    {
        $itemTotalWithDiscount = $priceWithDiscount * $quantity;

        $taxesAndFees = $this->taxCalculationService->calculateTaxAndFeesForProduct(
            product: $product,
            price: $priceWithDiscount,
            quantity: $quantity
        );

        return [
            'product_type' => $product->getProductType(),
            'product_id' => $product->getId(),
            'product_price_id' => $productPriceDetails->price_id,
            'quantity' => $quantity,
            'price_before_discount' => $priceBeforeDiscount,
            'total_before_additions' => Currency::round($itemTotalWithDiscount),
            'price' => $priceWithDiscount,
            'order_id' => $order->getId(),
            'item_name' => $this->getOrderItemLabel($product, $productPriceDetails->price_id),
            'total_tax' => $taxesAndFees->taxTotal,
            'total_service_fee' => $taxesAndFees->feeTotal,
            'total_gross' => Currency::round($itemTotalWithDiscount + $taxesAndFees->taxTotal + $taxesAndFees->feeTotal),
            'taxes_and_fees_rollup' => $taxesAndFees->rollUp,
        ];
    }

    private function getOrderItemLabel(ProductDomainObject $product, int $priceId): string
    {
        if ($product->isTieredType()) {
            return $product->getTitle() . ' - ' . $product->getProductPrices()
                    ?->filter(fn($p) => $p->getId() === $priceId)->first()
                    ?->getLabel();
        }

        return $product->getTitle();
    }
}
