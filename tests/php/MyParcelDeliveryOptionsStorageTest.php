<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Tests;

use SilverShop\Model\Address;
use SilverShop\Model\Order;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The widget payload (myparcel_updated_delivery_options detail) → Order field storage + extraction, and the
 * graceful-degrade cases. Exercises {@see \SilverShop\ShippingMyParcel\Extension\MyParcelOrderExtension}.
 */
class MyParcelDeliveryOptionsStorageTest extends SapphireTest
{
    protected $usesDatabase = true;

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
            'date' => '2026-10-20 00:00:00.000000',
            'deliveryType' => 'pickup',
            'isPickup' => true,
            'packageType' => 'package',
            'shipmentOptions' => ['signature' => false, 'onlyRecipient' => false],
            'pickupLocation' => [
                'cc' => 'NL',
                'city' => 'Amsterdam',
                'distance' => 320,
                'latitude' => 52.37,
                'locationCode' => '176688',
                'locationName' => 'Primera Centrum',
                'longitude' => 4.89,
                'number' => '12',
                'numberSuffix' => '',
                'postalCode' => '1012AB',
                'retailNetworkId' => 'PNPNL-01',
                'street' => 'Damrak',
                'type' => 'parcel_locker',
            ],
        ]);
    }

    public function testPickupPayloadStoredAndExtracted(): void
    {
        $order = $this->orderFor('NL');
        $order->applyMyParcelDeliveryOptions($this->pickupPayload());
        $order->write();

        $this->assertSame('pickup', (string) $order->DeliveryType);
        $this->assertSame('2026-10-20', (string) $order->DeliveryDate);
        $this->assertSame('176688', (string) $order->PickupLocationCode);
        $this->assertSame('PNPNL-01', (string) $order->PickupNetworkId);
        $this->assertTrue($order->IsMyParcelPickup());
        $this->assertNotEmpty((string) $order->MyParcelDeliveryOptions, 'raw payload kept as source of truth');

        $pickup = $order->getMyParcelPickupData();
        $this->assertIsArray($pickup);
        $this->assertSame('176688', $pickup['locationCode']);
        $this->assertSame('PNPNL-01', $pickup['retailNetworkId']);
    }

    public function testPickupMirroredToAddress(): void
    {
        $order = $this->orderFor('NL');
        $order->applyMyParcelDeliveryOptions($this->pickupPayload());
        $order->write();

        $location = $order->PickupLocation();
        $this->assertTrue($location->exists());
        $this->assertSame('Primera Centrum', (string) $location->Company);
        $this->assertSame('Damrak 12', (string) $location->Address);
        $this->assertSame('Amsterdam', (string) $location->City);
        $this->assertSame('1012AB', (string) $location->PostalCode);
        $this->assertSame('NL', (string) $location->Country);
    }

    public function testEmptyPayloadClearsSelection(): void
    {
        $order = $this->orderFor('NL');
        $order->applyMyParcelDeliveryOptions($this->pickupPayload());
        $order->write();
        $this->assertTrue($order->IsMyParcelPickup());

        $order->applyMyParcelDeliveryOptions('');
        $order->write();

        $this->assertFalse($order->IsMyParcelPickup());
        $this->assertSame('', (string) $order->DeliveryType);
        $this->assertSame('', (string) $order->PickupLocationCode);
        $this->assertSame(0, (int) $order->PickupLocationID);
    }

    public function testInvalidJsonDegradesToHome(): void
    {
        $order = $this->orderFor('NL');
        $order->applyMyParcelDeliveryOptions('not-json {{{');
        $order->write();

        $this->assertFalse($order->IsMyParcelPickup());
        $this->assertSame('', (string) $order->DeliveryType);
    }

    public function testHomeDeliveryPayloadStoresTypeButNoPickup(): void
    {
        $order = $this->orderFor('NL');
        $order->applyMyParcelDeliveryOptions(json_encode([
            'carrier' => 'postnl',
            'deliveryType' => 'standard',
            'isPickup' => false,
            'packageType' => 'package',
            'shipmentOptions' => ['signature' => false, 'onlyRecipient' => false],
        ]));
        $order->write();

        $this->assertSame('standard', (string) $order->DeliveryType);
        $this->assertFalse($order->IsMyParcelPickup());
        $this->assertSame('', (string) $order->PickupLocationCode);
    }

    public function testNonNlBeDestinationDoesNotStorePickup(): void
    {
        $order = $this->orderFor('DE');
        $order->applyMyParcelDeliveryOptions($this->pickupPayload());
        $order->write();

        // Delivery type is recorded, but the pickup point is not honoured outside NL/BE → degrades to home.
        $this->assertFalse($order->shipsToMyParcelCountry());
        $this->assertFalse($order->IsMyParcelPickup());
        $this->assertSame('', (string) $order->PickupLocationCode);
    }
}
