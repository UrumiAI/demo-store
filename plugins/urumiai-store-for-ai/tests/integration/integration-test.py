#!/usr/bin/env python3
"""
Integration test: UrumiAI Store for AI against a REAL WordPress + WooCommerce.

This exercises exactly the four files the unit suite could only shape-check:
  class-rest-controller.php      route + bearer auth
  class-woocommerce-adapter.php  live catalog, WC session carts, stock rules
  class-transient-session-store.php  sessions surviving across HTTP requests
  class-checkout-handoff.php     the claim link, in a real browser session

The agent talks JSON-RPC over HTTP. The "shopper" is a separate cookie jar,
exactly as a real browser would be.
"""

import json
import sys
import urllib.request
import urllib.error
import http.cookiejar

BASE = __import__("os").environ.get("BASE_URL", "http://127.0.0.1:9400")
RPC = BASE + "/?rest_route=/urumiai-store-for-ai/v1/rpc"
TOKEN = "test-secret-token"

passed, failed = [], []


def check(name, condition, detail=""):
    if condition:
        passed.append(name)
        print(f"  \033[32mPASS\033[0m {name}")
    else:
        failed.append((name, detail))
        print(f"  \033[31mFAIL\033[0m {name}  {detail}")


def rpc(method, params=None, token=TOKEN, rid=1):
    body = {"jsonrpc": "2.0", "id": rid, "method": method}
    if params is not None:
        body["params"] = params
    req = urllib.request.Request(
        RPC, data=json.dumps(body).encode(), method="POST",
        headers={"Content-Type": "application/json",
                 **({"Authorization": f"Bearer {token}"} if token else {})},
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, {"raw": raw[:400]}


def tool(name, args=None, token=TOKEN):
    status, res = rpc("tools/call", {"name": name, "arguments": args or {}}, token=token)
    if "result" not in res:
        return status, res, None
    return status, res, res["result"].get("structuredContent")


print("\n=== 1. Transport, route and authentication ===")
status, res = rpc("tools/list")
check("authenticated tools/list returns 200", status == 200, f"got {status}")
tools = [t["name"] for t in res.get("result", {}).get("tools", [])]
check("all 13 tools are exposed over REST", len(tools) == 13, f"got {len(tools)}: {tools}")

status, _ = rpc("tools/list", token=None)
check("no bearer token is rejected 401", status == 401, f"got {status}")
status, _ = rpc("tools/list", token="wrong-token")
check("wrong bearer token is rejected 401", status == 401, f"got {status}")

status, res = rpc("initialize")
info = res.get("result", {}).get("serverInfo", {})
check("initialize identifies the server", info.get("name") == "urumiai-store-for-ai",
      str(info))

print("\n=== 2. Live WooCommerce catalog ===")
_, _, sc = tool("search_products", {})
products = (sc or {}).get("products", [])
names = [p["name"] for p in products]
check("search returns the seeded published products", len(products) == 3,
      f"got {len(products)}: {names}")
check("the DRAFT product is invisible to the agent",
      "Unreleased Roaster" not in names, f"leaked: {names}")

pour = next((p for p in products if p["name"] == "Ceramic Pour-Over Kit"), None)
check("sale price is read from WooCommerce in integer minor units",
      pour is not None and pour["price_minor"] == 3200 and pour["regular_price_minor"] == 4000,
      json.dumps(pour) if pour else "product missing")
check("currency comes from the store, not a constant",
      pour is not None and pour["currency"] == "USD", str(pour and pour.get("currency")))

_, _, sc = tool("search_products", {"in_stock_only": True})
check("in_stock_only filters out the out-of-stock product",
      len(sc.get("products", [])) == 2, f"got {len(sc.get('products', []))}")

print("\n=== 3. Session + cart against real WooCommerce sessions ===")
_, _, sc = tool("create_session", {})
session_id = (sc or {}).get("session_id")
check("create_session returns a handle", bool(session_id), json.dumps(sc)[:200])
check("the session handle is random, not the fixture counter",
      bool(session_id) and session_id != "sess_000001" and len(session_id) > 12,
      str(session_id))
check("the WooCommerce cart token never reaches the agent",
      "cart_token" not in json.dumps(sc), "cart_token leaked in create_session")

grinder = next((p for p in products if p["name"] == "Hand Burr Grinder"), None)
_, _, sc = tool("add_to_cart", {"session_id": session_id, "product_id": pour["id"], "quantity": 2})
check("add_to_cart writes to a real WooCommerce cart",
      sc and sc.get("cart", {}).get("subtotal_minor") == 6400,
      json.dumps(sc)[:300])

_, _, sc = tool("add_to_cart", {"session_id": session_id, "product_id": grinder["id"], "quantity": 1})
check("a second line totals correctly (2x3200 + 1x6500)",
      sc and sc["cart"]["subtotal_minor"] == 12900, json.dumps(sc.get("cart"))[:300])

_, _, sc = tool("get_cart", {"session_id": session_id})
check("the cart survives across separate HTTP requests (transient store)",
      sc and sc["cart"]["item_count"] == 3, json.dumps(sc)[:300])

espresso = next((p for p in products if p["name"] == "Espresso Blend 1kg"), None)
_, _, sc = tool("add_to_cart", {"session_id": session_id, "product_id": espresso["id"]})
check("WooCommerce refuses an out-of-stock product",
      sc and sc.get("error") == "store_refused", json.dumps(sc)[:300])

_, _, sc = tool("add_to_cart", {"session_id": session_id, "product_id": grinder["id"], "quantity": 9})
check("WooCommerce refuses more than the 8 units actually in stock",
      sc and sc.get("error") == "store_refused", json.dumps(sc)[:300])

_, _, sc = tool("add_to_cart", {"session_id": session_id, "product_id": pour["id"], "quantity": 11})
check("the per-item cap of 10 is enforced before WooCommerce sees it",
      sc and sc.get("error") == "limit_exceeded", json.dumps(sc)[:300])

_, _, sc = tool("get_cart", {"session_id": session_id})
check("a refused add left the real cart unchanged",
      sc and sc["cart"]["subtotal_minor"] == 12900, json.dumps(sc.get("cart"))[:200])

print("\n=== 4. The checkout handoff, in a real browser session ===")
_, _, sc = tool("create_checkout_handoff", {"session_id": session_id})
claim_url = (sc or {}).get("checkout_url")
handoff_token = (sc or {}).get("handoff_token")
check("handoff returns a claim URL on the real storefront",
      bool(claim_url) and "urumiai_claim=" in claim_url, str(claim_url))
check("handoff says a human is required", sc and sc.get("requires_human") is True, str(sc))
check("handoff carries no order id", sc and "order_id" not in sc, json.dumps(sc)[:200])

_, _, sc = tool("get_handoff_status", {"handoff_token": handoff_token})
check("status before opening is pending",
      sc and sc["handoff"]["state"] == "pending", json.dumps(sc)[:200])

# The shopper: a brand-new cookie jar that has never seen the agent's session.
jar = http.cookiejar.CookieJar()
browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
browser.addheaders = [("User-Agent", "Mozilla/5.0 (integration-test shopper)")]

try:
    with browser.open(claim_url, timeout=180) as r:
        landed_url, landed_html = r.geturl(), r.read().decode(errors="replace")
    opened_ok = True
except Exception as e:  # noqa: BLE001
    landed_url, landed_html, opened_ok = "", "", False
    check("shopper can open the claim URL", False, repr(e))

if opened_ok:
    check("opening the claim redirects the shopper to checkout",
          "checkout" in landed_url.lower(), landed_url)
    check("the shopper's browser now holds a WooCommerce session cookie",
          any("woocommerce" in c.name or "wp_woocommerce" in c.name for c in jar),
          str([c.name for c in jar]))
    # WooCommerce 10.x renders cart/checkout as blocks hydrated from the Store
    # API, so the server HTML carries no product names. Ask the Store API what
    # is actually in THIS browser's cart — that is what the shopper will pay for.
    def store_cart(opener):
        try:
            with opener.open(BASE + "/?rest_route=/wc/store/v1/cart", timeout=120) as r:
                return json.loads(r.read().decode())
        except Exception as e:  # noqa: BLE001
            return {"_error": repr(e)}

    cart = store_cart(browser)
    items = {i["name"]: i["quantity"] for i in cart.get("items", [])}
    check("the agent's basket is now in the shopper's own WooCommerce cart",
          items.get("Ceramic Pour-Over Kit") == 2 and items.get("Hand Burr Grinder") == 1,
          json.dumps(items) or cart.get("_error", ""))
    check("the shopper's cart totals $129.00",
          cart.get("totals", {}).get("total_items") == "12900",
          json.dumps(cart.get("totals", {}))[:200])
    check("no order was created by the handoff",
          "order-received" not in landed_url and "thank" not in landed_url.lower(),
          landed_url)

_, _, sc = tool("get_handoff_status", {"handoff_token": handoff_token})
check("status after opening is claimed",
      sc and sc["handoff"]["state"] == "claimed", json.dumps(sc)[:200])

print("\n=== 5. The guardrails, live ===")
jar2 = http.cookiejar.CookieJar()
browser2 = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar2))
try:
    with browser2.open(claim_url, timeout=120) as r:
        replay_html = r.read().decode(errors="replace")
        replay_url = r.geturl()
except Exception as e:  # noqa: BLE001
    replay_html, replay_url = repr(e), ""
replay_cart = store_cart(browser2) if opened_ok else {"items": []}
check("a replayed claim link leaves a second browser's cart empty",
      len(replay_cart.get("items", [])) == 0,
      f"second shopper got {[i['name'] for i in replay_cart.get('items', [])]} at {replay_url}")
check("a replayed claim link sends that person to the cart page with a notice",
      "/cart" in replay_url, replay_url)

_, _, sc = tool("add_to_cart", {"session_id": session_id, "product_id": grinder["id"]})
check("the agent cannot change a basket the shopper is paying for",
      sc and sc.get("error") == "session_claimed", json.dumps(sc)[:250])

_, _, sc = tool("get_cart", {"session_id": session_id})
check("but the agent can still read it",
      sc and sc.get("cart", {}).get("subtotal_minor") == 12900, json.dumps(sc)[:200])

_, _, sc = tool("end_session", {"session_id": session_id})
check("end_session closes the session", sc and sc.get("closed") is True, json.dumps(sc)[:200])
_, _, sc = tool("get_cart", {"session_id": session_id})
check("a closed session is gone", sc and sc.get("error") == "session_not_found",
      json.dumps(sc)[:200])

print("\n" + "=" * 62)
print(f"  {len(passed)} passed, {len(failed)} failed")
if failed:
    print("\n  Failures:")
    for name, detail in failed:
        print(f"    - {name}\n      {detail}")
print("=" * 62 + "\n")
sys.exit(1 if failed else 0)
