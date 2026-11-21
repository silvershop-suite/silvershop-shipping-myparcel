<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Tests;

use SilverShop\Model\Address;
use SilverShop\Model\Order;
use SilverShop\ShippingMyParcel\Checkout\MyParcelDeliveryOptions;
use SilverShop\ShippingMyParcel\Extension\MyParcelCheckoutExtension;
use SilverShop\ShippingMyParcel\MyParcelProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\HiddenField;
use SilverStripe\i18n\i18n;

/**
 * The widget configuration emitted into checkout + the render-time gating of the delivery-options component.
 */
class MyParcelCheckoutWidgetTest extends SapphireTest
{
    protected $usesDatabase = true;

    private string $envKey;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Order::class, 'log_status', []);
        $this->envKey = (string) Config::inst()->get(MyParcelProvider::class, 'api_key_env');
        Environment::setEnv($this->envKey, '');
        i18n::set_locale('nl_NL');
    }

    protected function tearDown(): void
    {
        Environment::setEnv($this->envKey, '');
        parent::tearDown();
    }

    private function orderFor(string $country = 'NL'): Order
    {
        $address = Address::create([
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

    public function testWidgetConfigShape(): void
    {
        $config = (new MyParcelCheckoutExtension())->buildWidgetConfig($this->orderFor('NL'));

        $this->assertSame('myparcel', $config['config']['platform']);
        $this->assertArrayHasKey('postnl', $config['config']['carrierSettings']);
        $this->assertTrue($config['config']['carrierSettings']['postnl']['allowPickupLocations']);
        $this->assertSame('map', $config['config']['carrierSettings']['postnl']['pickupLocationsDefaultView']);

        // The widget's texts (its `strings` map) follow the site language, and so does its date locale.
        $this->assertSame('Afhalen', $config['strings']['pickupTitle']);
        $this->assertSame('nl-NL', $config['config']['locale']);

        // Address seeded from the order, with a house number parsed from the street.
        $this->assertSame('NL', $config['address']['cc']);
        $this->assertSame('1011AB', $config['address']['postalCode']);
        $this->assertSame('Amsterdam', $config['address']['city']);
        $this->assertSame('10', $config['address']['number']);
    }

    public function testGatingFollowsProviderConfiguration(): void
    {
        $order = $this->orderFor('NL');

        Environment::setEnv($this->envKey, '');
        $this->assertFalse($order->canOfferMyParcelDeliveryOptions(), 'not offered without an API key');

        Environment::setEnv($this->envKey, 'test-key');
        $this->assertTrue($order->canOfferMyParcelDeliveryOptions(), 'offered once configured');
    }

    public function testComponentRendersNothingWhenUnconfigured(): void
    {
        Environment::setEnv($this->envKey, '');
        $fields = MyParcelDeliveryOptions::create()->getFormFields($this->orderFor('NL'));
        $this->assertCount(0, $fields);
    }

    public function testComponentRendersMountAndHiddenFieldWhenConfigured(): void
    {
        Environment::setEnv($this->envKey, 'test-key');
        $fields = MyParcelDeliveryOptions::create()->getFormFields($this->orderFor('NL'));

        $this->assertCount(2, $fields);
        $hidden = $fields->dataFieldByName(MyParcelDeliveryOptions::FIELD_NAME);
        $this->assertInstanceOf(HiddenField::class, $hidden);
        $this->assertStringContainsString(MyParcelDeliveryOptions::INPUT_CLASS, (string) $hidden->extraClass());
    }

    public function testEveryErrorTheWidgetCanShowHasText(): void
    {
        // Without a text the widget prints its raw key, e.g. "error3501" under the terms checkbox.
        $strings = (new MyParcelCheckoutExtension())->widgetStrings();

        foreach (['error3212', 'error3224', 'error3501', 'error3505', 'error3728'] as $key) {
            $this->assertNotSame('', $strings[$key] ?? '', $key . ' has a text');
            $this->assertNotSame($key, $strings[$key]);
        }
        $this->assertSame('Dit adres is niet gevonden. Controleer je postcode en huisnummer.', $strings['error3501']);
    }

    public function testWidgetTextsAreTranslatedAndConfigOverridesOneKey(): void
    {
        i18n::set_locale('en_GB');
        Config::modify()->set(MyParcelCheckoutExtension::class, 'widget_strings', ['pickupTitle' => 'Collect']);

        $config = (new MyParcelCheckoutExtension())->buildWidgetConfig($this->orderFor('NL'));

        $this->assertSame('Collect', $config['strings']['pickupTitle'], 'config wins for the key it sets');
        $this->assertSame('Delivery', $config['strings']['deliveryTitle'], 'the rest stays translated');
        $this->assertStringStartsWith('We could not find this address', $config['strings']['error3501']);
        $this->assertSame('en-GB', $config['config']['locale']);
    }

    public function testPinnedLocaleWins(): void
    {
        Config::modify()->set(MyParcelCheckoutExtension::class, 'locale', 'nl-BE');

        $config = (new MyParcelCheckoutExtension())->buildWidgetConfig($this->orderFor('NL'));

        $this->assertSame('nl-BE', $config['config']['locale']);
    }
}
