"use strict";

// urumiai-store-for-ai end-to-end suite.
//
// Every behavioral test drives the REAL shipped server — loader, JSON-RPC
// dispatch, session rules, tool handlers, safety policy and serializer —
// through tests/php/store-cli.php against the fixture adapter and the in-memory
// session store. Nothing is mocked except the store and the session backend,
// and only because this host has no WordPress and no WooCommerce.
//
// The suite is organized around the claims that actually matter:
//   * protocol    — JSON-RPC 2.0 conformance and the error model
//   * session     — the handle lifecycle: open, expire, close
//   * catalog     — reads return what they should, and hide what they must
//   * cart        — the lifecycle works and the arithmetic is right
//   * safety      — caps, tiers, idempotency, dry-run and rate limiting hold
//   * handoff     — single use, expiry, and the frozen-basket rule
//   * no-purchase — the structural claim: nothing here can buy anything
//   * privacy     — the allowlist strips customer data AND internal handles
//   * schema      — the published schema matches the implementation

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("fs");
const path = require("path");
const { spawnSync } = require("child_process");

const ROOT = path.join(__dirname, "..");
const PLUGIN_DIR = path.join(ROOT, "urumiai-store-for-ai");
const LOADER = path.join(ROOT, "urumiai-store-for-ai.php");
const INCLUDES = path.join(PLUGIN_DIR, "includes");
const SCHEMA = path.join(PLUGIN_DIR, "schema", "tools.json");
const CLI = path.join(__dirname, "php", "store-cli.php");

const PHP_BIN = process.env.PHP_BIN || "php";
const EXPECTED_VERSION = "0.1.0";

// The in-memory store hands out predictable handles, so a test can name the
// session and claim it just created. See class-memory-session-store.php.
const SESSION = "sess_000001";
const CLAIM = "claim_000001";

/**
 * Run a session: each request is one JSON-RPC object (or a `__control` object
 * standing in for the storefront), responses come back in order. One process
 * keeps session and cart state across the whole run.
 */
function session(requests, { config, clock, advance } = {}) {
  const args = [CLI];
  if (config) args.push(`--config=${JSON.stringify(config)}`);
  if (clock) args.push(`--clock=${clock}`);
  if (advance) args.push(`--advance=${advance}`);

  const input = requests.map((r) => JSON.stringify(r)).join("\n") + "\n";
  const res = spawnSync(PHP_BIN, args, { input, encoding: "utf8" });

  assert.equal(res.status, 0, `CLI exited ${res.status}\nstderr: ${res.stderr}`);
  assert.equal(res.stderr.trim(), "", `CLI wrote to stderr:\n${res.stderr}`);

  return res.stdout
    .trim()
    .split("\n")
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

/** Shorthand for a tools/call request object. */
function call(name, args = {}, id = 1) {
  return { jsonrpc: "2.0", id, method: "tools/call", params: { name, arguments: args } };
}

/** Call one tool in a fresh process and return its MCP result. */
function callTool(name, args = {}, opts) {
  const [response] = session([call(name, args)], opts);
  return response.result;
}

/** Open a session, then run more requests in the same process. */
function withSession(requests, opts) {
  const responses = session([call("create_session", {}, 1), ...requests], opts);
  return responses.slice(1);
}

/** Recursively collect every key name in a structure. */
function allKeys(node, into = new Set()) {
  if (Array.isArray(node)) {
    node.forEach((child) => allKeys(child, into));
  } else if (node && typeof node === "object") {
    for (const [key, value] of Object.entries(node)) {
      into.add(key);
      allKeys(value, into);
    }
  }
  return into;
}

// --- Protocol ----------------------------------------------------------------

test("initialize advertises the protocol, capabilities and server identity", () => {
  const [res] = session([{ jsonrpc: "2.0", id: 1, method: "initialize" }]);
  assert.equal(res.jsonrpc, "2.0");
  assert.equal(res.id, 1);
  assert.equal(res.result.protocolVersion, "2025-06-18");
  assert.equal(res.result.serverInfo.name, "urumiai-store-for-ai");
  assert.equal(res.result.serverInfo.version, EXPECTED_VERSION);
  assert.ok(res.result.capabilities.tools, "must advertise tools");
  assert.ok(res.result.capabilities.resources, "must advertise resources");
  assert.match(res.result.instructions, /create_session/,
    "instructions must tell an agent where to start");
});

test("unknown methods return -32601 and bad params return -32602", () => {
  const [notFound, badParams] = session([
    { jsonrpc: "2.0", id: 1, method: "does/not/exist" },
    { jsonrpc: "2.0", id: 2, method: "tools/call", params: {} },
  ]);
  assert.equal(notFound.error.code, -32601);
  assert.equal(badParams.error.code, -32602);
});

test("a malformed envelope is rejected as an invalid request", () => {
  const [res] = session([{ id: 1, method: "ping" }]);
  assert.equal(res.error.code, -32600);
});

test("notifications produce no response", () => {
  const responses = session([
    { jsonrpc: "2.0", method: "notifications/initialized" },
    { jsonrpc: "2.0", id: 7, method: "ping" },
  ]);
  assert.equal(responses.length, 1, "only the ping should answer");
  assert.equal(responses[0].id, 7);
});

test("store refusals are successful responses carrying isError, not JSON-RPC errors", () => {
  // The distinction an agent depends on: retryable/re-plannable vs broken.
  const [res] = session([call("get_product", { product: "nope" })]);
  assert.equal(res.error, undefined, "must not be a protocol error");
  assert.equal(res.result.isError, true);
  assert.equal(res.result.structuredContent.error, "not_found");
});

// --- Session lifecycle -------------------------------------------------------

test("create_session returns a handle and the store profile", () => {
  const result = callTool("create_session", {});
  assert.equal(result.isError, false);

  const payload = result.structuredContent;
  assert.equal(payload.session_id, SESSION);
  assert.equal(payload.session.state, "open");
  assert.ok(payload.session.expires_at > payload.session.created_at, "a session must expire");
  assert.equal(payload.store.currency, "USD");
});

test("every session-bound tool refuses an unknown session id", () => {
  for (const [name, args] of [
    ["get_session", { session_id: "sess_999999" }],
    ["get_cart", { session_id: "sess_999999" }],
    ["add_to_cart", { session_id: "sess_999999", product_id: 1 }],
    ["update_cart_item", { session_id: "sess_999999", item_key: "line_1_0", quantity: 1 }],
    ["remove_cart_item", { session_id: "sess_999999", item_key: "line_1_0" }],
    ["clear_cart", { session_id: "sess_999999" }],
    ["create_checkout_handoff", { session_id: "sess_999999" }],
  ]) {
    const result = callTool(name, args);
    assert.equal(result.isError, true, `${name} must reject an unknown session`);
    assert.equal(result.structuredContent.error, "session_not_found");
  }
});

test("a session expires, and says so rather than looking unknown", () => {
  const [res] = withSession([call("get_session", { session_id: SESSION }, 2)], {
    config: { session_ttl_sec: 10 },
    clock: 1758240000,
    advance: 20,
  });
  assert.equal(res.result.isError, true);
  assert.equal(res.result.structuredContent.error, "session_expired");
});

test("end_session closes a session and is safe to repeat", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1 }, 2),
    call("end_session", { session_id: SESSION }, 3),
    call("end_session", { session_id: SESSION }, 4),
    call("get_cart", { session_id: SESSION }, 5),
  ]);

  assert.equal(responses[1].result.structuredContent.closed, true);
  assert.equal(responses[2].result.isError, false, "a second end_session is not an error");
  assert.equal(responses[2].result.structuredContent.closed, false);
  assert.equal(responses[3].result.structuredContent.error, "session_not_found",
    "the session must be gone afterwards");
});

test("sessions are isolated: one cannot see or touch another's basket", () => {
  const responses = session([
    call("create_session", {}, 1),
    call("create_session", {}, 2),
    call("add_to_cart", { session_id: "sess_000001", product_id: 1, quantity: 2 }, 3),
    call("get_cart", { session_id: "sess_000002" }, 4),
  ]);

  assert.equal(responses[2].result.structuredContent.cart.item_count, 2);
  assert.equal(responses[3].result.structuredContent.cart.item_count, 0,
    "the second session must have its own empty cart");
  assert.equal(responses[3].result.structuredContent.cart.lines.length, 0);
});

// --- Catalog reads -----------------------------------------------------------

test("search returns published purchasable products with integer minor-unit prices", () => {
  const result = callTool("search_products", {});
  assert.equal(result.isError, false);

  const { products, total } = result.structuredContent;
  assert.equal(products.length, 3, "fixture exposes exactly 3 purchasable products");
  assert.equal(total, 3);

  for (const product of products) {
    assert.equal(typeof product.price_minor, "number");
    assert.ok(Number.isInteger(product.price_minor), "prices must be integers, never floats");
    assert.equal(product.currency, "USD");
  }
});

test("draft and unpurchasable products are invisible to every read path", () => {
  const search = callTool("search_products", {}).structuredContent.products.map((p) => p.id);
  assert.ok(!search.includes(4), "draft product 4 must not appear");
  assert.ok(!search.includes(5), "unpurchasable product 5 must not appear");

  for (const identifier of [4, 5, "unreleased-roaster", "discontinued-grinder"]) {
    const direct = callTool("get_product", { product: String(identifier) });
    assert.equal(direct.isError, true, `direct fetch of ${identifier} must fail`);
    assert.equal(direct.structuredContent.error, "not_found");
  }
});

test("catalog reads need no session at all", () => {
  for (const name of ["search_products", "list_categories"]) {
    assert.equal(callTool(name, {}).isError, false, `${name} must work without a session`);
  }
  assert.equal(callTool("get_product", { product: "1" }).isError, false);
});

test("search filters by query, category, stock and price range", () => {
  assert.equal(
    callTool("search_products", { query: "grinder" }).structuredContent.products.length, 1);
  assert.equal(
    callTool("search_products", { category: "coffee" }).structuredContent.products.length, 1);
  assert.equal(
    callTool("search_products", { in_stock_only: true }).structuredContent.products.length, 2);
  assert.equal(
    callTool("search_products", { min_price_minor: 3000, max_price_minor: 5000 })
      .structuredContent.products.length, 1);
});

test("search paginates with an opaque cursor", () => {
  const first = callTool("search_products", { limit: 2 }).structuredContent;
  assert.equal(first.products.length, 2);
  assert.equal(first.next_cursor, "2");

  const second = callTool("search_products", { limit: 2, cursor: first.next_cursor }).structuredContent;
  assert.equal(second.products.length, 1);
  assert.equal(second.next_cursor, null, "last page must end the cursor chain");
});

test("get_product returns detail including variations", () => {
  const product = callTool("get_product", { product: "burr-grinder" }).structuredContent.product;
  assert.equal(product.id, 2);
  assert.equal(product.variations.length, 2);
  assert.equal(product.variations[0].price_minor, 6500);
  assert.equal(product.variations[1].in_stock, false);
});

test("resources are listed and readable, and unknown URIs are rejected", () => {
  const [list, templates, profile, unknown] = session([
    { jsonrpc: "2.0", id: 1, method: "resources/list" },
    { jsonrpc: "2.0", id: 2, method: "resources/templates/list" },
    { jsonrpc: "2.0", id: 3, method: "resources/read", params: { uri: "store://store/profile" } },
    { jsonrpc: "2.0", id: 4, method: "resources/read", params: { uri: "store://nope" } },
  ]);

  assert.equal(list.result.resources.length, 5);
  assert.equal(templates.result.resourceTemplates[0].uriTemplate, "store://session/{session_id}");
  assert.equal(JSON.parse(profile.result.contents[0].text).currency, "USD");
  assert.equal(unknown.error.code, -32602);
});

test("a session resource reads that session's cart, and an unknown one is not a server fault", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2 }, 2),
    { jsonrpc: "2.0", id: 3, method: "resources/read", params: { uri: `store://session/${SESSION}` } },
    { jsonrpc: "2.0", id: 4, method: "resources/read", params: { uri: "store://session/sess_999999" } },
  ]);

  const payload = JSON.parse(responses[1].result.contents[0].text);
  assert.equal(payload.session.id, SESSION);
  assert.equal(payload.cart.subtotal_minor, 6400);
  assert.equal(responses[2].error.code, -32602, "an unreadable session is an unknown URI");
});

// --- Cart lifecycle ----------------------------------------------------------

test("a cart can be built, amended and read back, with correct arithmetic", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2 }, 2),
    call("add_to_cart", { session_id: SESSION, product_id: 2, variation_id: 201, quantity: 1 }, 3),
    call("update_cart_item", { session_id: SESSION, item_key: "line_1_0", quantity: 3 }, 4),
    call("remove_cart_item", { session_id: SESSION, item_key: "line_2_201" }, 5),
    call("get_cart", { session_id: SESSION }, 6),
  ]);

  for (const res of responses) {
    assert.equal(res.result.isError, false, JSON.stringify(res.result.structuredContent));
  }

  // 2 x 3200
  assert.equal(responses[0].result.structuredContent.cart.subtotal_minor, 6400);
  // + 1 x 6500 (variation 201)
  assert.equal(responses[1].result.structuredContent.cart.subtotal_minor, 12900);
  // line 1 raised to 3 => 9600 + 6500
  assert.equal(responses[2].result.structuredContent.cart.subtotal_minor, 16100);
  // grinder removed => 3 x 3200
  assert.equal(responses[3].result.structuredContent.cart.subtotal_minor, 9600);

  const final = responses[4].result.structuredContent.cart;
  assert.equal(final.lines.length, 1);
  assert.equal(final.item_count, 3);
  assert.equal(final.subtotal_minor, 9600);
});

test("adding the same product twice merges into one line", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2 }, 3),
  ]);
  const cart = responses[1].result.structuredContent.cart;
  assert.equal(cart.lines.length, 1, "same product must not create a second line");
  assert.equal(cart.lines[0].quantity, 3);
});

test("clear_cart empties the basket but leaves the session open", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2 }, 2),
    call("clear_cart", { session_id: SESSION }, 3),
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 4),
  ]);

  assert.equal(responses[1].result.structuredContent.cart.item_count, 0);
  assert.equal(responses[1].result.structuredContent.cart.lines.length, 0);
  assert.equal(responses[2].result.isError, false, "the session must still be usable");
  assert.equal(responses[2].result.structuredContent.cart.item_count, 1);
});

test("the store refuses out-of-stock products, bad variations and over-stock quantities", () => {
  // Product 3 is published and purchasable but out of stock.
  let responses = withSession([call("add_to_cart", { session_id: SESSION, product_id: 3 }, 2)]);
  assert.equal(responses[0].result.structuredContent.error, "store_refused");

  // Variation 202 exists but is out of stock.
  responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 2, variation_id: 202 }, 2),
  ]);
  assert.equal(responses[0].result.structuredContent.error, "store_refused");

  // A variable product needs a variation.
  responses = withSession([call("add_to_cart", { session_id: SESSION, product_id: 2 }, 2)]);
  assert.equal(responses[0].result.structuredContent.error, "store_refused");

  // Product 2 has stock_quantity 8; ask for 9 (under the cap of 10, over stock).
  responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 2, variation_id: 201, quantity: 9 }, 2),
  ]);
  assert.equal(responses[0].result.isError, true);
  assert.equal(responses[0].result.structuredContent.error, "store_refused");
});

test("missing required arguments are rejected before the store is touched", () => {
  const result = callTool("get_cart", {});
  assert.equal(result.isError, true);
  assert.equal(result.structuredContent.error, "invalid_arguments");
});

// --- Safety ------------------------------------------------------------------

test("the per-item quantity cap is enforced", () => {
  const [res] = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 11 }, 2),
  ]);
  assert.equal(res.result.isError, true);
  assert.equal(res.result.structuredContent.error, "limit_exceeded");
});

test("the subtotal ceiling blocks the write rather than reporting it afterwards", () => {
  const responses = withSession(
    [
      call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
      call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 5 }, 3),
      call("get_cart", { session_id: SESSION }, 4),
    ],
    { config: { max_subtotal_minor: 10000 } },
  );

  assert.equal(responses[1].result.isError, true);
  assert.equal(responses[1].result.structuredContent.error, "limit_exceeded");
  // The rejected add must have been rolled back, not left half-applied.
  assert.equal(responses[2].result.structuredContent.cart.subtotal_minor, 3200);
  assert.equal(responses[2].result.structuredContent.cart.lines[0].quantity, 1);
});

test("the distinct-line cap is enforced", () => {
  const responses = withSession(
    [
      call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
      call("add_to_cart", { session_id: SESSION, product_id: 2, variation_id: 201, quantity: 1 }, 3),
    ],
    { config: { max_lines: 1 } },
  );
  assert.equal(responses[1].result.isError, true);
  assert.equal(responses[1].result.structuredContent.error, "limit_exceeded");
});

test("a disabled cart tier refuses every mutating tool but leaves reads and sessions working", () => {
  const config = { cart_enabled: false };

  for (const [name, args] of [
    ["add_to_cart", { session_id: SESSION, product_id: 1 }],
    ["update_cart_item", { session_id: SESSION, item_key: "line_1_0", quantity: 1 }],
    ["remove_cart_item", { session_id: SESSION, item_key: "line_1_0" }],
    ["clear_cart", { session_id: SESSION }],
  ]) {
    const result = callTool(name, args, { config });
    assert.equal(result.isError, true, `${name} must be refused`);
    assert.equal(result.structuredContent.error, "capability_disabled");
  }

  assert.equal(callTool("search_products", {}, { config }).isError, false,
    "reads must survive a disabled cart tier");
  assert.equal(callTool("create_session", {}, { config }).isError, false,
    "sessions must survive a disabled cart tier");
});

test("a disabled checkout tier refuses the handoff", () => {
  const [res] = withSession([call("create_checkout_handoff", { session_id: SESSION }, 2)], {
    config: { checkout_enabled: false },
  });
  assert.equal(res.result.isError, true);
  assert.equal(res.result.structuredContent.error, "capability_disabled");
});

test("a retried call with the same idempotency key does not apply twice", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2, idempotency_key: "abc" }, 2),
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2, idempotency_key: "abc" }, 3),
    call("get_cart", { session_id: SESSION }, 4),
  ]);

  assert.equal(responses[2].result.structuredContent.cart.item_count, 2,
    "the replayed call must not have doubled the quantity");
  assert.deepEqual(
    responses[0].result.structuredContent,
    responses[1].result.structuredContent,
    "a replay must return the original result");
});

test("a retried create_session does not strand a second basket", () => {
  const responses = session([
    call("create_session", { idempotency_key: "start" }, 1),
    call("create_session", { idempotency_key: "start" }, 2),
  ]);
  assert.equal(responses[0].result.structuredContent.session_id, SESSION);
  assert.equal(responses[1].result.structuredContent.session_id, SESSION,
    "the replay must return the first session, not open another");
});

test("dry_run validates without changing the cart", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 5, dry_run: true }, 3),
    call("get_cart", { session_id: SESSION }, 4),
  ]);

  assert.equal(responses[1].result.isError, false);
  assert.equal(responses[1].result.structuredContent.dry_run, true);
  assert.equal(responses[1].result.structuredContent.would_be_cart.item_count, 6,
    "the simulation must show the would-be state");
  assert.equal(responses[2].result.structuredContent.cart.item_count, 1,
    "the real cart must be untouched");
});

test("the rate limiter stops a runaway agent", () => {
  const requests = [];
  for (let i = 2; i <= 5; i++) {
    requests.push(call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, i));
  }
  // The limiter buckets by session_id, and create_session has none yet, so it
  // is counted anonymously and does not spend this session's budget.
  const responses = withSession(requests, {
    config: { rate_limit_calls: 3, rate_limit_window_sec: 60 },
    clock: 1758240000,
  });

  assert.equal(responses[2].result.isError, false, "the third add is still inside the limit");
  assert.equal(responses[3].result.isError, true, "the fourth must be rate limited");
  assert.equal(responses[3].result.structuredContent.error, "rate_limited");
});

// --- Checkout handoff --------------------------------------------------------

test("the checkout handoff returns a single-use URL for a human and places nothing", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("create_checkout_handoff", { session_id: SESSION }, 3),
  ]);

  const payload = responses[1].result.structuredContent;
  assert.equal(responses[1].result.isError, false);
  assert.match(payload.checkout_url, /^https:\/\//);
  assert.match(payload.checkout_url, /urumiai_claim=/, "the URL must carry the claim");
  assert.equal(payload.requires_human, true);
  assert.match(payload.notice, /no order has been placed/i);
  assert.ok(payload.expires_at > 0, "a handoff must expire");
  assert.equal(payload.session.state, "handed_off");
  assert.equal(payload.order_id, undefined, "a handoff must never carry an order id");
  assert.equal(payload.cart.subtotal_minor, 3200);
});

test("an empty cart cannot be handed off", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("remove_cart_item", { session_id: SESSION, item_key: "line_1_0" }, 3),
    call("create_checkout_handoff", { session_id: SESSION }, 4),
  ]);
  assert.equal(responses[2].result.isError, true);
  assert.equal(responses[2].result.structuredContent.error, "empty_cart");
});

test("opening the link hands the shopper the basket the agent built", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 2 }, 2),
    call("add_to_cart", { session_id: SESSION, product_id: 2, variation_id: 201, quantity: 1 }, 3),
    call("create_checkout_handoff", { session_id: SESSION }, 4),
    { __control: "redeem_claim", token: CLAIM },
  ]);

  const redeemed = responses[3];
  assert.equal(redeemed.ok, true, JSON.stringify(redeemed));
  assert.equal(redeemed.session.state, "claimed");
  assert.equal(redeemed.cart.item_count, 3);
  assert.equal(redeemed.cart.subtotal_minor, 12900,
    "the shopper must land on exactly the basket that was handed off");
});

test("a checkout link works once", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("create_checkout_handoff", { session_id: SESSION }, 3),
    { __control: "redeem_claim", token: CLAIM },
    { __control: "redeem_claim", token: CLAIM },
  ]);

  assert.equal(responses[2].ok, true);
  assert.equal(responses[3].ok, false, "a replayed link must be refused");
  assert.equal(responses[3].error, "claim_already_used");
});

test("a checkout link expires", () => {
  const responses = withSession(
    [
      call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
      call("create_checkout_handoff", { session_id: SESSION }, 3),
      { __control: "redeem_claim", token: CLAIM },
    ],
    { config: { handoff_ttl_sec: 10 }, clock: 1758240000, advance: 20 },
  );

  assert.equal(responses[2].ok, false);
  assert.equal(responses[2].error, "claim_expired");
});

test("an unknown or unredeemed link is refused, and a bogus token is not a crash", () => {
  const responses = session([
    { __control: "redeem_claim", token: "claim_999999" },
    call("get_handoff_status", { handoff_token: "claim_999999" }, 1),
  ]);
  assert.equal(responses[0].ok, false);
  assert.equal(responses[0].error, "claim_not_found");
  assert.equal(responses[1].result.isError, true);
  assert.equal(responses[1].result.structuredContent.error, "not_found");
});

test("once a person opens the link the agent can no longer change the basket", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("create_checkout_handoff", { session_id: SESSION }, 3),
    { __control: "redeem_claim", token: CLAIM },
    call("add_to_cart", { session_id: SESSION, product_id: 2, variation_id: 201 }, 4),
    call("update_cart_item", { session_id: SESSION, item_key: "line_1_0", quantity: 9 }, 5),
    call("remove_cart_item", { session_id: SESSION, item_key: "line_1_0" }, 6),
    call("clear_cart", { session_id: SESSION }, 7),
    call("get_cart", { session_id: SESSION }, 8),
  ]);

  for (const res of responses.slice(3, 7)) {
    assert.equal(res.result.isError, true, "a claimed basket must be frozen to the agent");
    assert.equal(res.result.structuredContent.error, "session_claimed");
  }

  assert.equal(responses[7].result.isError, false, "reading it is still fine");
  assert.equal(responses[7].result.structuredContent.cart.item_count, 1,
    "the basket the shopper is paying for must be untouched");
});

test("handoff status reports pending, then claimed, and never leaks an order", () => {
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("create_checkout_handoff", { session_id: SESSION }, 3),
    call("get_handoff_status", { handoff_token: CLAIM }, 4),
    { __control: "redeem_claim", token: CLAIM },
    call("get_handoff_status", { handoff_token: CLAIM }, 5),
  ]);

  const pending = responses[2].result.structuredContent.handoff;
  assert.equal(pending.state, "pending");
  assert.equal(pending.claimed, false);
  assert.equal(pending.claimed_at, null);

  const claimed = responses[4].result.structuredContent.handoff;
  assert.equal(claimed.state, "claimed");
  assert.equal(claimed.claimed, true);
  assert.ok(claimed.claimed_at > 0);

  const keys = allKeys(claimed);
  for (const forbidden of ["order_id", "order", "total", "payment", "customer"]) {
    assert.ok(!keys.has(forbidden), `handoff status must not carry "${forbidden}"`);
  }
});

// --- The structural no-purchase claim ---------------------------------------

test("no tool can place an order, take payment, refund or issue a coupon", () => {
  const [list] = session([{ jsonrpc: "2.0", id: 1, method: "tools/list" }]);
  const names = list.result.tools.map((tool) => tool.name);

  assert.deepEqual(names.slice().sort(), [
    "add_to_cart", "clear_cart", "create_checkout_handoff", "create_session",
    "end_session", "get_cart", "get_handoff_status", "get_product",
    "get_session", "list_categories", "remove_cart_item", "search_products",
    "update_cart_item",
  ], "the tool list must be exactly these thirteen");

  const forbidden = /order|pay|payment|charge|refund|coupon|discount|invoice/i;
  for (const name of names) {
    // The two checkout tools are named below and verified elsewhere: one
    // returns a URL, the other a boolean.
    if (name === "create_checkout_handoff" || name === "get_handoff_status") continue;
    assert.ok(!forbidden.test(name), `tool "${name}" looks purchase-shaped`);
  }

  // And the source carries no handler for one, at any tier.
  const sources = fs.readdirSync(INCLUDES).map((f) => fs.readFileSync(path.join(INCLUDES, f), "utf8"));
  for (const source of sources) {
    assert.ok(!/function\s+place_order/.test(source), "no place_order handler may exist");
    assert.ok(!/function\s+capture_payment/.test(source), "no capture_payment handler may exist");
    assert.ok(!/wc_create_order|->payment_complete\(/.test(source),
      "no WooCommerce order-creation or payment-completion call may exist");
  }
});

test("there is no order tier, and an invented one is denied", () => {
  const policy = fs.readFileSync(path.join(INCLUDES, "class-safety-policy.php"), "utf8");
  assert.ok(!/TIER_ORDER|TIER_PAYMENT/.test(policy), "no order or payment tier may be defined");
  assert.match(policy, /return false;/, "an unknown tier must fall through to denied");

  const result = callTool("place_order", { session_id: SESSION });
  assert.equal(result.isError, true);
  assert.equal(result.structuredContent.error, "unknown_tool");
});

// --- Privacy -----------------------------------------------------------------

test("customer-shaped fields never reach an agent", () => {
  // The fixture deliberately carries customer_email on product 1 and
  // admin_email on the store profile. Both must be stripped.
  const responses = session([
    call("search_products", {}, 1),
    call("get_product", { product: "1" }, 2),
    { jsonrpc: "2.0", id: 3, method: "resources/read", params: { uri: "store://store/profile" } },
    { jsonrpc: "2.0", id: 4, method: "resources/read", params: { uri: "store://catalog/products" } },
  ]);

  const blob = JSON.stringify(responses);
  for (const leak of ["customer_email", "admin_email", "buyer@example.com", "owner@example.com"]) {
    assert.ok(!blob.includes(leak), `"${leak}" must never appear in an agent-facing response`);
  }

  const keys = allKeys(responses);
  for (const forbidden of ["email", "phone", "billing", "shipping_address", "meta_data", "user_id"]) {
    assert.ok(!keys.has(forbidden), `key "${forbidden}" must not be serialized`);
  }
});

test("the internal cart token never reaches an agent", () => {
  // The agent holds a session_id this plugin issued. The adapter's cart handle
  // is the store's business and stays on the server.
  const responses = withSession([
    call("add_to_cart", { session_id: SESSION, product_id: 1, quantity: 1 }, 2),
    call("get_session", { session_id: SESSION }, 3),
    call("get_cart", { session_id: SESSION }, 4),
    call("create_checkout_handoff", { session_id: SESSION }, 5),
    { jsonrpc: "2.0", id: 6, method: "resources/read", params: { uri: `store://session/${SESSION}` } },
  ]);

  const blob = JSON.stringify(responses);
  assert.ok(!blob.includes("cart_000001"), "the fixture cart token must not be serialized");
  assert.ok(!blob.includes("cart_token"), "no response may carry a cart_token field");
});

test("the serializer is an allowlist, so an unknown field cannot pass through", () => {
  const source = fs.readFileSync(path.join(INCLUDES, "class-serializer.php"), "utf8");
  assert.match(source, /array_key_exists\(\$field, \$source\)/,
    "pick() must copy only allowlisted keys");
  assert.ok(!/unset\(/.test(source), "a denylist (unset) would be the wrong shape here");
});

// --- Structure and schema ----------------------------------------------------

test("the plugin follows the loader + sibling-directory convention", () => {
  assert.ok(fs.existsSync(LOADER));
  assert.ok(fs.statSync(PLUGIN_DIR).isDirectory());
  assert.ok(fs.existsSync(path.join(PLUGIN_DIR, "README.md")));
  assert.ok(fs.existsSync(SCHEMA));
  assert.ok(!fs.existsSync(path.join(PLUGIN_DIR, "tests")),
    "tests must live outside the plugin directory, which a deploy tars wholesale");
});

test("loader declares a complete WordPress plugin header", () => {
  const src = fs.readFileSync(LOADER, "utf8");
  for (const field of [
    "Plugin Name: UrumiAI Store for AI",
    "Plugin URI:",
    "Description:",
    "Author:",
    "Version:",
    "Requires at least:",
    "Requires PHP:",
  ]) {
    assert.ok(src.includes(field), `plugin header is missing "${field}"`);
  }
});

test("every PHP file guards against direct access and parses", () => {
  const files = [LOADER, ...fs.readdirSync(INCLUDES).map((f) => path.join(INCLUDES, f))];
  for (const file of files) {
    const source = fs.readFileSync(file, "utf8");
    assert.match(source, /if \(!defined\('ABSPATH'\)\) \{\s*\n\s*exit;/,
      `${path.basename(file)} needs an ABSPATH guard`);

    const res = spawnSync(PHP_BIN, ["-l", file], { encoding: "utf8" });
    assert.equal(res.status, 0, `php -l failed for ${path.basename(file)}:\n${res.stdout}`);

    assert.ok(fs.statSync(file).size > 0, `${path.basename(file)} is empty`);
  }
});

test("the published schema matches the implementation exactly", () => {
  const published = JSON.parse(fs.readFileSync(SCHEMA, "utf8"));
  const [live] = session([{ jsonrpc: "2.0", id: 1, method: "tools/list" }]);
  assert.deepEqual(published.tools, live.result.tools,
    "schema/tools.json has drifted — regenerate with php tests/php/generate-schema.php");
});

test("the published schema leaks no private definition keys", () => {
  const published = JSON.parse(fs.readFileSync(SCHEMA, "utf8"));
  const keys = allKeys(published.tools);
  for (const internal of ["_tier", "_session", "_mutating"]) {
    assert.ok(!keys.has(internal), `"${internal}" is internal and must be stripped from tools/list`);
  }
});

test("both adapters implement the contract without inventing methods", () => {
  // The WooCommerce adapter's behavior cannot be verified here (no
  // WooCommerce), but its shape can.
  const contract = fs.readFileSync(path.join(INCLUDES, "class-adapter.php"), "utf8");
  const woo = fs.readFileSync(path.join(INCLUDES, "class-woocommerce-adapter.php"), "utf8");
  const fixture = fs.readFileSync(path.join(INCLUDES, "class-fixture-adapter.php"), "utf8");

  const required = [...contract.matchAll(/public function (\w+)\(/g)].map((m) => m[1]);
  assert.ok(required.length >= 13, "the contract should declare at least 13 methods");

  for (const method of required) {
    assert.ok(woo.includes(`function ${method}(`), `WooCommerce adapter is missing ${method}()`);
    assert.ok(fixture.includes(`function ${method}(`), `fixture adapter is missing ${method}()`);
  }

  for (const source of [woo, fixture]) {
    assert.match(source, /implements Urumiai_Store_For_Ai_Adapter/);
  }
});

test("both session stores implement the session-store contract", () => {
  const contract = fs.readFileSync(path.join(INCLUDES, "class-session-store.php"), "utf8");
  const memory = fs.readFileSync(path.join(INCLUDES, "class-memory-session-store.php"), "utf8");
  const transient = fs.readFileSync(path.join(INCLUDES, "class-transient-session-store.php"), "utf8");

  const required = [...contract.matchAll(/public function (\w+)\(/g)].map((m) => m[1]);
  assert.ok(required.length >= 7, "the contract should declare at least 7 methods");

  for (const method of required) {
    assert.ok(memory.includes(`function ${method}(`), `memory store is missing ${method}()`);
    assert.ok(transient.includes(`function ${method}(`), `transient store is missing ${method}()`);
  }

  // The production store must not hand out guessable handles.
  assert.match(transient, /wp_generate_password/,
    "the transient store must generate random handles");
});

test("the core is framework-agnostic: WordPress appears only in the binding files", () => {
  // This is what makes the suite able to test the real thing. If a WordPress
  // call creeps into the core, the CLI harness would need a bigger shim and the
  // coverage claim would quietly weaken.
  const wordpressOnly = new Set([
    "class-woocommerce-adapter.php",
    "class-transient-session-store.php",
    "class-rest-controller.php",
    "class-checkout-handoff.php",
  ]);

  const wpApi = /\b(add_action|add_filter|apply_filters|do_action|get_option|set_transient|get_transient|wp_generate_password|register_rest_route|home_url|wc_get_\w+|WC\(\))/;

  for (const file of fs.readdirSync(INCLUDES)) {
    if (wordpressOnly.has(file)) continue;
    const source = fs.readFileSync(path.join(INCLUDES, file), "utf8");
    // Strip comments before looking: docblocks legitimately name these.
    const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    assert.ok(!wpApi.test(code), `${file} calls a WordPress API; it belongs in the core`);
  }
});

test("version is identical in the header, the constant and the README", () => {
  const loader = fs.readFileSync(LOADER, "utf8");
  const header = loader.match(/^\s*\*\s*Version:\s*(\S+)/m);
  const constant = loader.match(/define\('URUMIAI_STORE_FOR_AI_VERSION',\s*'([^']+)'\)/);
  const readme = fs.readFileSync(path.join(PLUGIN_DIR, "README.md"), "utf8")
    .match(/^\*\*Version:\*\*\s*(\S+)/m);

  assert.equal(header[1], EXPECTED_VERSION);
  assert.equal(constant[1], EXPECTED_VERSION);
  assert.equal(readme[1], EXPECTED_VERSION);
});
