<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Tests;

use MyParcelNL\Sdk\src\Services\ConsignmentEncode;
use SilverShop\Model\Address;
use SilverShop\Model\Order;
use SilverShop\ShippingMyParcel\MyParcelProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The PRIMARY proof: the stored checkout choice maps onto the exact MyParcel consignment API payload.
 *
 * {@see MyParcelProvider::buildConsignment()} is pure (no network), and the MyParcel SDK's own
 * {@see ConsignmentEncode::apiEncode()} serialises a consignment into the precise array sent to the shipments API — so
 * asserting that array proves the wire mapping without any HTTP (the SDK uses curl, not Guzzle, so a Guzzle MockHandler
 * is not applicable; this is the stronger, network-free equivalent).
 */
class MyParcelConsignmentMappingTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array{title: string, carrier_id: int, package_type: int}
     */
    private array $service = ['title' => 'PostNL — Parcel', 'carrier_id' => 1, 'package_type' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Order::class, 'log_status', []);
    }

    private function orderFor(string $country = 'NL'): Order
    {
        $address = Address::create([
            'FirstName' => 'Jan',
            'Surname' => 'Jansen',
            'Address' => 'Teststraat 10',
            'PostalCode' => '1011AB',
            'City' => 'Amsterdam',
            'State' => 'Noord-Holland',
            'Country' => $country,
        ]);
        $address->write();

        $order = Order::create();
        $order->ShippingAddressID = $address->ID;
        $order->write();

        return $order;
    }

    private function pickupPayload(): string
    {
        return json_encode([
            'carrier' => 'postnl',
            'deliveryType' => 'pickup',
            'isPickup' => true,
            'packageType' => 'package',
            'shipmentOptions' => ['signature' => false, 'onlyRecipient' => false],
            'pickupLocation' => [
                'cc' => 'NL',
                'city' => 'Amsterdam',
                'locationCode' => '176688',
                'locationName' => 'Primera Centrum',
                'number' => '12',
                'numberSuffix' => '',
                'postalCode' => '1012AB',
                'retailNetworkId' => 'PNPNL-01',
                'street' => 'Damrak',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Order $order): array
    {
        $consignment = (new MyParcelProvider())->buildConsignment($order, $this->service, 'test-key');

        return (new ConsignmentEncode([$consignment]))->apiEncode();
    }

    public function testPickupConsignmentPayload(): void
    {
        $order = $this->orderFor('NL');
        $order->applyMyParcelDeliveryOptions($this->pickupPayload());
        $order->write();

        $encoded = $this->encode($order);

        // delivery_type 4 = pickup; pickup requires signature.
        $this->assertSame(4, $encoded['options']['delivery_type']);
        $this->assertSame(1, $encoded['options']['signature']);
        $this->assertSame(0, $encoded['options']['only_recipient']);
        $this->assertSame(1, $encoded['options']['package_type'], 'pickup forces package_type 1');

        // The pickup object with the canonical identifiers + address.
        $this->assertArrayHasKey('pickup', $encoded);
        $this->assertSame('176688', $encoded['pickup']['location_code']);
        $this->assertSame('PNPNL-01', $encoded['pickup']['retail_network_id']);
        $this->assertSame('NL', $encoded['pickup']['cc']);
        $this->assertSame('1012AB', $encoded['pickup']['postal_code']);
        $this->assertSame('Amsterdam', $encoded['pickup']['city']);
        $this->assertSame('Damrak', $encoded['pickup']['street']);
        $this->assertSame('12', $encoded['pickup']['number']);
        $this->assertSame('Primera Centrum', $encoded['pickup']['location_name']);
    }

    public function testHomeDeliveryHasNoPickupObject(): void
    {
        $order = $this->orderFor('NL');
        // No delivery options chosen at all.
        $encoded = $this->encode($order);

        $this->assertArrayNotHasKey('pickup', $encoded);
        $this->assertSame(2, $encoded['options']['delivery_type'], 'defaults to standard home delivery');
        $this->assertSame('1011AB', $encoded['recipient']['postal_code']);
    }

    public function testNonNlBePickupDegradesToHome(): void
    {
        $order = $this->orderFor('DE');
        $order->applyMyParcelDeliveryOptions($this->pickupPayload());
        $order->write();

        $encoded = $this->encode($order);

        $this->assertArrayNotHasKey('pickup', $encoded, 'pickup not applied outside NL/BE');
        $this->assertSame(2, $encoded['options']['delivery_type']);
    }
}
