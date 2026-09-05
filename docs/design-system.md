# Demo Store — Design System

Living reference for the cosmetic storefront built on `demo-store-headless`.
Tokens here map 1:1 to CSS custom properties in `src/styles/tokens.css`
(forthcoming). When a value changes, change it in both places.

> **Scope:** specific to this demo storefront. Do **not** port any of these
> visual tokens into `base-headless` — that repo intentionally ships with
> a thin neutral baseline so each derived theme can impose its own identity.

---

## 1. Brand foundation

### Direction
Aesop-style minimalist apothecary. Clean cream surfaces, classical serif
headlines, ingredient-forward voice, near-zero motion, near-zero radius.
The product is the hero; the chrome recedes.

### Brand-name candidates
Pick one for the live demo (sets logo wordmark + voice). All single words,
apothecary-coded, available as `.com` / Shopify-style handles in concept:

| Name        | Why it fits                                                                                  |
|-------------|----------------------------------------------------------------------------------------------|
| **Salve**   | Latin "to heal." Single syllable, instantly apothecary, easy to set in display serif.        |
| **Maren**   | Latin/Nordic "of the sea." Soft, feminine, plant-adjacent. Pairs well with sage green.       |
| **Verbena** | Botanical, recognizable, slightly more decorative — leans floral.                             |
| **Tare**    | Botanical (a wild legume) + balance term. Brutalist-leaning, distinctive, four letters.     |
| **Tincture**| Apothecary craft term. Longer wordmark, sets a herbal/handmade tone.                          |

**Working default:** `Salve` — shortest, most pronounceable, sets a
clinical-but-warm tone. Easy to swap via `wpData.siteName` later.

### Voice
- **Precise** — "12% niacinamide" beats "powerful brightening complex"
- **Lowercase, sentence case** for body and labels — never SHOUTY-CAPS
- **Tracked all-caps** reserved for navigation, buttons, and section eyebrows
- **No exclamation points.** No emoji. No "girl, ✨ this serum slaps."
- Single-clause product names where possible: "Resurfacing Serum,"
  "Geranium Hand Wash," "Volume Two Cleanser"

### Tagline candidates
- "Considered formulations."
- "Daily care, well kept."
- "Made for the routine, not the ritual." *(slightly contrarian — recommended)*

---

## 2. Design principles

1. **The product is the hero.** Composition serves photography. UI chrome
   never competes with the bottle on the shelf.
2. **Whitespace is content.** Generous margin and air around every element.
   When in doubt, double the gutter.
3. **Type carries the brand.** Two faces, one role each. Decorative type
   is the only ornament.
4. **Material restraint.** No gradients, no shadows, no rounded corners
   beyond 2px. Borders are 1px hairlines.
5. **Motion as punctuation.** Transitions exist to confirm intent
   (hover, focus, page change). Never to entertain. ≤ 250ms.
6. **Mobile-first, but typographic-first within that.** Minimum body 15px,
   line-length capped at 60–70 characters.

---

## 3. Color tokens

Warm cream foundation, deep warm-charcoal text, single sage accent. Avoid
saturated color anywhere except sale and validation states.

### Surface
| Token                    | Value     | Use                                              |
|--------------------------|-----------|--------------------------------------------------|
| `--color-bg-primary`     | `#FBF8F3` | Page background — warm off-white, the "paper"   |
| `--color-bg-elevated`    | `#FFFFFF` | Cards, modals, surfaces lifted above the page    |
| `--color-bg-subtle`      | `#F4EFE7` | Section blocks, alternate strips, footer        |
| `--color-bg-inverse`     | `#1A1612` | Inverted sections (rare — campaign blocks)      |

### Text
| Token                    | Value     | Use                                              |
|--------------------------|-----------|--------------------------------------------------|
| `--color-text-primary`   | `#1A1612` | Body and headlines on light surfaces             |
| `--color-text-secondary` | `#6B635A` | Captions, meta, helper copy                      |
| `--color-text-muted`     | `#9A9088` | Placeholders, disabled state                     |
| `--color-text-inverse`   | `#FBF8F3` | Text on dark surfaces                            |
| `--color-text-link`      | `#1A1612` | Links — body weight, underlined, no color shift  |

### Accent (use sparingly)
| Token                    | Value     | Use                                              |
|--------------------------|-----------|--------------------------------------------------|
| `--color-accent`         | `#5F6F52` | Sage — primary buttons, focus rings, badges      |
| `--color-accent-hover`   | `#4C5A41` | Darker sage on hover                             |
| `--color-accent-subtle`  | `#E8EBE2` | Sage tint background — selected chips, info     |

### Border / divider
| Token                    | Value     | Use                                              |
|--------------------------|-----------|--------------------------------------------------|
| `--color-border-subtle`  | `#E8E2D8` | Default 1px hairline                             |
| `--color-border-strong`  | `#1A1612` | Keylines, active states, button outlines         |

### Status
| Token                    | Value     | Use                                              |
|--------------------------|-----------|--------------------------------------------------|
| `--color-sale`           | `#A65D4F` | Sale price, urgent flag — terracotta             |
| `--color-success`        | `#5C6B4F` | Form success, in-stock — muted forest            |
| `--color-warning`        | `#B8924A` | Low stock, advisories — antique brass            |
| `--color-error`          | `#8B3A2F` | Form error, destructive — deep burnt umber       |

---

## 4. Typography tokens

Two faces, no exceptions:

### Faces
| Token                    | Family                         | Source        | Notes                                |
|--------------------------|--------------------------------|---------------|--------------------------------------|
| `--font-display`         | EB Garamond                    | Google Fonts  | Classical serif, free, broad weights |
| `--font-body`            | Inter                          | Google Fonts  | Neutral sans-serif, free             |
| `--font-mono`            | JetBrains Mono *(optional)*    | Google Fonts  | SKU, lot numbers, batch labels only  |

Fallback stacks:
```css
--font-display: 'EB Garamond', Georgia, 'Times New Roman', serif;
--font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
```

### Type scale (modular, base 16px, ratio ~1.22)
| Token              | Size / line-height | Family   | Weight | Tracking | Use                              |
|--------------------|--------------------|----------|--------|----------|----------------------------------|
| `--type-display`   | 48px / 52px        | display  | 400    | -0.02em  | Homepage hero, campaign blocks   |
| `--type-h1`        | 36px / 42px        | display  | 400    | -0.01em  | Page titles                      |
| `--type-h2`        | 28px / 36px        | display  | 400    | -0.01em  | Section headers                  |
| `--type-h3`        | 22px / 30px        | display  | 500    | 0        | Sub-sections, product titles     |
| `--type-h4`        | 18px / 26px        | body     | 500    | 0        | Card titles                      |
| `--type-body-lg`   | 17px / 28px        | body     | 400    | 0        | Long-form description, PDP body  |
| `--type-body`      | 15px / 24px        | body     | 400    | 0        | Default body                     |
| `--type-body-sm`   | 13px / 20px        | body     | 400    | 0        | Meta, captions                   |
| `--type-eyebrow`   | 11px / 16px        | body     | 600    | 0.16em   | Section eyebrows, all-caps       |
| `--type-label`     | 12px / 16px        | body     | 600    | 0.12em   | Buttons, form labels, all-caps   |
| `--type-caption`   | 11px / 16px        | body     | 400    | 0.04em   | Image credits, fine print        |

Display sizes use **tight leading** (line-height ≈ size × 1.05–1.15);
body sizes use **comfortable leading** (line-height ≈ size × 1.5–1.6).

### Headline rules
- Display + h1 set in display serif, near-black weight 400, slightly tight.
- h2 + h3 in display serif when possible; switch to body sans if the h3
  is mid-paragraph or in a UI shelf.
- Body is **never** display serif.

---

## 5. Spacing scale

8px base, geometric. Use the token, not the raw value.

| Token         | Value    | Use                                              |
|---------------|----------|--------------------------------------------------|
| `--space-0`   | 0        |                                                  |
| `--space-1`   | 4px      | Icon ↔ label, chip padding-y                     |
| `--space-2`   | 8px      | Tight stack, inline gap                          |
| `--space-3`   | 12px     | Form field padding                               |
| `--space-4`   | 16px     | Default container padding                        |
| `--space-5`   | 24px     | Card padding, paragraph stack                    |
| `--space-6`   | 32px     | Section internal padding                         |
| `--space-7`   | 48px     | Section vertical rhythm                          |
| `--space-8`   | 64px     | Major block separator                            |
| `--space-9`   | 96px     | Hero margin, page sections                       |
| `--space-10`  | 128px    | Reserved — campaign blocks, breathing room       |

Vertical rhythm preference: **section-to-section gap ≥ 64px on desktop,
≥ 48px on mobile.** Inside a section, use 24–32px.

---

## 6. Layout & grid

| Token                | Value          | Use                                          |
|----------------------|----------------|----------------------------------------------|
| `--container-max`    | 1280px         | Main content max width                       |
| `--container-narrow` | 720px          | Article body, long-form copy                 |
| `--container-padding`| 24px (mobile: 16px) | Horizontal page padding                  |
| `--grid-gutter`      | 24px           | Product grid gap                             |
| `--grid-min-card`    | 280px          | Min product card width — drives `auto-fill`  |

Breakpoints (mobile-first):
| Token         | Value   | Use                                              |
|---------------|---------|--------------------------------------------------|
| `--bp-sm`     | 640px   | Phone landscape / small tablet                   |
| `--bp-md`     | 768px   | Tablet portrait                                  |
| `--bp-lg`     | 1024px  | Tablet landscape / small laptop                  |
| `--bp-xl`     | 1280px  | Desktop                                          |
| `--bp-2xl`    | 1536px  | Wide desktop                                     |

Product grid behaviour:
- **Mobile:** 1 column with full-bleed images
- **640px+:** 2 columns
- **1024px+:** 3 columns
- **1280px+:** 4 columns *(only if product copy stays short)*

---

## 7. Border, radius, elevation

| Token                | Value                                       | Notes                          |
|----------------------|---------------------------------------------|--------------------------------|
| `--radius-none`      | 0                                           | Default for all surfaces       |
| `--radius-sm`        | 2px                                         | Inputs, tooltips               |
| `--radius-pill`      | 999px                                       | Reserved — never on chrome     |
| `--border-width`     | 1px                                         | Default                        |
| `--shadow-none`      | none                                        | Default — no surface shadows   |
| `--shadow-overlay`   | `0 8px 24px rgba(26, 22, 18, 0.08)`         | Modals, drawers only           |

**Cards do not have shadows.** Cards have hairline borders or none at all.
Hover state is a 1px border-color shift, not a lift or shadow.

---

## 8. Motion

| Token                | Value                              | Use                              |
|----------------------|------------------------------------|----------------------------------|
| `--ease-standard`    | `cubic-bezier(0.4, 0, 0.2, 1)`     | Default                          |
| `--ease-decelerate`  | `cubic-bezier(0, 0, 0.2, 1)`       | Entering elements                |
| `--ease-accelerate`  | `cubic-bezier(0.4, 0, 1, 1)`       | Exiting elements                 |
| `--duration-fast`    | 120ms                              | Hover state, focus ring          |
| `--duration-base`    | 200ms                              | Default UI transitions           |
| `--duration-slow`    | 320ms                              | Page-level transitions           |

Always honor `prefers-reduced-motion: reduce` — set transition-duration
to 0 globally inside that media query.

---

## 9. Component patterns

### Buttons
Two variants only. No tertiary "ghost" link-button — that role is just a link.

| Variant     | Background        | Text                | Border                 | On hover                              |
|-------------|-------------------|---------------------|------------------------|---------------------------------------|
| Primary     | `--color-text-primary` | `--color-text-inverse` | none                | bg → 90% opacity                      |
| Secondary   | transparent       | `--color-text-primary` | 1px `--color-border-strong` | bg → `--color-text-primary`, text → inverse |

- Padding: `14px 28px`
- Type: `--type-label` (12px, all-caps, tracked)
- Radius: 0
- Min-width: 160px on desktop, 100% on mobile

### Form inputs
- Background: `--color-bg-elevated`
- Border: 1px `--color-border-subtle`, → `--color-border-strong` on focus
- Radius: 2px
- Padding: `12px 14px`
- Type: `--type-body`
- Label: `--type-label` above the field, 8px gap

### Product card
- No border by default; 1px `--color-border-subtle` on hover
- 1:1 image, `object-fit: cover`, `--color-bg-subtle` placeholder
- Below image: name (`--type-h4`), price (`--type-body`), no shadow
- Padding 16px on text region, no padding on image region

### Header
- Sticky, `--color-bg-primary` background, 1px bottom hairline
- Wordmark left in display serif, weight 400, ~22px
- Nav links right in `--type-label` (all-caps tracked)
- Cart count: text only — "Cart (3)" — no badge bubble

### Footer
- `--color-bg-subtle` background
- Single column on mobile, 4 columns on desktop
- 64px vertical padding minimum
- Links in `--type-body-sm`, secondary text color

---

## 10. Imagery direction

- **Backgrounds:** cream, beige, soft warm gray. No stark white.
- **Lighting:** soft, directional, single source. No flash, no glare.
- **Composition:** centered or thirds, generous negative space.
- **Subject:** product alone, or product with one botanical/textural prop
  (linen, stone, eucalyptus). Never lifestyle scenes with people in
  hero positions.
- **Avoid:** stock-y "happy customer" portraits, glittery overlays,
  high-contrast saturated color, gradient backdrops.

For demo seed data, source from Unsplash collections tagged
`skincare`, `apothecary`, `botanical`, `still-life`. Credit photographers
in `imp-docs/customers/demo-store.md` if relevant.

---

## 11. Voice & copy patterns

| Element              | Pattern                                                            |
|----------------------|--------------------------------------------------------------------|
| Product name         | `[Quality] [Type]` — "Resurfacing Serum," "Geranium Hand Wash"     |
| Short description    | One sentence, ingredient-forward. ≤ 80 chars.                      |
| Long description     | 2–4 short paragraphs. State what it does, what's in it, how to use.|
| Ingredient list      | INCI names, comma-separated, no bullet points                      |
| Volume / size        | "100 mL," "1.7 fl oz" — both, lowercase units, thin space          |
| Price                | "₹2,400" — currency symbol attached, no `.00` if whole number      |
| CTA                  | Verb + noun, all-caps, ≤ 3 words. "ADD TO CART," "VIEW DETAILS"   |
| Empty cart           | "Your cart is empty." — no apologies, no exclamation               |
| Error                | State the cause and the fix. "Card declined. Try another method."  |

---

## 12. Implementation checklist

When applying these tokens to the codebase:

- [ ] Create `src/styles/tokens.css` with every CSS custom property above
- [ ] Create `src/styles/typography.css` for `@font-face` declarations + base type styles
- [ ] Update `src/index.css` to import tokens.css + typography.css
- [ ] Replace existing `--primary-color`, `--text-headline`, etc. references with the new token names
- [ ] Audit each component CSS file (`Header.css`, `Footer.css`, `ProductCard.css`, `Cart.css`, `Checkout.css`, `OrderConfirmation.css`, `ProductDetail.css`) and remap to tokens
- [ ] Add `prefers-reduced-motion` block to `index.css`
- [ ] Load EB Garamond + Inter via Google Fonts in `index.php` or self-host in `public/fonts/`
- [ ] Verify against this document — every visual choice should trace to a token

---

## 13. References

Visual reference benchmarks (see commit conversation for the curated list):
- [Aesop](https://www.aesop.com) — primary north star
- [Tatcha](https://www.tatcha.com) — secondary
- [The Ordinary](https://theordinary.com) — for clinical typography patterns
- [Le Labo](https://www.lelabofragrances.com) — for typographic discipline

Don't lift directly — these are calibration points, not templates.
