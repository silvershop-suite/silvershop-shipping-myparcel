<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Checkout;

use SilverShop\Checkout\Component\CheckoutComponent;
use SilverShop\Model\Order;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;

/**
 * Checkout component that carries the MyParcel delivery-options widget into the order form and persists the
 * customer's choice.
 *
 * It contributes two fields: a mount point (`<div id="myparcel-delivery-options">`) the JS widget renders into, and a
 * hidden input that the glue script fills with the raw `myparcel_updated_delivery_options` payload. On submit the
 * payload is parsed and stored on the order via {@see MyParcelOrderExtension::applyMyParcelDeliveryOptions()}.
 *
 * Because core's {@see \SilverShop\Checkout\SinglePageCheckoutComponentConfig} hard-codes its component list with no
 * add-component hook, this component is registered with an Injector `calls:` entry (addComponent) on that config in
 * {@see _config/shipping-myparcel.yml} — no core edit. A `calls:` entry composes (Injector merges service definitions),
 * so it coexists with another module's override of the same config (e.g. vat-compliance), unlike a `class:` swap.
 * (The recommended long-term fix is a small upstream `updateComponents` extend hook in the core config; a future PR.)
 *
 * The selection is always optional: when nothing is chosen, or the destination is not NL/BE, the order simply falls
 * back to plain home delivery.
 */
class MyParcelDeliveryOptions extends CheckoutComponent
{
    /**
     * The mount element id the widget renders into (matches the widget's default `#myparcel-delivery-options`).
     */
    public const MOUNT_ID = 'myparcel-delivery-options';

    /**
     * The (un-namespaced) hidden field + order column name holding the raw widget payload.
     */
    public const FIELD_NAME = 'MyParcelDeliveryOptions';

    /**
     * Stable class the glue JS targets to write the payload into the hidden input (survives checkout-component
     * field-name namespacing, which only rewrites the field `name`, not its CSS classes).
     */
    public const INPUT_CLASS = 'js-myparcel-delivery-options';

    public function getFormFields(Order $order): FieldList
    {
        $fields = FieldList::create();

        // Render nothing when MyParcel can't be offered — the order posts fine without this component's field.
        if (!$order->hasMethod('canOfferMyParcelDeliveryOptions') || !$order->canOfferMyParcelDeliveryOptions()) {
            return $fields;
        }

        $fields->push(
            LiteralField::create(
                'MyParcelDeliveryOptionsMount',
                '<div class="field myparcel-delivery-options-field">'
                . '<div id="' . self::MOUNT_ID . '" class="myparcel-delivery-options"></div>'
                . '</div>'
            )
        );
        $fields->push(
            HiddenField::create(self::FIELD_NAME)
                ->setValue((string) $order->MyParcelDeliveryOptions)
                ->addExtraClass(self::INPUT_CLASS)
        );

        return $fields;
    }

    public function validateData(Order $order, array $data): bool
    {
        // The selection is optional (graceful degrade to home delivery), so there is nothing to reject.
        return true;
    }

    public function getData(Order $order): array
    {
        return [
            self::FIELD_NAME => (string) $order->MyParcelDeliveryOptions,
        ];
    }

    public function setData(Order $order, array $data): Order
    {
        if (!$order->hasMethod('applyMyParcelDeliveryOptions')) {
            return $order;
        }

        // Only act when this component's field was part of the submission (it may be absent when the component
        // rendered nothing, e.g. provider unconfigured) — never wipe a stored choice on an unrelated save.
        if (!array_key_exists(self::FIELD_NAME, $data)) {
            return $order;
        }

        $order->applyMyParcelDeliveryOptions((string) $data[self::FIELD_NAME]);
        $order->write();

        return $order;
    }
}
