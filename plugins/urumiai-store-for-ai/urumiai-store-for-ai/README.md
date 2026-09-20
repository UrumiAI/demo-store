# UrumiAI Store for AI

**Version:** 0.1.0
**Author:** Urumi AI
**License:** Proprietary
**Status:** Standalone prototype — not wired into any repository or delivery lane

Exposes a WooCommerce store to AI agents as an **MCP-style JSON-RPC tool
surface**. An agent opens a shopping session, browses the catalog, fills a
basket on a shopper's behalf, and finishes by handing that person a link. The
link loads the basket into their own browser and drops them on checkout.

**The shopper's remaining job is to pay. That is the whole design.**

> **The agent cannot buy anything.** There is no order-placement, payment,
> refund or coupon tool — not disabled, *absent*. And once the shopper opens the
> checkout link, the basket is frozen: the agent cannot change what someone is
> in the middle of paying for. See [Safety boundaries](#safety-boundaries).

## Directory Structure

```
urumiai-store-for-ai.php                       # Main loader
urumiai-store-for-ai/
├── includes/
│   ├── class-adapter.php                      # Store adapter contract + canonical shapes
│   ├── class-fixture-adapter.php              # Deterministic in-memory store (no WP/WC)
│   ├── class-woocommerce-adapter.php          # Live WooCommerce binding
│   ├── class-session-store.php                # Session/claim persistence contract
│   ├── class-memory-session-store.php         # In-process store (tests)
│   ├── class-transient-session-store.php      # Transient-backed store (live)
│   ├── class-sessions.php                     # Session lifecycle + claim rules
│   ├── class-safety-policy.php                # Tiers, caps, idempotency, audit
│   ├── class-serializer.php                   # PII allowlist — the egress chokepoint
│   ├── class-tools.php                        # The 13 tools
│   ├── class-resources.php                    # Resource URIs
│   ├── class-server.php                       # JSON-RPC 2.0 dispatch (no WordPress)
│   ├── class-rest-controller.php              # WP REST binding + bearer auth
│   └── class-checkout-handoff.php             # Storefront claim-link consumer
├── schema/tools.json                          # Generated tool schema
└── README.md                                  # This file
```

Tests live outside the plugin directory, in `../tests/`, because a mu-plugin
deploy tars the whole plugin directory into every tenant docroot.

## Architecture

The MCP core is **framework-agnostic PHP**: `Urumiai_Store_For_Ai_Server::handle()`
takes a decoded JSON-RPC array and returns a response array. WordPress appears
in exactly four files — the WooCommerce adapter, the transient session store,
the REST controller and the checkout handoff. The suite enforces that boundary
rather than trusting it.

That split is what makes the surface testable: the suite drives the real server,
real tools, real session rules, real safety policy and real serializer on a
machine with no WordPress and no WooCommerce, substituting only the store and
the session backend.

```
agent ──JSON-RPC──▶ REST controller ──▶ Server ──▶ Tools ──▶ Sessions ──▶ Adapter ──▶ store
                    (WordPress)         (plain PHP)   │          │           │
                                                 Safety    Session      Serializer
                                                 policy     store       (allowlist)

shopper ──claim URL──▶ Checkout handoff ──▶ Sessions.redeem_claim() ──▶ their own cart ──▶ checkout
                       (WordPress)
```

## The session is the handle

An agent calls `create_session` once and then addresses **everything** by
`session_id`. The adapter's internal cart token is never serialized. Two
consequences worth stating plainly:

- An agent cannot address a basket it was not handed.
- A leaked transcript carries a handle this plugin issued, scoped to one
  shopper and expiring on its own.

A session moves through four states:

| State | Meaning | Cart writes |
|---|---|---|
| `open` | the agent is shopping | allowed |
| `handed_off` | a checkout link exists, nobody has opened it | allowed |
| `claimed` | a person opened the link and is at checkout | **refused** |
| `closed` | ended by `end_session`, or expired | refused |

## Tools

| Tool | Tier | Effect |
|---|---|---|
| `create_session` | session | Open a shopping session; returns `session_id` and the store profile |
| `get_session` | session | Read a session's state, expiry and cart |
| `end_session` | session | Close a session and discard its basket; safe to repeat |
| `search_products` | read | Search the catalog; filter by query, category, price, stock; cursor-paginated |
| `get_product` | read | One product by id or slug, with variations and stock |
| `list_categories` | read | Public categories with counts |
| `get_cart` | read | Read a session's basket |
| `add_to_cart` | cart | Add a line |
| `update_cart_item` | cart | Set a line's quantity |
| `remove_cart_item` | cart | Drop a line |
| `clear_cart` | cart | Empty the basket, leaving the session open |
| `create_checkout_handoff` | checkout | Mint a **single-use URL for a human**; places nothing |
| `get_handoff_status` | checkout | Has the shopper opened the link yet? Returns no order data |

Resources: `store://store/profile`, `store://catalog/products`,
`store://catalog/categories`, `store://policies/shipping`,
`store://policies/returns`, and the template `store://session/{session_id}`.

## The checkout handoff

This is the part that makes the plugin useful rather than merely safe.

The agent builds its basket server-side, as a line list this plugin owns; the
shopper's browser has never seen it. Sending them to `/checkout` would show an
empty cart.

So `create_checkout_handoff` mints a **claim**: a single-use, short-lived token
(15 minutes by default) returned inside a storefront URL. When a person opens
it, `class-checkout-handoff.php` redeems the claim, copies the basket into that
visitor's **own** cart, and redirects to checkout.

```
create_checkout_handoff ──▶ https://store.example/?urumiai_claim=<token>
                                     │
                       shopper opens it once
                                     ▼
              basket copied into their cart ──▶ /checkout ──▶ they pay
```

Why copy rather than point the visitor's session at the agent's? First because
WooCommerce does not support it — `WC_Session_Handler` exposes no public way to
rebind an initialized session, a lesson the integration suite taught us loudly
(see Version History). And because even forced, entangling two sessions fights
whatever session handler and object cache a host substitutes, and leaves the
shopper on a session they do not own. Copying costs a handful of
`add_to_cart()` calls and leaves them in their own session, where checkout,
taxes and shipping behave normally.

A line that sold out in the meantime is skipped with a notice rather than
aborting the handoff: arriving with three of four items and a message beats
arriving with nothing.

## Money

Every amount is an **integer in the currency's minor unit** (cents for USD, yen
for JPY) alongside an ISO-4217 code — `price_minor: 3200` with `currency: "USD"`
means $32.00. Never a float, never a pre-formatted string. Float arithmetic
loses cents, and an agent that formats currency itself will get it wrong less
often than one handed an ambiguous number.

The WooCommerce adapter reads `wc_get_price_decimals()` rather than assuming two.

## Store adapter assumptions

An adapter implements 13 methods and returns canonical shapes, never store
objects. Assumptions it must honor:

- **Visibility is the adapter's job.** Only `publish` + purchasable products may
  be returned. Filtering there means a bug in a tool handler cannot expose a
  draft.
- **Money in minor units**, as above.
- **Carts are addressed by an opaque token** that stays server-side. The
  WooCommerce adapter keys its own line list by it rather than binding a
  WooCommerce session — see that file's docblock for the bug that taught us to.
- **Refusals throw `Urumiai_Store_For_Ai_Adapter_Exception`**, which surfaces to
  the agent as a tool error, not a protocol error.
- **Adapters never format, translate or round for display.**

Two ship: `Fixture` (deterministic, in-memory, used by the tests) and
`WooCommerce` (live). The REST controller picks WooCommerce when it is loaded
and otherwise falls back to the fixture, tagging the response with an
`X-Urumiai-Store-For-Ai-Adapter` header so sample data is never mistaken for a
real catalog.

## Safety boundaries

**The line: an agent can fill a basket; only a human can pay.**

1. **No purchase path exists.** No order placement, payment capture, refund or
   coupon tool is implemented anywhere in the plugin. This is structural — there
   is no flag that turns it on. `create_checkout_handoff` returns a URL and
   creates nothing.
2. **A claimed basket is frozen.** Once the shopper opens the link, every cart
   tool refuses with `session_claimed`. An agent quietly changing the basket
   while someone is entering card details is exactly the failure this prevents.
3. **Claim links are single-use and expire.** A replayed or bookmarked URL is
   refused. The default life is 15 minutes.
4. **Capability tiers.** `session` and `read` are always on. `cart` and
   `checkout` are independently switchable via the `urumiai_store_for_ai_policy`
   filter. An unknown tier is denied.
5. **Caps**, all enforced *before* the write commits, with rollback on
   violation: max quantity per line (10), max distinct lines (20), optional
   subtotal ceiling, and a per-session rate limit (60/min).
6. **Idempotency keys.** A retried `add_to_cart` with the same key replays the
   first result instead of adding again.
7. **`dry_run`** on every mutating cart tool: validate and report, change
   nothing.
8. **PII allowlist.** `class-serializer.php` copies only named fields. Customer
   emails, addresses, order history and meta blobs have no serializer at all, so
   no handler can emit one by mistake — and neither can the internal cart token.
9. **Audit trail.** Every mutating call records tool, arguments and outcome.
10. **Bearer auth**, constant-time compared. With no token configured the
    endpoint refuses everything rather than defaulting to open.

### Deliberately out of scope

Order placement, payment, refunds, coupon creation, customer records, order
history, inventory writes, price changes, and anything in wp-admin.

## Installing

**1. Install.** Place `urumiai-store-for-ai.php` and `urumiai-store-for-ai/`
together in `wp-content/mu-plugins/`, or the pair inside a folder in
`wp-content/plugins/` and activate it.

**2. Configure a token** in `wp-config.php`:

```php
define('URUMIAI_STORE_FOR_AI_TOKEN', 'a-long-random-shared-secret');
```

**3. Optionally tune the policy:**

```php
add_filter('urumiai_store_for_ai_policy', function ($config) {
    $config['max_line_quantity']  = 5;
    $config['max_subtotal_minor'] = 250000; // $2,500.00
    $config['handoff_ttl_sec']    = 600;    // 10 minutes
    $config['checkout_enabled']   = false;  // browse + basket only
    return $config;
});
```

**4. Optionally publish policy text** an agent can quote, via the
`urumiai_store_for_ai_shipping_policy` and `urumiai_store_for_ai_returns_policy`
options.

## Attaching an agent

The endpoint speaks JSON-RPC 2.0 over
`POST /wp-json/urumiai-store-for-ai/v1/rpc`:

```bash
curl -sS https://store.example/wp-json/urumiai-store-for-ai/v1/rpc \
  -H 'Authorization: Bearer a-long-random-shared-secret' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

**A typical agent session:**

```jsonc
// 1. discover
{"jsonrpc":"2.0","id":1,"method":"initialize"}

// 2. open a session for this shopper — everything else takes its session_id
{"jsonrpc":"2.0","id":2,"method":"tools/call",
 "params":{"name":"create_session","arguments":{}}}

// 3. browse
{"jsonrpc":"2.0","id":3,"method":"tools/call",
 "params":{"name":"search_products","arguments":{"query":"grinder","in_stock_only":true}}}

// 4. fill the basket
{"jsonrpc":"2.0","id":4,"method":"tools/call",
 "params":{"name":"add_to_cart",
           "arguments":{"session_id":"sess_...","product_id":2,"variation_id":201,
                        "quantity":1,"idempotency_key":"chat-42-add-1"}}}

// 5. hand off to a person — the agent stops here
{"jsonrpc":"2.0","id":5,"method":"tools/call",
 "params":{"name":"create_checkout_handoff","arguments":{"session_id":"sess_..."}}}

// 6. optionally, did they open it?
{"jsonrpc":"2.0","id":6,"method":"tools/call",
 "params":{"name":"get_handoff_status","arguments":{"handoff_token":"claim_..."}}}
```

The handoff response carries `requires_human: true` and a notice stating that no
order was placed. **An agent should present the URL, not try to follow it** —
following it would spend the single use and freeze the basket for nobody.

## Verification

Two suites, because they prove different things.

**Unit — 54 tests, no WordPress required:**

```
cd ..            # the plugin's container directory
npm --prefix tests test
```

Runs the real server, tools, session rules, safety policy and serializer
against the fixture adapter and the in-memory session store.

**Integration — 36 tests against real WordPress + WooCommerce:**

```
tests/integration/run.sh [/path/to/woocommerce]
```

Boots WordPress Playground (WordPress + PHP + SQLite in wasm — no MySQL, no PHP
extensions, no hosting account), mounts this plugin and WooCommerce, seeds a
catalog, and drives the whole flow over HTTP: an agent fills a basket, a
separate cookie jar opens the claim link, and the test asserts via the
WooCommerce Store API that the shopper's own cart holds exactly what the agent
built, at the right total.

Pass a WooCommerce directory to mount it rather than installing it from
wordpress.org — installing inside wasm is memory-hungry and may OOM on a small
box. Last run: **36/36 against WooCommerce 10.7.0, WordPress latest, PHP 8.3.**

This is the suite that covers the four files the unit suite structurally cannot:
`class-rest-controller.php`, `class-woocommerce-adapter.php`,
`class-transient-session-store.php` and `class-checkout-handoff.php`.

Regenerate the published schema after changing a tool definition:

```
php tests/php/generate-schema.php
```

## Version History

### 0.1.0 (Current)
- Initial standalone prototype: JSON-RPC core, 13 tools, 6 resources, session
  layer with single-use checkout claims, adapter contract with fixture +
  WooCommerce implementations, two session stores, safety policy, PII allowlist
  serializer, WP REST binding, storefront handoff, generated tool schema, unit
  suite.
- Added the integration suite, and fixed what it immediately found: the
  WooCommerce adapter tried to bind `WC()->session` to the cart token via
  `set_customer_id()`. `WC_Session_Handler` has no such public method, so the
  bind silently did nothing, every request ran against the anonymous session,
  and an agent's cart did not survive one round trip. The adapter now owns its
  line list instead of borrowing a WooCommerce session. Nothing had shipped.

## Requirements

- **WordPress**: 5.8 or higher (for the REST binding and the handoff)
- **WooCommerce**: 7.0 or higher (for the live adapter)
- **PHP**: 7.4 or higher

## License

Proprietary - Copyright © 2026 Urumi AI. All rights reserved.

This plugin is provided exclusively for sites hosted on the Urumi platform.
Unauthorized use, modification, or distribution is prohibited.
