<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\TicketDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\DomainObjects\TicketDomainObject;
use HiEvents\DomainObjects\TicketPriceDomainObject;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\TicketRepositoryInterface;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use HiEvents\Services\Domain\Ticket\DTO\OrderTicketPriceDTO;
use HiEvents\Services\Domain\Ticket\TicketPriceService;
use HiEvents\Services\Handlers\Order\DTO\TicketOrderDetailsDTO;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

readonly class OrderItemProcessingService
{
    public function __construct(
        private OrderRepositoryInterface    $orderRepository,
        private TicketRepositoryInterface   $ticketRepository,
        private TaxAndFeeCalculationService $taxCalculationService,
        private TicketPriceService          $ticketPriceService,
    )
    {
    }

    /**
     * @param OrderDomainObject $order
     * @param Collection<TicketOrderDetailsDTO> $ticketsOrderDetails
     * @param EventDomainObject $event
     * @param PromoCodeDomainObject|null $promoCode
     * @return Collection
     */
    public function process(
        OrderDomainObject      $order,
        Collection             $ticketsOrderDetails,
        EventDomainObject      $event,
        ?PromoCodeDomainObject $promoCode
    ): Collection
    {
        $ticketPrices = collect();

        foreach ($ticketsOrderDetails as $ticketOrderDetail) {
            $ticket = $this->ticketRepository
                ->loadRelation(TaxAndFeesDomainObject::class)
                ->loadRelation(TicketPriceDomainObject::class)
                ->findFirstWhere([
                    TicketDomainObjectAbstract::ID => $ticketOrderDetail->ticket_id,
                    TicketDomainObjectAbstract::EVENT_ID => $event->getId(),
                ]);

            if ($ticket === null) {
                throw new ResourceNotFoundException(
                   __('Ticket with id :id not found', ['id' => $ticketOrderDetail->ticket_id])
                );
            }

            $ticketOrderDetail->quantities->each(function (OrderTicketPriceDTO $ticketPrice) use ($promoCode, $order, $ticketPrices, $ticket) {
                if ($ticketPrice->quantity === 0) {
                    return;
                }
                $ticketPrices->push($this->getPricesForTicket($ticket, $ticketPrice, $promoCode));
            });
        }

        # Sort by savings per ticket, descending
        $sortedTicketPrices = $ticketPrices->sortByDesc('savingsPerTicket');

        # Limit the promo code to the number of tickets configured. The tickets with the most savings should be prioritized.
        if ($promoCode) {
            $ticketsRemaining = $promoCode->getTicketLimitPerUse();
            $sortedTicketPrices->transform(function (array $ticketPrice, int $key) use (&$ticketsRemaining) {
                if ($ticketPrice['priceBeforeDiscount'] == null) {
                    # ticket is not discountable
                    return $ticketPrice;
                }

                if ($ticketsRemaining === null) {
                    # there is no per-use ticket limit imposed
                    $ticketPrice['discountedQuantity'] = $ticketPrice['quantity'];
                } elseif ($ticketPrice['quantity'] > $ticketsRemaining) {
                    # use up the remaining discountable quantity
                    $ticketPrice['discountedQuantity'] = $ticketsRemaining;
                    $ticketsRemaining = 0;
                } else {
                    # discount all of the tickets
                    $ticketPrice['discountedQuantity'] = $ticketPrice['quantity'];
                    $ticketsRemaining -= $ticketPrice['quantity'];
                }

                return $ticketPrice;
            });
        }

        $orderItems = $sortedTicketPrices->flatMap(function (array $ticketPrice, int $key) use ($order) {
            $result = collect();

            if ($ticketPrice['priceBeforeDiscount'] == null) {
                $orderItemData = $this->calculateOrderItemData(
                    null,
                    $ticketPrice['priceWithDiscount'],
                    $ticketPrice['quantity'] - $ticketPrice['discountedQuantity'],
                    $ticketPrice['ticket'],
                    $ticketPrice['ticketPriceDetails'],
                    $order
                );
                $result->push($this->orderRepository->addOrderItem($orderItemData));
            } else {
                if ($ticketPrice['discountedQuantity'] > 0) {
                    $orderItemData = $this->calculateOrderItemData(
                        $ticketPrice['priceBeforeDiscount'],
                        $ticketPrice['priceWithDiscount'],
                        $ticketPrice['discountedQuantity'],
                        $ticketPrice['ticket'],
                        $ticketPrice['ticketPriceDetails'],
                        $order
                    );
                    $result->push($this->orderRepository->addOrderItem($orderItemData));
                }

                if ($ticketPrice['quantity'] - $ticketPrice['discountedQuantity'] > 0) {
                    $orderItemData = $this->calculateOrderItemData(
                        null,
                        $ticketPrice['priceBeforeDiscount'],
                        $ticketPrice['quantity'] - $ticketPrice['discountedQuantity'],
                        $ticketPrice['ticket'],
                        $ticketPrice['ticketPriceDetails'],
                        $order
                    );
                    $result->push($this->orderRepository->addOrderItem($orderItemData));
                }
            }

            return $result;
        });

        return $orderItems;
    }

    private function getPricesForTicket(
        TicketDomainObject     $ticket,
        OrderTicketPriceDTO    $ticketPriceDetails,
        ?PromoCodeDomainObject $promoCode
    ): array
    {
        $prices = $this->ticketPriceService->getPrice($ticket, $ticketPriceDetails, $promoCode);
        $priceWithDiscount = $prices->price;
        $priceBeforeDiscount = $prices->price_before_discount;
        $savingsPerTicket = ($prices->price_before_discount - $prices->price);

        return [
            'ticket' => $ticket,
            'ticketPriceDetails' => $ticketPriceDetails,
            'priceBeforeDiscount' => $priceBeforeDiscount,
            'priceWithDiscount' => $priceWithDiscount,
            'savingsPerTicket' => $savingsPerTicket,
            'quantity' => $ticketPriceDetails->quantity,
            'discountedQuantity' => 0,
        ];
    }

    private function calculateOrderItemData(
        ?float              $priceBeforeDiscount,
        float               $priceWithDiscount,
        int                 $quantity,
        TicketDomainObject  $ticket,
        OrderTicketPriceDTO $ticketPriceDetails,
        OrderDomainObject   $order
    ): array
    {
        $itemTotalWithDiscount = $priceWithDiscount * $quantity;

        $taxesAndFees = $this->taxCalculationService->calculateTaxAndFeesForTicket(
            ticket: $ticket,
            price: $priceWithDiscount,
            quantity: $quantity
        );

        return [
            'ticket_id' => $ticket->getId(),
            'ticket_price_id' => $ticketPriceDetails->price_id,
            'quantity' => $quantity,
            'price_before_discount' => $priceBeforeDiscount,
            'total_before_additions' => Currency::round($itemTotalWithDiscount),
            'price' => $priceWithDiscount,
            'order_id' => $order->getId(),
            'item_name' => $this->getOrderItemLabel($ticket, $ticketPriceDetails->price_id),
            'total_tax' => $taxesAndFees->taxTotal,
            'total_service_fee' => $taxesAndFees->feeTotal,
            'total_gross' => Currency::round($itemTotalWithDiscount + $taxesAndFees->taxTotal + $taxesAndFees->feeTotal),
            'taxes_and_fees_rollup' => $taxesAndFees->rollUp,
        ];
    }

    private function getOrderItemLabel(TicketDomainObject $ticket, int $priceId): string
    {
        if ($ticket->isTieredType()) {
            return $ticket->getTitle() . ' - ' . $ticket->getTicketPrices()
                    ?->filter(fn($p) => $p->getId() === $priceId)->first()
                    ?->getLabel();
        }

        return $ticket->getTitle();
    }
}
