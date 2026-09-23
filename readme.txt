=== Payment Integrations for Bachs ===
Tags: payments, woocommerce, memberships, forms, donations
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bachs hosted payments in WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms, and GiveWP.

== Description ==

Payment Integrations for Bachs connects supported WordPress commerce, membership, form, and donation plugins to Bachs hosted checkout.

Version 1.0.0 supports one-time payments for:

* WooCommerce
* Paid Memberships Pro
* Gravity Forms
* Fluent Forms Pro payment forms
* GiveWP

The plugin uses a shared payment core with exact amount verification, signed webhook verification, event deduplication, safe fulfillment, reconciliation tools, diagnostics, and provider-confirmed refunds.

Customers are redirected to Bachs hosted checkout to enter payment details. The plugin does not collect or store full card numbers, CVV/CVC values, or other raw card credentials.

Recurring payments and subscriptions are not included in version 1.0.0.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin, or install it from the WordPress Plugin Directory when available.
2. Activate Payment Integrations for Bachs.
3. Add the Bachs configuration constants described below to `wp-config.php`.
4. In your Bachs account, configure the webhook endpoint as `https://example.com/wp-json/etchpoint-bachs/v1/webhook`, replacing `example.com` with your site domain.
5. Enable Bachs in the supported WordPress integration you want to use.
6. Use sandbox mode first and confirm checkout, webhook completion, and reconciliation before switching to live mode.

== Configuration ==

The plugin intentionally keeps Bachs secret credentials out of the WordPress database. Configure them in `wp-config.php`.

Required values:

* `ETCHPOINT_BACHS_ENVIRONMENT` - `sandbox` or `live`. Defaults to `sandbox` if omitted.
* `ETCHPOINT_BACHS_SANDBOX_SECRET_KEY` - sandbox secret key when sandbox mode is active.
* `ETCHPOINT_BACHS_LIVE_SECRET_KEY` - live secret key when live mode is active.
* `ETCHPOINT_BACHS_WEBHOOK_SECRET` - active Bachs webhook signing secret.

Optional values:

* `ETCHPOINT_BACHS_WEBHOOK_SECRET_PREVIOUS` - previous webhook secret during secret rotation.
* `ETCHPOINT_BACHS_ORGANIZATION_ID` - pins webhook/payment verification to one Bachs organization when configured.

Live payments require HTTPS.

== Refunds ==

Administrators can request Bachs refunds from Bachs Payments > Refunds.

Full and partial refunds are supported. Bachs currently permits one refund operation per charge, so a partial refund consumes the refund operation for that charge.

A refund is not treated as complete merely because the API accepted the request. The plugin waits for signed provider evidence confirming the refund before applying the corresponding local refund state.

== Reconciliation and diagnostics ==

Bachs Payments > Reconciliation can safely re-check provider state and retry incomplete WordPress fulfillment.

Bachs Payments > Diagnostics provides read-only configuration and integration checks. Secret values and full provider responses are not displayed.

== External Service ==

This plugin requires Bachs, a third-party payment service provided by Bachs Technologies Limited. The plugin contacts Bachs only when a configured administrator or customer uses functionality that requires the payment service, such as creating or retrieving checkout sessions, verifying payments, requesting refunds, or receiving Bachs webhooks.

Bachs service: https://bachs.io/

Bachs Merchant Terms: https://bachs.io/buyer-terms

Bachs Privacy Policy: https://bachs.io/privacy

Bachs developer documentation: https://docs.bachs.io/

Service endpoints used by the plugin:

* Sandbox API: https://sandbox-api.bachs.io
* Live API: https://api.bachs.io
* Hosted checkout: https://checkout.bachs.io

A Bachs merchant account and applicable Bachs service access are required to process payments. Bachs may charge fees under its own terms and pricing. Etchpoint does not control Bachs availability, eligibility decisions, fees, settlement, or payment processing rules.

== Data sent to Bachs ==

For checkout creation, the plugin sends the transaction currency and amount, success and cancel return URLs, an opaque payment reference, and correlation metadata identifying the WordPress integration and local transaction record.

Payment details entered by the customer on hosted checkout are submitted directly to Bachs rather than to this plugin.

When an administrator initiates a refund, the plugin sends the Bachs charge identifier, requested refund amount, internal reference/idempotency information, and an optional administrator-entered refund reason.

The plugin also retrieves payment or refund records from Bachs when verification, reconciliation, diagnostics, or webhook processing requires authoritative provider state.

== Local data and privacy ==

The plugin stores operational payment records in custom WordPress database tables. These records can include the integration name, local WordPress record identifier, Bachs checkout/charge/refund identifiers, amount, currency, processing status, timestamps, error information, and an optional refund reason.

For webhook deduplication and audit processing, the plugin stores provider event identifiers and a SHA-256 hash of the webhook payload. It does not persist the complete raw webhook payload.

The plugin does not store raw card credentials.

Payment, webhook, reconciliation, and refund records are intentionally retained when the plugin is uninstalled. They can be important financial and audit records and are not automatically deleted. Site owners are responsible for retention and deletion in accordance with their legal and operational requirements.

Because local transaction identifiers can link these records to orders, memberships, form submissions, or donations managed by other plugins, those integrations may contain personal data under their own retention policies.

== Frequently Asked Questions ==

= Do I need a Bachs account? =

Yes. Bachs provides the external payment service used by this plugin.

= Does the plugin store card details? =

No. Customers enter payment details on Bachs hosted checkout. This plugin does not store full card numbers or CVV/CVC values.

= Can I use sandbox mode? =

Yes. Sandbox is the default environment when `ETCHPOINT_BACHS_ENVIRONMENT` is not defined. Use the matching sandbox secret key and webhook configuration.

= What happens if Bachs confirms payment but WordPress fulfillment fails? =

The payment remains recorded and can be safely rechecked from Bachs Payments > Reconciliation. The reconciliation flow verifies provider state before retrying local fulfillment.

= Are recurring payments supported? =

No. Version 1.0.0 is limited to one-time payment flows.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Added one-time Bachs hosted checkout integrations for WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms Pro, and GiveWP.
* Added signed webhook verification, deduplication, reconciliation, diagnostics, and refund support.
