<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Extension;

use SilverShop\Model\Address;
use SilverShop\Model\Order;
use SilverShop\ShippingLabels\Provider\ProviderRegistry;
use SilverShop\ShippingMyParcel\MyParcelProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * Carrier-neutral checkout delivery-options storage on an {@link Order}.
 *
 * The customer's choice from the MyParcel delivery-options widget (home delivery vs a nearby carrier parcel point)
 * is captured here. The raw widget payload is kept verbatim as the source of truth ({@see $db MyParcelDeliveryOptions})
 * and a few carrier-neutral columns are extracted for admin/querying + fulfilment. Field names are kept free of
 * "MyParcel" where neutral (DeliveryType, PickupLocationCode, …) so a future generic checkout-delivery-options
 * extraction can lift them unchanged (see PLAN-shipping-myparcel-pickup §3.2).
 *
 * The pickup point is also mirrored into a {@link Address} ({@see has_one PickupLocation}) for admin display and
 * reuse of the address renderer. {@see MyParcelProvider::createLabel()} reads these fields when it builds the
 * consignment, routing the package to the chosen point.
 *
 * @property ?string $MyParcelDeliveryOptions
 * @property ?string $DeliveryType
 * @property ?string $DeliveryDate
 * @property ?string $PickupLocationCode
 * @property ?string $PickupNetworkId
 * @property int $PickupLocationID
 * @method Address PickupLocation()
 * @extends Extension<Order>
 */
class MyParcelOrderExtension extends Extension
{
    private static array $db = [
        // Raw `event.detail` JSON from the widget's myparcel_updated_delivery_options event (authoritative).
        'MyParcelDeliveryOptions' => 'Text',
        // Extracted, carrier-neutral columns.
        'DeliveryType' => 'Varchar(20)',   // standard|morning|evening|pickup|…
        'DeliveryDate' => 'Varchar(10)',   // YYYY-MM-DD (carrier's chosen date, when offered)
        'PickupLocationCode' => 'Varchar',  // canonical MyParcel location id
        'PickupNetworkId' => 'Varchar',     // canonical retail_network_id
    ];

    private static array $has_one = [
        // The chosen carrier parcel point, mirrored as an Address for admin display / reuse.
        'PickupLocation' => Address::class,
    ];

    /**
     * Shipping-destination countries for which the widget + pickup flow are offered. MyParcel delivery types +
     * pickup are NL/BE only (see the MyParcel shipments API restrictions).
     *
     * @var array<int, string>
     */
    private static array $allowed_countries = ['NL', 'BE'];

    /**
     * Whether the delivery-options widget can be offered for this order at checkout render time.
     *
     * Country is intentionally NOT checked here: on the single-page checkout the shipping address is entered in the
     * same form, so the destination is not yet known when the form first renders. The widget's JS gates itself on
     * the live address (NL/BE + postcode + house number); the server re-checks the country when persisting
     * ({@see applyMyParcelDeliveryOptions()}) and when fulfilling ({@see MyParcelProvider}). The render-time gate is
     * therefore just: provider configured, and the cart is not pickup-only (shippability veto, when present).
     */
    public function canOfferMyParcelDeliveryOptions(): bool
    {
        if (!self::providerIsConfigured()) {
            return false;
        }

        $order = $this->getOwner();
        // shippability veto: a pickup-only cart can't be posted at all — route to merchant pickup instead.
        if ($order->hasMethod('HasPickupOnlyItems') && $order->HasPickupOnlyItems()) {
            return false;
        }

        return true;
    }

    /**
     * The MyParcel label provider's configured state (API key present). Resolved via the shipping-labels registry so
     * the same instance/config is used everywhere.
     */
    public static function providerIsConfigured(): bool
    {
        try {
            $provider = Injector::inst()->get(ProviderRegistry::class)->get('myparcel');

            return $provider instanceof MyParcelProvider && $provider->isConfigured();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * True when this order's shipping destination is a country the MyParcel flow supports (NL/BE).
     */
    public function shipsToMyParcelCountry(): bool
    {
        $country = strtoupper((string) $this->shippingCountry());

        return $country !== '' && in_array($country, $this->allowedCountries(), true);
    }

    /**
     * True when the customer chose a carrier parcel point (and it is usable). Named distinctly from
     * click-and-collect's Order::IsPickup() (merchant-store pickup) to avoid a method collision.
     */
    public function IsMyParcelPickup(): bool
    {
        $order = $this->getOwner();

        return (string) $order->DeliveryType === 'pickup' && (string) $order->PickupLocationCode !== '';
    }

    /**
     * The decoded pickup-location object from the stored raw widget payload, or null. Keys are the widget's
     * camelCase output (locationCode, retailNetworkId, locationName, cc, city, postalCode, street, number,
     * numberSuffix, …) — see @myparcel/delivery-options OutputPickupLocation.
     *
     * @return array<string, mixed>|null
     */
    public function getMyParcelPickupData(): ?array
    {
        $decoded = $this->decodeDeliveryOptions();
        $pickup = $decoded['pickupLocation'] ?? null;

        return is_array($pickup) ? $pickup : null;
    }

    /**
     * Parse a raw widget payload and persist it onto the order (extracting the neutral columns + mirroring the
     * pickup point into the PickupLocation Address). Passing null/empty clears any previous selection (→ home
     * delivery). Separated from the checkout component so it is directly testable on an Order.
     *
     * Does NOT call write() — the caller decides when to persist (the checkout component writes once).
     */
    public function applyMyParcelDeliveryOptions(?string $json): void
    {
        $order = $this->getOwner();
        $json = trim((string) $json);

        // No selection → clear everything (graceful degrade to plain home delivery).
        if ($json === '') {
            $this->clearMyParcelDeliveryOptions();
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->clearMyParcelDeliveryOptions();
            return;
        }

        $order->MyParcelDeliveryOptions = $json;
        $order->DeliveryType = isset($data['deliveryType']) ? (string) $data['deliveryType'] : '';
        $order->DeliveryDate = isset($data['date']) ? substr((string) $data['date'], 0, 10) : '';

        $isPickup = !empty($data['isPickup']) || ($data['deliveryType'] ?? '') === 'pickup';
        $pickup = $data['pickupLocation'] ?? null;

        // Only honour a pickup point when the destination is an NL/BE address (API restriction) and the payload
        // actually carries a location. Otherwise keep it as home delivery.
        if ($isPickup && is_array($pickup) && $this->shipsToMyParcelCountry()) {
            $order->PickupLocationCode = (string) ($pickup['locationCode'] ?? '');
            $order->PickupNetworkId = (string) ($pickup['retailNetworkId'] ?? '');
            $this->syncPickupAddress($pickup);
        } else {
            $this->clearPickup();
        }
    }

    /**
     * Admin "Delivery" panel: the chosen type/date + the pickup location summary. Guarded to saved orders.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $order = $this->getOwner();
        if (!$order->isInDB() || (string) $order->MyParcelDeliveryOptions === '') {
            return;
        }

        $fields->findOrMakeTab('Root.Delivery', _t(self::class . '.TabDelivery', 'Delivery'));

        $fields->addFieldsToTab('Root.Delivery', [
            ReadonlyField::create(
                'MyParcelDeliveryTypeDisplay',
                _t(self::class . '.DeliveryType', 'Delivery type'),
                (string) $order->DeliveryType
            ),
            ReadonlyField::create(
                'MyParcelDeliveryDateDisplay',
                _t(self::class . '.DeliveryDate', 'Delivery date'),
                (string) $order->DeliveryDate
            ),
        ]);

        if ($this->IsMyParcelPickup()) {
            $fields->addFieldToTab('Root.Delivery', LiteralField::create(
                'MyParcelPickupLocationDisplay',
                '<div class="field"><label class="left">'
                . Convert::raw2xml(_t(self::class . '.PickupLocation', 'Pickup location'))
                . '</label><div class="middleColumn"><p class="form__field-description">'
                . $this->pickupLocationSummary()
                . '</p></div></div>'
            ));
        }
    }

    /**
     * Safe HTML fragment summarising the chosen pickup point (name + address + canonical codes).
     */
    public function pickupLocationSummary(): string
    {
        $order = $this->getOwner();
        $location = $order->PickupLocation();
        if (!$location || !$location->exists()) {
            return '';
        }

        $lines = [];
        if ((string) $location->Company !== '') {
            $lines[] = '<strong>' . Convert::raw2xml((string) $location->Company) . '</strong>';
        }
        $address = trim((string) $location->toString());
        if ($address !== '') {
            $lines[] = Convert::raw2xml($address);
        }
        $codes = trim((string) $order->PickupLocationCode . ' / ' . (string) $order->PickupNetworkId, ' /');
        if ($codes !== '') {
            $lines[] = '<small>' . Convert::raw2xml($codes) . '</small>';
        }

        return implode('<br>', $lines);
    }

    /**
     * @return array<int, string>
     */
    protected function allowedCountries(): array
    {
        $countries = (array) $this->getOwner()->config()->get('allowed_countries');

        return array_values(array_map(static fn ($c): string => strtoupper((string) $c), $countries));
    }

    protected function shippingCountry(): string
    {
        $order = $this->getOwner();
        $address = $order->ShippingAddress();

        return $address && $address->exists() ? (string) $address->Country : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeDeliveryOptions(): array
    {
        $raw = (string) $this->getOwner()->MyParcelDeliveryOptions;
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mirror a pickup-location payload into the order's PickupLocation Address (update in place to avoid orphan
     * Address records accumulating across re-selections).
     *
     * @param array<string, mixed> $pickup
     */
    protected function syncPickupAddress(array $pickup): void
    {
        $order = $this->getOwner();
        $location = $order->PickupLocation();
        if (!$location || !$location->exists()) {
            $location = Address::create();
        }

        $number = trim((string) ($pickup['number'] ?? '') . (string) ($pickup['numberSuffix'] ?? ''));
        $street = trim((string) ($pickup['street'] ?? ''));

        $location->Company = (string) ($pickup['locationName'] ?? '');
        $location->Address = trim($street . ' ' . $number);
        $location->City = (string) ($pickup['city'] ?? '');
        $location->PostalCode = (string) ($pickup['postalCode'] ?? '');
        $location->Country = strtoupper((string) ($pickup['cc'] ?? ''));
        // Set a discrete house number when the field exists (silvershop-suite/address-formats adds HouseNumber on
        // Address).
        if ($number !== '' && $location->hasField('HouseNumber')) {
            $location->HouseNumber = $number;
        }
        // A carrier parcel point is a system record carrying no State/province, so bypass the shop's address
        // validation (core requires State unless address-formats relaxes it) — never block checkout on it.
        $this->writeWithoutValidation($location);

        $order->PickupLocationID = (int) $location->ID;
    }

    /**
     * Write a system-generated record without the shop's model validation (restored afterwards).
     */
    protected function writeWithoutValidation(DataObject $record): void
    {
        $previous = (bool) Config::inst()->get(DataObject::class, 'validation_enabled');
        Config::modify()->set(DataObject::class, 'validation_enabled', false);
        try {
            $record->write();
        } finally {
            Config::modify()->set(DataObject::class, 'validation_enabled', $previous);
        }
    }

    protected function clearMyParcelDeliveryOptions(): void
    {
        $order = $this->getOwner();
        $order->MyParcelDeliveryOptions = '';
        $order->DeliveryType = '';
        $order->DeliveryDate = '';
        $this->clearPickup();
    }

    protected function clearPickup(): void
    {
        $order = $this->getOwner();
        $order->PickupLocationCode = '';
        $order->PickupNetworkId = '';
        $order->PickupLocationID = 0;
    }
}
