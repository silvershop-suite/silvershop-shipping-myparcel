# silvershop/shipping-myparcel

A **MyParcel** label provider for [`silvershop/shipping-labels`](https://github.com/silvershop/silvershop-shipping-labels).
It creates a real carrier label (PostNL) for an order through the [MyParcel SDK](https://github.com/myparcelNL/sdk),
returning the label PDF + barcode/tracking; the shipping-labels framework stores and serves the PDF.

MyParcel is an **NL/BE-account** aggregator (ships NL/BE domestic + EU/international). Merchants outside NL/BE should
use a different adapter.

## Install

```bash
composer require silvershop/shipping-myparcel
```

Pulls in `silvershop/shipping-labels` and `myparcelnl/sdk`. Install `silvershop/shipping` too if you want the parcel
weight derived automatically from your products.

## Configure

Put your MyParcel API key in `.env` (never in config/SiteConfig):

```
SS_MYPARCEL_API_KEY="your-myparcel-key"
```

Everything else is optional config on `SilverShop\ShippingMyParcel\MyParcelProvider`:

```yaml
SilverShop\ShippingMyParcel\MyParcelProvider:
  default_service: 'postnl_parcel'
  default_weight_grams: 1000      # fallback when a parcel has no product weight
  weight_to_grams: 1              # set 1000 if your shop stores product weight in kilograms
  services:
    postnl_parcel:  { title: 'PostNL — Parcel',         carrier_id: 1, package_type: 1 }
    postnl_mailbox: { title: 'PostNL — Mailbox parcel',  carrier_id: 1, package_type: 2 }
    # Add DHL/DPD here once the installed myparcelnl/sdk major provides the carrier — config only, no code.
```

## Use

Create a label for an order (the only entry point; the shipping-labels framework persists the result):

```php
$label = $order->createShippingLabel('myparcel', ['service' => 'postnl_parcel']);
// $label->LabelFile (PDF), $label->TrackingNumber (barcode), $label->TrackingUrl, $label->ProviderRef (consignment id)
```

The label PDF is viewable at `/shippinglabels/label/{ID}`. `track()` returns the barcode + public MyParcel
track-trace URL.

## Checkout delivery options + pickup points (afhaalpunt)

The module also adds a **pre-purchase** step: at checkout the customer can choose **home delivery** or a nearby
**carrier parcel point** (PostNL pickup location). The choice is stored on the order and routed into the consignment, so
the package is sent to the chosen point (`delivery_type 4`).

It uses MyParcel's official **[`@myparcel/delivery-options`](https://developer.myparcel.nl/documentation/60.delivery-options.html)
v6** widget, loaded from a pinned jsDelivr CDN build and mounted into the **single-page checkout** via the module's own
`Requirements` + the `updateOrderForm` controller hook — **no theme edits**, the same injection pattern as
silvershop/conversion-tracking. The widget's address lookups (delivery options + nearby pickup points) are **keyless**;
only consignment creation uses your `SS_MYPARCEL_API_KEY`.

**How it works**

1. When MyParcel is configured and the cart is not pickup-only, the widget mount + a hidden field are added to the
   checkout form (via a `CheckoutComponent`), and the widget JS + a small glue script are injected.
2. The glue script builds the widget config from the live shipping-address fields and only activates it for an
   **NL/BE** address with a postcode + house number. (Install `silvershop/address-formats` for a discrete house-number
   field; otherwise it is parsed from the street.)
3. The customer's selection (`myparcel_updated_delivery_options`) is written into the hidden field and, on submit,
   stored on the order.
4. `MyParcelProvider::createLabel()` reads the stored choice and, for a pickup, sets `delivery_type = 4` + signature +
   the `pickup` object (canonical `location_code` + `retail_network_id` + the point's address).

Nothing chosen, an incomplete address, or a non-NL/BE destination → the order falls back to plain home delivery.

**What is stored on the order** (carrier-neutral field names, so a future generic delivery-options layer can lift them):

| Field | Meaning |
|---|---|
| `MyParcelDeliveryOptions` | The raw widget payload JSON (source of truth) |
| `DeliveryType` | `standard` / `morning` / `evening` / `pickup` / … |
| `DeliveryDate` | The carrier's chosen date (when offered) |
| `PickupLocationCode` / `PickupNetworkId` | The canonical MyParcel pickup identifiers |
| `PickupLocation` (has_one Address) | The chosen point, mirrored for admin display |

### Checkout wiring (no core edit)

Core's `SinglePageCheckoutComponentConfig` hard-codes its component list with no add-component hook. Rather than swap the
class (an Injector `class:` override doesn't compose — only one module can win), the module appends its component with an
Injector `calls:` entry (`addComponent`). Injector merges service definitions, so this coexists with another module that
overrides the same config (e.g. `silvershop/vat-compliance`). `CheckoutComponentConfig::create()` resolves through the
Injector, so no silvershop/core edit is needed. A small upstream `updateComponents` extend hook in the core config would
be cleaner still; it is flagged as a recommended future PR rather than bundled here.

### Widget configuration

Defaults live on `SilverShop\ShippingMyParcel\Extension\MyParcelCheckoutExtension` (CDN URLs, `platform`, `carrier`,
`locale`, `pickup_default_view`) and `SilverShop\ShippingMyParcel\Extension\MyParcelOrderExtension.allowed_countries`
(default `['NL', 'BE']`). Override them in config as needed, e.g.:

```yaml
SilverShop\ShippingMyParcel\Extension\MyParcelCheckoutExtension:
  pickup_default_view: 'list'
```

## Cancelling labels

`cancelLabel()` asks MyParcel to delete the shipment and then **verifies it is actually gone** before reporting success,
so it never reports a cancellation that didn't happen.

**Limitation:** MyParcel's API can only delete shipments still in **concept** status. Because this module **produces the
label PDF when it creates the label** — which registers / pre-announces the shipment with the carrier — a created label
is no longer a concept, so `cancelLabel()` on it returns `false` and leaves the label unchanged. To remove such a
shipment, delete it in the **MyParcel dashboard** (possible the same day, before it is collected).

Making created labels cancellable through the API would need a **concept-first flow** (register the shipment as a
concept and produce the PDF only when printing) — a planned v2 change.

## Scope

- **v1** — labels (PostNL, carrier-extensible via config): a real carrier label + tracking from a placed order.
- **v2 (this release)** — checkout delivery-options + **carrier pickup-point** selection: home-vs-pickup chosen with the
  official MyParcel widget, stored on the order, routed into the consignment. Flat pricing (no per-option surcharge),
  NL/BE gate, graceful degrade.

Planned (later): per-option surcharges (pickup fee / signature / delivery windows) as a shipping modifier, carrier
delivery dates/time windows on the consignment, multi-carrier (DPD/bpost/DHL), opt-in auto-create on payment, bulk label
creation, concept-first create (API-cancellable labels).

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
