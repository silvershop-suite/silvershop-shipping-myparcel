<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Extension;

use SilverShop\Model\Order;
use SilverShop\ShippingMyParcel\Checkout\MyParcelDeliveryOptions;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\View\Requirements;

/**
 * Injects the MyParcel delivery-options widget into the single-page checkout, with no theme edits — the same
 * Requirements/`updateOrderForm` pattern silvershop/conversion-tracking and the storefront use.
 *
 * Applied to {@link \SilverShop\Page\CheckoutPageController}; fires on its OrderForm()'s `updateOrderForm` hook. It
 * loads the official `@myparcel/delivery-options` v6 widget (pinned CDN) and a small inline glue script that: builds
 * the widget configuration from the order's shipping address, (re)dispatches `myparcel_update_delivery_options` as the
 * address fields change, and copies the `myparcel_updated_delivery_options` payload into the hidden field the
 * {@see MyParcelDeliveryOptions} checkout component persists.
 *
 * The mount div + hidden field themselves are contributed by the checkout component (so they post correctly); this
 * extension only adds the assets + glue. Everything is gated on the order's canOfferMyParcelDeliveryOptions() check
 * ({@see MyParcelOrderExtension}) and guarded so a failure never breaks checkout.
 *
 * @extends Extension<\SilverShop\Page\CheckoutPageController>
 */
class MyParcelCheckoutExtension extends Extension
{
    use Configurable;

    /**
     * Pinned CDN build of the widget (Vue bundled). v6.22.0, published 2025-11-11 — far older than two weeks.
     */
    private static string $widget_js_url =
        'https://cdn.jsdelivr.net/npm/@myparcel/delivery-options@6.22.0/dist/myparcel.js';

    private static string $widget_css_url =
        'https://cdn.jsdelivr.net/npm/@myparcel/delivery-options@6.22.0/dist/style.css';

    /**
     * Widget platform: "myparcel" (NL) or "sendmyparcel" (BE). v1 ships the NL platform + PostNL.
     */
    private static string $platform = 'myparcel';

    /**
     * Carrier key the widget's carrierSettings are configured under.
     */
    private static string $carrier = 'postnl';

    /**
     * Widget locale (BCP-47).
     */
    private static string $locale = 'nl-NL';

    /**
     * Default pickup view: "map" or "list".
     */
    private static string $pickup_default_view = 'map';

    /**
     * Widget UI label overrides (its `strings` map). The widget localises dates/opening-hours from `locale`, but its
     * section labels fall back to their string keys unless supplied here. Dutch defaults for the NL-first audience;
     * override per language in config. Set to an empty array to show the widget's own keys.
     *
     * @var array<string, string>
     */
    private static array $widget_strings = [
        'deliveryTitle' => 'Bezorgen',
        'pickupTitle' => 'Afhalen',
        'deliveryStandardTitle' => 'Standaard',
        'deliveryMorningTitle' => 'Ochtend',
        'deliveryEveningTitle' => 'Avond',
        'signatureTitle' => 'Handtekening voor ontvangst',
        'onlyRecipientTitle' => 'Alleen bij de ontvanger bezorgen',
        'openingHours' => 'Openingstijden',
    ];

    public function updateOrderForm(Form $form): void
    {
        try {
            $order = $this->orderFrom($form);
            if (!$order || !$order->hasMethod('canOfferMyParcelDeliveryOptions')) {
                return;
            }
            if (!$order->canOfferMyParcelDeliveryOptions()) {
                return;
            }

            $cssUrl = (string) self::config()->get('widget_css_url');
            $jsUrl = (string) self::config()->get('widget_js_url');
            if ($cssUrl !== '') {
                Requirements::css($cssUrl);
            }
            if ($jsUrl !== '') {
                Requirements::javascript($jsUrl);
            }

            Requirements::customScript($this->glueScript($order), 'myparcel-delivery-options');
        } catch (\Throwable $e) {
            // A checkout nicety must never fatal the order form.
        }
    }

    /**
     * Build the widget configuration object for an order (the `detail` of the myparcel_update_delivery_options event),
     * seeded from the order's shipping address. Separated out so it is directly unit-testable.
     *
     * @return array<string, mixed>
     */
    public function buildWidgetConfig(Order $order): array
    {
        $config = [
            'address' => $this->seedAddress($order),
            'config' => [
                'platform' => (string) self::config()->get('platform'),
                'locale' => (string) self::config()->get('locale'),
                'carrierSettings' => [
                    (string) self::config()->get('carrier') => [
                        'allowDeliveryOptions' => true,
                        'allowStandardDelivery' => true,
                        'allowPickupLocations' => true,
                        'pickupLocationsDefaultView' => (string) self::config()->get('pickup_default_view'),
                    ],
                ],
            ],
        ];

        $strings = (array) self::config()->get('widget_strings');
        if ($strings !== []) {
            $config['strings'] = $strings;
        }

        return $config;
    }

    /**
     * The shipping-destination countries the widget is allowed to activate for (mirrors the order extension's list so
     * the client-side gate matches the server-side one).
     *
     * @return array<int, string>
     */
    public function allowedCountries(Order $order): array
    {
        $countries = (array) $order->config()->get('allowed_countries');

        return array_values(array_map(static fn ($c): string => strtoupper((string) $c), $countries));
    }

    /**
     * @return array<string, string>
     */
    protected function seedAddress(Order $order): array
    {
        $address = $order->ShippingAddress();
        if (!$address || !$address->exists()) {
            return ['cc' => '', 'postalCode' => '', 'number' => '', 'street' => '', 'city' => ''];
        }

        $number = $address->hasField('HouseNumber') ? (string) $address->HouseNumber : '';
        if ($number === '') {
            $number = $this->parseHouseNumber((string) $address->Address);
        }

        return [
            'cc' => strtoupper((string) $address->Country),
            'postalCode' => (string) $address->PostalCode,
            'number' => $number,
            'street' => (string) $address->Address,
            'city' => (string) $address->City,
        ];
    }

    /**
     * Best-effort discrete house number from a combined street string (fallback when address-formats' HouseNumber is
     * absent). The widget's lookup needs a number; the glue also re-reads a discrete HouseNumber field when present.
     */
    protected function parseHouseNumber(string $street): string
    {
        if (preg_match('/(\d+)\s*[a-zA-Z]?\s*$/', trim($street), $m)) {
            return $m[1];
        }
        if (preg_match('/\b(\d+)\b/', $street, $m)) {
            return $m[1];
        }

        return '';
    }

    protected function orderFrom(Form $form): ?Order
    {
        $controller = $form->getController();
        if ($controller && $controller->hasMethod('Cart')) {
            $cart = $controller->Cart();
            if ($cart instanceof Order && $cart->exists()) {
                return $cart;
            }
        }

        return null;
    }

    /**
     * The inline glue: configures the widget from the live shipping-address fields (NL/BE + postcode + number only),
     * re-dispatches on change, and stores the chosen option into the component's hidden field.
     */
    protected function glueScript(Order $order): string
    {
        $config = json_encode($this->buildWidgetConfig($order), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $allowed = json_encode($this->allowedCountries($order));
        $inputClass = MyParcelDeliveryOptions::INPUT_CLASS;

        return <<<JS
(function () {
    var BASE = {$config};
    var ALLOWED = {$allowed};
    var INPUT = '.{$inputClass}';

    function field(suffix) {
        var els = document.querySelectorAll('[name\$="_' + suffix + '"]');
        var chosen = null;
        for (var i = 0; i < els.length; i++) {
            if (els[i].name.indexOf('Shipping') !== -1) { chosen = els[i]; break; }
            if (!chosen) { chosen = els[i]; }
        }
        return chosen ? String(chosen.value || '').trim() : '';
    }

    function parseNumber(street) {
        var m = String(street || '').match(/(\\d+)\\s*[a-zA-Z]?\\s*\$/) || String(street || '').match(/\\b(\\d+)\\b/);
        return m ? m[1] : '';
    }

    function address() {
        var cc = (field('Country') || BASE.address.cc || '').toUpperCase();
        var street = field('Address') || BASE.address.street || '';
        var number = field('HouseNumber') || BASE.address.number || parseNumber(street);
        return {
            cc: cc,
            postalCode: field('PostalCode') || BASE.address.postalCode || '',
            number: number,
            street: street,
            city: field('City') || BASE.address.city || ''
        };
    }

    function configure() {
        var addr = address();
        if (ALLOWED.indexOf(addr.cc) === -1 || !addr.postalCode || !addr.number) { return; }
        var detail = { address: addr, config: BASE.config };
        if (BASE.strings) { detail.strings = BASE.strings; }
        document.dispatchEvent(new CustomEvent('myparcel_update_delivery_options', { detail: detail }));
    }

    document.addEventListener('myparcel_updated_delivery_options', function (e) {
        var input = document.querySelector(INPUT);
        if (input) {
            try { input.value = JSON.stringify(e.detail); } catch (err) { input.value = ''; }
        }
    });

    ['Country', 'PostalCode', 'HouseNumber', 'Address', 'City'].forEach(function (suffix) {
        document.addEventListener('change', function (e) {
            var n = e.target && e.target.name;
            if (n && n.indexOf('_' + suffix) === n.length - suffix.length - 1) { configure(); }
        }, true);
    });

    function boot() { configure(); setTimeout(configure, 800); }
    if (document.readyState !== 'loading') { boot(); } else { document.addEventListener('DOMContentLoaded', boot); }
})();
JS;
    }
}
