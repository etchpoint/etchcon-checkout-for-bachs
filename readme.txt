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
3. Open Bachs Payments > Settings and choose Sandbox or Live.
4. Enter the matching Bachs secret API key and webhook signing secret, then save.
5. Copy the webhook endpoint shown on the settings screen into your Bachs webhook configuration.
6. Subscribe the endpoint to `collection.succeeded`, `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.created`, `refund.paid`, and `refund.failed`.
7. Enable Bachs in the supported WordPress integration you want to use.
8. Use sandbox mode first and confirm checkout, webhook completion, and reconciliation before switching to live mode.

== Configuration ==

Bachs Payments > Settings provides one shared configuration for WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms, and GiveWP.

The active environment requires:

* A matching Bachs secret API key (`sk_sandbox_...` for Sandbox or `sk_live_...` for Live).
* The active Bachs webhook signing secret.

The settings screen also supports an optional previous webhook secret during secret rotation and an optional organization ID for stricter provider verification.

For sites that prefer secret management through `wp-config.php`, the following constants remain supported and take precedence over dashboard values when defined:

* `ETCHPOINT_BACHS_ENVIRONMENT` - `sandbox` or `live`.
* `ETCHPOINT_BACHS_SANDBOX_SECRET_KEY` - sandbox secret key.
* `ETCHPOINT_BACHS_LIVE_SECRET_KEY` - live secret key.
* `ETCHPOINT_BACHS_WEBHOOK_SECRET` - active webhook signing secret.
* `ETCHPOINT_BACHS_WEBHOOK_SECRET_PREVIOUS` - previous webhook secret during secret rotation.
* `ETCHPOINT_BACHS_ORGANIZATION_ID` - optional organization identifier.

Dashboard-managed secret values are stored in the WordPress options table and are never displayed back in plaintext after saving. They are deleted when the plugin is uninstalled. Financial/audit records remain retained as described below.

Live payments require HTTPS.

== Refunds ==

Administrators can request Bachs refunds from Bachs Payments > Refunds.

Full refunds are supported. Partial refunds are supported when the Bachs settlement currency matches the original WordPress transaction currency. If Bachs settled the charge in a different currency, use a full refund so the plugin does not guess an exchange rate. Bachs currently permits one refund operation per charge, so a partial refund consumes the refund operation for that charge.

A refund is not treated as complete merely because the API accepted the request. The plugin waits for signed provider evidence confirming the refund before applying the corresponding local refund state.

== Reconciliation and diagnostics ==

Bachs Payments > Reconciliation can safely re-check provider state and retry incomplete WordPress fulfillment.

The plugin also schedules an hourly WordPress cron task that checks up to 20 eligible payment records per run. With Bachs configured, these background checks can contact the Bachs API to verify transaction status and retry incomplete local fulfillment without an administrator opening the dashboard. Actual execution depends on the site's WordPress cron setup. The scheduled task is removed when the plugin is deactivated or uninstalled.

Bachs Payments > Diagnostics provides read-only configuration and integration checks. Secret values and full provider responses are not displayed.

== External Service ==

This plugin requires Bachs, a third-party payment service provided by Bachs Technologies Limited. Once configured, the plugin contacts Bachs to create or retrieve checkout sessions, verify payments, request refunds, and reconcile eligible payment records. Requests can be triggered by customer checkout, administrator actions, automatic background reconciliation, or processing incoming Bachs webhooks. Background reconciliation does not require an administrator or customer to be actively using the site at the time of the request.

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

For checkout creation, the plugin sends the transaction currency and amount, success and cancel return URLs, an opaque payment reference, correlation metadata identifying the WordPress integration and local transaction record, and customer contact information required for hosted checkout. This includes the customer email address and can include the customer name and phone number when available from the originating integration.

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

Yes. Sandbox is the default environment. Choose Sandbox under Bachs Payments > Settings and use the matching sandbox secret key and webhook configuration.

= What happens if Bachs confirms payment but WordPress fulfillment fails? =

The payment remains recorded and can be safely rechecked from Bachs Payments > Reconciliation. The reconciliation flow verifies provider state before retrying local fulfillment.

= Are recurring payments supported? =

No. Version 1.0.0 is limited to one-time payment flows.

== Source code and development ==

Source code, build scripts, and development instructions are available at:
https://github.com/etchpoint/payment-integrations-for-bachs/

See README.md in the repository for Composer setup, quality checks, and release packaging instructions. The release build script is .github/scripts/build-release.sh and the CI workflow is .github/workflows/ci.yml.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Added one-time Bachs hosted checkout integrations for WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms Pro, and GiveWP.
* Added signed webhook verification, deduplication, reconciliation, diagnostics, and refund support.
