# Architecture

## EtchCon Checkout for Bachs

EtchCon Checkout for Bachs is an open-source WordPress plugin maintained by **Etchpoint**. It provides Bachs payment support for multiple WordPress commerce, membership, form, and donation systems through one shared payment core and isolated host integrations.

Initial integrations:

- WooCommerce
- Paid Memberships Pro
- Gravity Forms
- Fluent Forms
- GiveWP

The plugin is designed as a **modular monolith**: one installable WordPress plugin, one Bachs integration core, and separate adapters for each supported host plugin.

---

## 1. Architectural Goals

The architecture is designed around a small set of goals:

- process Bachs payments through supported WordPress extension APIs;
- keep payment verification independent from browser redirects;
- prevent duplicate fulfillment;
- maintain an auditable mapping between Bachs payments and WordPress records;
- recover safely when Bachs payment state and WordPress application state disagree;
- minimize API privileges and stored customer data;
- keep integrations isolated enough to maintain independently;
- remain suitable for distribution through WordPress.org.

The plugin is not intended to be a generic payment-provider framework, accounting system, payout platform, marketplace settlement system, or remote SaaS service.

---

## 2. High-Level Design

```text
Customer Browser
      │
      ▼
Supported WordPress Application
      │
      ▼
Integration Adapter
      │
      ▼
Shared Payment Core
      │
      ├── Payment intents
      ├── Money handling
      ├── Bachs API client
      ├── Webhook verification
      ├── Payment evidence
      ├── Event deduplication
      ├── Refund handling
      ├── Reconciliation
      └── Diagnostics
      │
      ▼
Bachs Hosted Checkout
      │
      ▼
Signed Bachs Webhook
      │
      ▼
Verified Payment
      │
      ▼
Native Host Completion API
```

The browser redirect is a user-experience mechanism only. It never authorizes or completes a payment.

---

## 3. Package Structure

The PHP namespace is:

```php
Etchpoint\BachsIntegrations
```

The WordPress/database prefix is:

```text
etchpoint_bachs_
```

The repository is organized approximately as follows:

```text
etchcon-checkout-for-bachs/
├── etchcon-checkout-for-bachs.php
├── uninstall.php
├── readme.txt
├── README.md
├── LICENSE
├── composer.json
├── composer.lock
├── src/
│   ├── Bootstrap/
│   ├── Core/
│   │   ├── Contracts/
│   │   ├── Checkout/
│   │   ├── Payment/
│   │   ├── Money/
│   │   ├── Webhook/
│   │   ├── Refund/
│   │   ├── Reconciliation/
│   │   └── Security/
│   ├── Bachs/
│   ├── Integrations/
│   │   ├── WooCommerce/
│   │   ├── PMPro/
│   │   ├── GravityForms/
│   │   ├── FluentForms/
│   │   └── GiveWP/
│   ├── Admin/
│   └── Persistence/
├── assets/
├── tests/
└── docs/
```

Only integrations whose host APIs are present are initialized. A missing optional host plugin must never cause a fatal error.

---

## 4. Core and Adapter Boundaries

### Shared core

The shared core owns Bachs-specific concerns:

- API communication;
- payment-intent mapping;
- exact money representation;
- signature verification;
- webhook replay protection;
- event deduplication;
- payment evidence validation;
- provider/application state separation;
- Bachs-specific reconciliation;
- secure diagnostics and logging.

The core does not directly implement WooCommerce, PMPro, Gravity Forms, Fluent Forms, or GiveWP business rules.

### Integration adapters

Each adapter owns host-specific behavior:

- locating or creating the pending host transaction;
- obtaining the trusted server-side amount and currency;
- calling the host application's supported completion APIs;
- handling host-specific refund behavior where supported;
- detecting whether fulfillment has already occurred.

Host-plugin database tables are not modified directly when supported APIs exist.

---

## 5. Payment Intent Model

A local payment intent is created **before** the customer is sent to Bachs.

The intent records the immutable payment expectation and the mapping to the local WordPress object. Conceptually it contains:

```text
uuid
integration
local object type
local object ID
environment
opaque reference
idempotency key
expected amount
expected currency
Bachs checkout ID
authoritative Bachs charge/payment ID
provider status
application status
attempt
processing timestamp
errors
timestamps
```

The successful provider charge/payment identifier is nullable until a successful payment is established, but once known it is unique across intents.

The database invariant is:

```sql
charge_id VARCHAR(191) NULL,
UNIQUE KEY provider_charge_id (charge_id)
```

This enforces an important property at the database level:

> One successful Bachs charge cannot satisfy two different WordPress payment intents.

---

## 6. Money Representation

Payment amounts are never validated with floating-point equality.

The core uses an exact Money representation consisting of:

```text
canonical decimal amount
validated currency code
```

Normalization rejects scientific notation, locale-formatted numbers, invalid signs, and invalid currency precision. Comparisons are exact after canonical normalization.

The trusted amount and currency always originate from the server-side host application, not from browser input.

---

## 7. Checkout

One-time payments use Bachs hosted checkout with raw pricing rather than mirroring every WooCommerce product, membership level, form total, or donation amount into a duplicate Bachs catalog.

The checkout request is created server-side from trusted values and includes:

- `pricing.currency`;
- `pricing.amount`;
- `success_url`;
- `cancel_url`;
- an opaque unique reference;
- minimal correlation metadata.

The 1.0.0 payment flow intentionally does not depend on browser-provided totals, deprecated return fields, adaptive pricing, or client-side payment authorization.

---

## 8. Idempotency

Bachs idempotency is used for POST operations where retries could create duplicate financial state.

A retry of the **same logical operation** reuses the same idempotency key and request body. A new logical checkout attempt receives a new attempt number and therefore a new key.

Conceptual key format:

```text
etp:{site_hash}:{integration}:{local_id}:{operation}:{attempt}
```

Idempotency does not replace local deduplication or host-level idempotent fulfillment; all three layers are used where appropriate.

---

## 9. Webhook Boundary

The public webhook route is:

```text
POST /wp-json/etchpoint-bachs/v1/webhook
```

Webhook authentication is based on Bachs' signed webhook protocol rather than a WordPress login session.

The processing boundary is:

```text
raw request
→ signature verification
→ timestamp freshness
→ event-ID deduplication
→ event persistence
→ intent lookup
→ payment evidence validation
→ VerifiedPayment
→ atomic processing claim
→ adapter completion
→ processed state
```

JSON is decoded only after signature verification of the raw request body.

The verifier supports multiple active V2 signatures during signing-secret rotation and uses constant-time comparison.

---

## 10. Initial Webhook Events

For one-time payments, the 1.0.0 integration is designed around these Bachs events:

```text
collection.succeeded
collection.failed
collection.underpaid
checkout.expired
```

When WordPress-side refunds are enabled, the plugin also processes:

```text
refund.created
refund.paid
refund.failed
```

A successful-looking event name alone is never sufficient to fulfill a WordPress transaction. The event must pass the complete payment-evidence checks.

---

## 11. Payment Evidence

A `VerifiedPayment` object is created only after the core has established sufficient evidence for a specific locally-created intent.

Evidence includes, as applicable:

- valid webhook signature;
- fresh signed timestamp;
- unique provider event ID;
- accepted event type;
- correct environment;
- known local payment intent;
- matching provider checkout/payment identifiers;
- reference correlation;
- exact expected amount;
- exact currency semantics;
- unique successful provider charge;
- compatible local terminal state.

If the webhook payload is insufficient to establish payment safely, the core retrieves authoritative provider state before creating `VerifiedPayment`.

Ambiguous payments are sent to review rather than treated as paid.

---

## 12. Provider State and Application State

Provider state and WordPress application state are stored separately.

Example provider states:

```text
created
open
processing
succeeded
failed
underpaid
expired
cancelled
refunded
partially_refunded
```

Example application states:

```text
pending
processing
applied
failed
requires_review
```

This permits a state such as:

```text
provider: succeeded
application: failed
```

which means Bachs has confirmed the money but the WordPress host application did not complete successfully. That mismatch remains visible and recoverable.

---

## 13. Concurrency and Recovery

Webhook delivery, reconciliation, and administrative recovery can race with one another.

Before host fulfillment, the intent is claimed atomically by changing the application state to `processing`. A processing timestamp allows stale claims to be identified after an interrupted PHP request.

Every adapter is also idempotent. Before repeating fulfillment it asks whether the corresponding host transaction has already been completed with the same Bachs transaction. If so, only the local Etchpoint state is repaired.

The event inbox uses the same principle so an interrupted event does not remain permanently locked.

---

## 14. Integration Adapters

### WooCommerce

The WooCommerce adapter uses supported WooCommerce APIs, including `WC_Payment_Gateway`, order CRUD, modern checkout integration, and HPOS-compatible access patterns.

A verified payment completes the order through WooCommerce's native payment-completion behavior rather than directly forcing an order database status.

### Paid Memberships Pro

The PMPro adapter uses the supported PMPro gateway/order APIs available in the target PMPro version. Membership activation follows PMPro's expected completion flow rather than direct table manipulation.

### Gravity Forms

The Gravity Forms adapter is based on the official `GFPaymentAddOn` framework and its supported feed, redirect, callback, transaction, and payment-status mechanisms.

### Fluent Forms

The Fluent Forms integration loads only when the Fluent Forms Pro payment extension APIs are present and uses the supported payment method/processor abstractions.

### GiveWP

The GiveWP adapter uses GiveWP's supported payment-gateway registry and `RedirectOffsite` command for one-time donations. Browser redirects do not grant payment state; the shared signed Bachs webhook verifies the payment before the donation is completed through GiveWP's donation model API.

---

## 15. API Credential Model

The plugin follows least-privilege credential design.

For the initial one-time payment flow, the intended Bachs capability is:

```text
payments:write
```

Additional unrelated capabilities are not required merely because they are available in the Bachs dashboard.

When WordPress-side refunds are deliberately enabled, refund capability is added separately:

```text
refunds:write
```

The plugin does not require payout, transfer, webhook-management, balance, connected-account, product, subscription, or customer-management permissions for ordinary one-time checkout unless a future feature explicitly needs them.

There is no payout code path in the plugin.

Separate Bachs keys should be used per site and environment.

---

## 16. Secret Handling

Secrets can be configured through WordPress settings or externally through server configuration/constants for hardened deployments.

Security rules include:

- never display complete secrets after storage;
- never place secrets in JavaScript configuration, HTML output, REST responses, logs, or diagnostics;
- centralize redaction;
- permit external configuration to lock credential editing in wp-admin;
- keep Bachs API hosts fixed rather than accepting arbitrary production API URLs.

A full server/PHP compromise is outside the protection boundary of application-level secret storage. The hardened configuration primarily reduces exposure from WordPress administrator-session compromise.

---

## 17. Refunds

Refund state is represented independently from the original payment:

```text
requested
processing
succeeded
failed
requires_review
```

A refund is not considered complete while the provider reports it as processing.

Before initiating a refund, the core validates the local payment mapping, provider transaction ownership, refund amount, permissions, and idempotency state. Host applications are updated only through their supported refund mechanisms.

---

## 18. Reconciliation

Reconciliation is intentionally limited to this plugin's Bachs-to-WordPress mappings.

Its purpose is to repair cases such as:

```text
Bachs: succeeded
WordPress application: pending or failed
```

Recovery revalidates provider evidence, checks whether host fulfillment already occurred, and either repairs the local mapping or safely invokes the host's native completion flow.

The plugin is not intended to become a cross-provider accounting or reconciliation platform.

---

## 19. Privacy and Logging

The plugin minimizes duplicated personal information. Its persistence primarily records identifiers, amounts, currencies, states, timestamps, and diagnostic outcomes required to process and recover payments.

Logs are diagnostic rather than accounting records and use centralized secret redaction. Debug logging is opt-in and must remain safe for payment environments.

Because Bachs is an external service, the WordPress.org documentation discloses when transaction/customer information is sent to Bachs and links to the relevant Bachs service, terms, and privacy information.

---

## 20. Quality and Security Gates

The project uses automated and manual quality gates including:

```text
PHPCS + WordPress Coding Standards
PHPCompatibilityWP
PHPStan
PHPUnit
Composer audit
WordPress Plugin Check
WP_DEBUG-clean testing
integration testing
adversarial payment-security testing
```

The CI matrix targets supported PHP versions beginning with PHP 8.1.

Security testing covers forged callbacks, forged/replayed webhooks, amount mutation, cross-order mapping, duplicate events, race conditions, secret leakage, authorization failures, and recovery after host-completion failures.

---

## 21. Release Model

The plugin begins at version:

```text
1.0.0
```

Development is incremental, but internal implementation checkpoints do not require artificial public `0.x` package versions.

The WordPress.org release package contains the production Composer autoloader and required runtime dependencies. Development-only dependencies, tests, IDE metadata, and unrelated build artifacts are excluded from the distributable ZIP.

GitHub is the public development repository. WordPress.org SVN is used for WordPress.org release distribution once a release is ready.

---

## 22. Security Invariants

The following properties define the payment-safety boundary of the plugin:

```text
Hosted checkout
Signed webhooks
Freshness + event deduplication
Exact money comparisons
Known locally-created payment intents
Unique successful provider charge per intent mapping
Least-privileged API credentials
No payout permission or payout code
No browser-authorized fulfillment
No arbitrary production API host
No direct host-plugin database hacks when supported APIs exist
Provider state separated from application state
Atomic processing claims
Idempotent host fulfillment
Recoverable stale processing states
Redacted secrets and diagnostics
```

These invariants apply across every supported integration.
