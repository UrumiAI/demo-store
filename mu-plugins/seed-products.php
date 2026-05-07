<?php
/**
 * Seed script for Glow & Co. cosmetics demo store.
 * Run via: wp eval-file seed-products.php --allow-root
 */

// Category mapping (from creation output)
$cats = [
    'skincare'    => 25, 'cleansers'    => 26, 'moisturizers' => 27, 'serums'     => 28, 'sunscreen'   => 29,
    'makeup'      => 30, 'foundation'   => 31, 'lipstick'     => 32, 'eye_makeup' => 33, 'blush'       => 34,
    'haircare'    => 35, 'shampoo'      => 36, 'conditioner'  => 37, 'treatments' => 38,
    'body_care'   => 39, 'lotions'      => 40, 'scrubs'       => 41, 'bath'       => 42,
    'fragrances'  => 43, 'tools'        => 44,
];

$products = [
    // === SKINCARE - Cleansers ===
    ['name' => 'Gentle Foam Cleanser', 'price' => '24.00', 'cats' => [25,26], 'sku' => 'GC-CLN-001', 'desc' => 'A lightweight foaming cleanser that removes impurities without stripping moisture. Enriched with chamomile and aloe vera.', 'short' => 'Gentle daily foaming cleanser for all skin types.', 'stock' => 150, 'weight' => '0.5'],
    ['name' => 'Charcoal Deep Pore Cleanser', 'price' => '28.00', 'cats' => [25,26], 'sku' => 'GC-CLN-002', 'desc' => 'Activated charcoal draws out deep-seated impurities and excess oil. Perfect for oily and combination skin.', 'short' => 'Deep-cleansing charcoal face wash.', 'stock' => 120, 'weight' => '0.5'],
    ['name' => 'Micellar Cleansing Water', 'price' => '18.00', 'cats' => [25,26], 'sku' => 'GC-CLN-003', 'desc' => 'No-rinse micellar water that gently lifts away makeup and dirt. Suitable for sensitive skin.', 'short' => 'Gentle no-rinse micellar cleanser.', 'stock' => 200, 'weight' => '0.6'],
    ['name' => 'Oil-to-Milk Cleanser', 'price' => '32.00', 'cats' => [25,26], 'sku' => 'GC-CLN-004', 'desc' => 'Transforms from oil to milk on contact with water. Dissolves sunscreen and heavy makeup effortlessly.', 'short' => 'Double-cleansing oil-to-milk formula.', 'stock' => 90, 'weight' => '0.5'],

    // === SKINCARE - Moisturizers ===
    ['name' => 'Hydra-Glow Daily Moisturizer', 'price' => '38.00', 'cats' => [25,27], 'sku' => 'GC-MOI-001', 'desc' => 'Lightweight gel-cream with hyaluronic acid and niacinamide for 24-hour hydration. Non-comedogenic formula.', 'short' => '24-hour hydrating gel-cream moisturizer.', 'stock' => 180, 'weight' => '0.4'],
    ['name' => 'Rich Repair Night Cream', 'price' => '52.00', 'cats' => [25,27], 'sku' => 'GC-MOI-002', 'desc' => 'Intensive overnight repair cream with retinol, peptides, and shea butter. Wake up to plumper, firmer skin.', 'short' => 'Overnight repair cream with retinol.', 'stock' => 100, 'weight' => '0.4'],
    ['name' => 'Oil-Free Matte Moisturizer', 'price' => '29.00', 'cats' => [25,27], 'sku' => 'GC-MOI-003', 'desc' => 'Controls shine all day while keeping skin hydrated. Perfect under makeup for oily skin types.', 'short' => 'Matte-finish oil-free moisturizer.', 'stock' => 130, 'weight' => '0.4'],
    ['name' => 'Barrier Repair Cream', 'price' => '45.00', 'cats' => [25,27], 'sku' => 'GC-MOI-004', 'desc' => 'Ceramide-rich formula rebuilds and strengthens the skin barrier. Ideal for dry, sensitive, or compromised skin.', 'short' => 'Ceramide barrier repair moisturizer.', 'stock' => 85, 'weight' => '0.4'],

    // === SKINCARE - Serums ===
    ['name' => 'Vitamin C Brightening Serum', 'price' => '48.00', 'cats' => [25,28], 'sku' => 'GC-SER-001', 'desc' => '20% stabilized vitamin C with ferulic acid. Targets dark spots, uneven tone, and dullness for radiant skin.', 'short' => 'High-potency vitamin C brightening serum.', 'stock' => 110, 'weight' => '0.3'],
    ['name' => 'Hyaluronic Acid Hydrating Serum', 'price' => '36.00', 'cats' => [25,28], 'sku' => 'GC-SER-002', 'desc' => 'Multi-weight hyaluronic acid pulls moisture into every layer of skin. Plumps fine lines instantly.', 'short' => 'Deep hydration hyaluronic acid serum.', 'stock' => 160, 'weight' => '0.3'],
    ['name' => 'Retinol Renewal Serum', 'price' => '55.00', 'cats' => [25,28], 'sku' => 'GC-SER-003', 'desc' => 'Encapsulated retinol minimizes wrinkles and pores with less irritation. Gradual-release technology.', 'short' => 'Anti-aging retinol serum with gradual release.', 'stock' => 75, 'weight' => '0.3'],
    ['name' => 'Niacinamide Pore Refining Serum', 'price' => '32.00', 'cats' => [25,28], 'sku' => 'GC-SER-004', 'desc' => '10% niacinamide with zinc PCA. Minimizes pores, controls sebum, and evens skin texture.', 'short' => 'Pore-minimizing niacinamide serum.', 'stock' => 140, 'weight' => '0.3'],
    ['name' => 'Peptide Firming Complex', 'price' => '62.00', 'cats' => [25,28], 'sku' => 'GC-SER-005', 'desc' => 'A potent blend of six peptides that firm, lift, and smooth aging skin. Clinical results in 4 weeks.', 'short' => 'Multi-peptide firming and lifting serum.', 'stock' => 60, 'weight' => '0.3'],

    // === SKINCARE - Sunscreen ===
    ['name' => 'Invisible Shield SPF 50', 'price' => '34.00', 'cats' => [25,29], 'sku' => 'GC-SUN-001', 'desc' => 'Weightless chemical sunscreen that leaves zero white cast. Broad spectrum UVA/UVB protection.', 'short' => 'Invisible finish SPF 50 sunscreen.', 'stock' => 200, 'weight' => '0.4'],
    ['name' => 'Tinted Mineral Sunscreen SPF 40', 'price' => '38.00', 'cats' => [25,29], 'sku' => 'GC-SUN-002', 'desc' => 'Mineral zinc oxide formula with a universal tint. Protects and perfects in one step.', 'short' => 'Tinted mineral SPF 40 with skin-perfecting finish.', 'stock' => 150, 'weight' => '0.4'],

    // === MAKEUP - Foundation ===
    ['name' => 'Skin Tint Glow Foundation', 'price' => '42.00', 'cats' => [30,31], 'sku' => 'GC-FND-001', 'desc' => 'Sheer-to-medium coverage with a dewy, skin-like finish. Infused with squalane and hyaluronic acid.', 'short' => 'Dewy skin tint foundation.', 'stock' => 95, 'weight' => '0.4'],
    ['name' => 'Full Coverage Matte Foundation', 'price' => '46.00', 'cats' => [30,31], 'sku' => 'GC-FND-002', 'desc' => 'Buildable full coverage with a long-lasting matte finish. 30 shades available. Oil-free.', 'short' => 'Long-wear matte full coverage foundation.', 'stock' => 80, 'weight' => '0.4'],
    ['name' => 'CC Cream SPF 30', 'price' => '36.00', 'cats' => [30,31], 'sku' => 'GC-FND-003', 'desc' => 'Color-correcting cream that evens tone, hydrates, and protects. The perfect everyday base.', 'short' => 'Color-correcting cream with SPF 30.', 'stock' => 110, 'weight' => '0.4'],
    ['name' => 'Concealer Stick', 'price' => '22.00', 'cats' => [30,31], 'sku' => 'GC-FND-004', 'desc' => 'Creamy stick concealer for targeted coverage on dark circles, blemishes, and redness.', 'short' => 'Targeted coverage concealer stick.', 'stock' => 170, 'weight' => '0.1'],

    // === MAKEUP - Lipstick ===
    ['name' => 'Velvet Matte Lipstick - Ruby Red', 'price' => '26.00', 'cats' => [30,32], 'sku' => 'GC-LIP-001', 'desc' => 'Richly pigmented matte lipstick that feels like velvet. Hydrating formula with vitamin E.', 'short' => 'Velvet matte lipstick in classic red.', 'stock' => 200, 'weight' => '0.1'],
    ['name' => 'Velvet Matte Lipstick - Nude Rose', 'price' => '26.00', 'cats' => [30,32], 'sku' => 'GC-LIP-002', 'desc' => 'The perfect everyday nude with rose undertones. Comfortable, non-drying matte formula.', 'short' => 'Everyday nude rose matte lipstick.', 'stock' => 220, 'weight' => '0.1'],
    ['name' => 'Velvet Matte Lipstick - Berry Kiss', 'price' => '26.00', 'cats' => [30,32], 'sku' => 'GC-LIP-003', 'desc' => 'A deep berry shade perfect for evening looks. Long-lasting color that does not feather.', 'short' => 'Deep berry matte lipstick.', 'stock' => 180, 'weight' => '0.1'],
    ['name' => 'Glossy Lip Oil - Peach', 'price' => '20.00', 'cats' => [30,32], 'sku' => 'GC-LIP-004', 'desc' => 'Nourishing lip oil with a glossy, non-sticky finish. Jojoba and rosehip oils condition lips.', 'short' => 'Hydrating glossy lip oil in peach.', 'stock' => 250, 'weight' => '0.1'],
    ['name' => 'Glossy Lip Oil - Cherry', 'price' => '20.00', 'cats' => [30,32], 'sku' => 'GC-LIP-005', 'desc' => 'Sheer cherry tint with intense shine. Plumps and conditions with every application.', 'short' => 'Sheer cherry tinted lip oil.', 'stock' => 230, 'weight' => '0.1'],
    ['name' => 'Lip Liner - Universal', 'price' => '16.00', 'cats' => [30,32], 'sku' => 'GC-LIP-006', 'desc' => 'A universal shade lip liner that works with any lip color. Prevents feathering and bleeding.', 'short' => 'Universal shade lip liner pencil.', 'stock' => 300, 'weight' => '0.05'],

    // === MAKEUP - Eye Makeup ===
    ['name' => 'Smoky Eyes Palette', 'price' => '44.00', 'cats' => [30,33], 'sku' => 'GC-EYE-001', 'desc' => '12-shade eyeshadow palette with matte, shimmer, and metallic finishes. Highly blendable formula.', 'short' => '12-shade smoky eye palette.', 'stock' => 70, 'weight' => '0.3'],
    ['name' => 'Everyday Neutrals Palette', 'price' => '40.00', 'cats' => [30,33], 'sku' => 'GC-EYE-002', 'desc' => 'Curated neutral shades from ivory to espresso. Buildable, crease-proof, all-day wear.', 'short' => 'Neutral eyeshadow palette for everyday wear.', 'stock' => 90, 'weight' => '0.3'],
    ['name' => 'Precision Liquid Eyeliner', 'price' => '18.00', 'cats' => [30,33], 'sku' => 'GC-EYE-003', 'desc' => 'Ultra-fine felt tip for precise lines. Waterproof, smudge-proof, lasts up to 24 hours.', 'short' => 'Waterproof precision liquid eyeliner.', 'stock' => 190, 'weight' => '0.05'],
    ['name' => 'Volumizing Mascara', 'price' => '24.00', 'cats' => [30,33], 'sku' => 'GC-EYE-004', 'desc' => 'Buildable volume without clumping. Curved brush lifts and separates every lash.', 'short' => 'Buildable volumizing mascara.', 'stock' => 210, 'weight' => '0.1'],
    ['name' => 'Brow Sculpt Pencil', 'price' => '19.00', 'cats' => [30,33], 'sku' => 'GC-EYE-005', 'desc' => 'Micro-tip pencil for natural, hair-like strokes. Includes spoolie brush for blending.', 'short' => 'Micro-tip eyebrow sculpting pencil.', 'stock' => 160, 'weight' => '0.05'],

    // === MAKEUP - Blush ===
    ['name' => 'Silk Blush - Coral Sunrise', 'price' => '28.00', 'cats' => [30,34], 'sku' => 'GC-BLU-001', 'desc' => 'Silky powder blush with a natural satin finish. Blends seamlessly for a healthy flush.', 'short' => 'Satin powder blush in warm coral.', 'stock' => 130, 'weight' => '0.2'],
    ['name' => 'Silk Blush - Pink Petal', 'price' => '28.00', 'cats' => [30,34], 'sku' => 'GC-BLU-002', 'desc' => 'Soft pink with subtle shimmer. Perfect for a romantic, fresh-faced look.', 'short' => 'Shimmer blush in soft pink.', 'stock' => 140, 'weight' => '0.2'],
    ['name' => 'Cream Blush Stick - Peony', 'price' => '24.00', 'cats' => [30,34], 'sku' => 'GC-BLU-003', 'desc' => 'Dewy cream blush in stick form. Tap and blend for an effortless, lit-from-within glow.', 'short' => 'Cream blush stick for dewy glow.', 'stock' => 100, 'weight' => '0.1'],
    ['name' => 'Highlighter Duo', 'price' => '32.00', 'cats' => [30,34], 'sku' => 'GC-BLU-004', 'desc' => 'Champagne and rose gold highlighter duo. Finely milled for a blinding, glass-skin glow.', 'short' => 'Champagne & rose gold highlighter palette.', 'stock' => 85, 'weight' => '0.2'],

    // === HAIRCARE - Shampoo ===
    ['name' => 'Volumizing Shampoo', 'price' => '22.00', 'cats' => [35,36], 'sku' => 'GC-SHA-001', 'desc' => 'Sulfate-free shampoo that adds body and bounce to fine, limp hair. Biotin-infused formula.', 'short' => 'Sulfate-free volumizing shampoo.', 'stock' => 170, 'weight' => '0.8'],
    ['name' => 'Hydrating Shampoo', 'price' => '22.00', 'cats' => [35,36], 'sku' => 'GC-SHA-002', 'desc' => 'Intense moisture for dry, damaged hair. Argan oil and coconut extract replenish and soften.', 'short' => 'Argan oil hydrating shampoo.', 'stock' => 180, 'weight' => '0.8'],
    ['name' => 'Color Protect Shampoo', 'price' => '26.00', 'cats' => [35,36], 'sku' => 'GC-SHA-003', 'desc' => 'Extends the life of color-treated hair. UV filters and antioxidants prevent fading.', 'short' => 'Color-safe protecting shampoo.', 'stock' => 120, 'weight' => '0.8'],

    // === HAIRCARE - Conditioner ===
    ['name' => 'Deep Repair Conditioner', 'price' => '24.00', 'cats' => [35,37], 'sku' => 'GC-CON-001', 'desc' => 'Keratin-infused conditioner that repairs breakage and split ends. Detangles instantly.', 'short' => 'Keratin deep repair conditioner.', 'stock' => 160, 'weight' => '0.8'],
    ['name' => 'Lightweight Daily Conditioner', 'price' => '20.00', 'cats' => [35,37], 'sku' => 'GC-CON-002', 'desc' => 'Everyday conditioner that hydrates without weighing hair down. Great for fine to normal hair.', 'short' => 'Lightweight everyday conditioner.', 'stock' => 190, 'weight' => '0.8'],

    // === HAIRCARE - Treatments ===
    ['name' => 'Overnight Hair Mask', 'price' => '34.00', 'cats' => [35,38], 'sku' => 'GC-TRT-001', 'desc' => 'Leave-in overnight treatment that transforms damaged hair by morning. Avocado and honey complex.', 'short' => 'Overnight intensive hair repair mask.', 'stock' => 80, 'weight' => '0.6'],
    ['name' => 'Scalp Revival Serum', 'price' => '38.00', 'cats' => [35,38], 'sku' => 'GC-TRT-002', 'desc' => 'Targets dry, itchy scalp with tea tree and salicylic acid. Promotes healthy hair growth.', 'short' => 'Scalp treatment serum for healthy growth.', 'stock' => 70, 'weight' => '0.3'],
    ['name' => 'Heat Protectant Spray', 'price' => '19.00', 'cats' => [35,38], 'sku' => 'GC-TRT-003', 'desc' => 'Shields hair up to 450°F. Lightweight spray adds shine and reduces frizz during heat styling.', 'short' => 'Heat protection spray up to 450°F.', 'stock' => 200, 'weight' => '0.4'],

    // === BODY CARE - Lotions ===
    ['name' => 'Shea Butter Body Lotion', 'price' => '22.00', 'cats' => [39,40], 'sku' => 'GC-LOT-001', 'desc' => 'Rich yet fast-absorbing body lotion with pure shea butter. 48-hour moisture for very dry skin.', 'short' => 'Shea butter 48-hour body lotion.', 'stock' => 200, 'weight' => '0.8'],
    ['name' => 'Firming Body Lotion', 'price' => '28.00', 'cats' => [39,40], 'sku' => 'GC-LOT-002', 'desc' => 'Caffeine and green tea extract tone and firm the skin. Reduces the appearance of cellulite.', 'short' => 'Firming and toning body lotion.', 'stock' => 130, 'weight' => '0.8'],
    ['name' => 'After-Sun Aloe Gel', 'price' => '16.00', 'cats' => [39,40], 'sku' => 'GC-LOT-003', 'desc' => 'Cooling aloe vera gel soothes and repairs sun-exposed skin. Lightweight, non-greasy formula.', 'short' => 'Cooling after-sun aloe vera gel.', 'stock' => 150, 'weight' => '0.6'],

    // === BODY CARE - Scrubs ===
    ['name' => 'Coffee Body Scrub', 'price' => '24.00', 'cats' => [39,41], 'sku' => 'GC-SCR-001', 'desc' => 'Arabica coffee grounds exfoliate and energize. Coconut oil leaves skin silky smooth.', 'short' => 'Energizing coffee body scrub.', 'stock' => 110, 'weight' => '0.7'],
    ['name' => 'Sugar Glow Body Polish', 'price' => '26.00', 'cats' => [39,41], 'sku' => 'GC-SCR-002', 'desc' => 'Fine sugar crystals gently polish away dead skin. Vitamin E and jojoba oil nourish.', 'short' => 'Gentle sugar body polish.', 'stock' => 100, 'weight' => '0.7'],

    // === BODY CARE - Bath & Shower ===
    ['name' => 'Lavender Bath Soak', 'price' => '28.00', 'cats' => [39,42], 'sku' => 'GC-BTH-001', 'desc' => 'Dead Sea salts infused with lavender essential oil. Relaxes muscles and calms the mind.', 'short' => 'Lavender Dead Sea salt bath soak.', 'stock' => 90, 'weight' => '1.0'],
    ['name' => 'Citrus Burst Shower Gel', 'price' => '18.00', 'cats' => [39,42], 'sku' => 'GC-BTH-002', 'desc' => 'Invigorating shower gel with grapefruit and orange extracts. Rich lather, fresh scent.', 'short' => 'Energizing citrus shower gel.', 'stock' => 180, 'weight' => '0.7'],
    ['name' => 'Eucalyptus Shower Steamer (6-pack)', 'price' => '15.00', 'cats' => [39,42], 'sku' => 'GC-BTH-003', 'desc' => 'Aromatherapy shower steamers that release eucalyptus vapor. Turn your shower into a spa.', 'short' => 'Eucalyptus aromatherapy shower steamers.', 'stock' => 250, 'weight' => '0.5'],

    // === FRAGRANCES ===
    ['name' => 'Eau de Parfum - Midnight Bloom', 'price' => '78.00', 'cats' => [43], 'sku' => 'GC-FRG-001', 'desc' => 'Seductive floral scent with notes of jasmine, black rose, and sandalwood. Long-lasting sillage.', 'short' => 'Luxurious floral eau de parfum.', 'stock' => 50, 'weight' => '0.5'],
    ['name' => 'Eau de Parfum - Citrus Garden', 'price' => '72.00', 'cats' => [43], 'sku' => 'GC-FRG-002', 'desc' => 'Fresh and vibrant with bergamot, lemon verbena, and white tea. Perfect for daytime wear.', 'short' => 'Fresh citrus eau de parfum.', 'stock' => 55, 'weight' => '0.5'],
    ['name' => 'Eau de Parfum - Velvet Oud', 'price' => '95.00', 'cats' => [43], 'sku' => 'GC-FRG-003', 'desc' => 'Warm, woody, and mysterious. Oud, amber, and vanilla create a captivating unisex fragrance.', 'short' => 'Warm oud and amber eau de parfum.', 'stock' => 40, 'weight' => '0.5'],
    ['name' => 'Body Mist - Fresh Linen', 'price' => '22.00', 'cats' => [43], 'sku' => 'GC-FRG-004', 'desc' => 'Light, airy body mist with clean cotton and white musk notes. An everyday essential.', 'short' => 'Clean and airy everyday body mist.', 'stock' => 180, 'weight' => '0.5'],
    ['name' => 'Rollerball Perfume - Rose Gold', 'price' => '28.00', 'cats' => [43], 'sku' => 'GC-FRG-005', 'desc' => 'Travel-friendly rollerball with rose, peony, and a hint of gold musk. Touch up on the go.', 'short' => 'Travel-size rose perfume rollerball.', 'stock' => 200, 'weight' => '0.1'],

    // === TOOLS & BRUSHES ===
    ['name' => 'Professional Brush Set (12-piece)', 'price' => '58.00', 'cats' => [44], 'sku' => 'GC-TLS-001', 'desc' => 'Complete brush set with synthetic bristles for face, eyes, and lips. Includes vegan leather roll case.', 'short' => '12-piece professional makeup brush set.', 'stock' => 60, 'weight' => '0.6'],
    ['name' => 'Beauty Blender Duo', 'price' => '16.00', 'cats' => [44], 'sku' => 'GC-TLS-002', 'desc' => 'Two latex-free makeup sponges for seamless foundation and concealer blending. Reusable.', 'short' => 'Latex-free blending sponge duo.', 'stock' => 250, 'weight' => '0.1'],
    ['name' => 'Jade Face Roller', 'price' => '32.00', 'cats' => [44], 'sku' => 'GC-TLS-003', 'desc' => 'Genuine jade stone roller reduces puffiness and promotes circulation. Dual-ended for face and under-eye.', 'short' => 'Genuine jade facial roller.', 'stock' => 80, 'weight' => '0.3'],
    ['name' => 'LED Light Therapy Mask', 'price' => '129.00', 'cats' => [44], 'sku' => 'GC-TLS-004', 'desc' => '7-color LED therapy mask targets acne, wrinkles, and hyperpigmentation. FDA-cleared, rechargeable.', 'short' => '7-color LED facial therapy mask.', 'stock' => 30, 'weight' => '0.8'],
    ['name' => 'Eyelash Curler', 'price' => '14.00', 'cats' => [44], 'sku' => 'GC-TLS-005', 'desc' => 'Ergonomic design with silicone pad for pinch-free curling. Includes two replacement pads.', 'short' => 'Ergonomic pinch-free eyelash curler.', 'stock' => 200, 'weight' => '0.1'],
    ['name' => 'Makeup Mirror with Ring Light', 'price' => '45.00', 'cats' => [44], 'sku' => 'GC-TLS-006', 'desc' => 'Vanity mirror with adjustable LED ring light and 10x magnification. USB rechargeable.', 'short' => 'LED ring light vanity mirror.', 'stock' => 50, 'weight' => '1.2'],
];

echo "Creating " . count($products) . " products...\n";

$created = 0;
foreach ($products as $p) {
    $product = new WC_Product_Simple();
    $product->set_name($p['name']);
    $product->set_regular_price($p['price']);
    $product->set_sku($p['sku']);
    $product->set_description($p['desc']);
    $product->set_short_description($p['short']);
    $product->set_category_ids($p['cats']);
    $product->set_manage_stock(true);
    $product->set_stock_quantity($p['stock']);
    $product->set_stock_status('instock');
    $product->set_weight($p['weight']);
    $product->set_status('publish');
    $product->set_reviews_allowed(true);

    // Add sale price for some products (~25%)
    if ($created % 4 === 0) {
        $sale = round((float)$p['price'] * 0.8, 2);
        $product->set_sale_price(number_format($sale, 2, '.', ''));
    }

    $product->save();
    $created++;
    if ($created % 10 === 0) echo "  Created $created products...\n";
}

echo "Done! Created $created products.\n";
