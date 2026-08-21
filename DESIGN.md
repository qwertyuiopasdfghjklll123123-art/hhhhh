---
name: فيترين (Vitrine)
description: A boutique display-case system for showcasing phones as objects worth wanting, not rows in a marketplace list.
colors:
  emerald-abyss: "#07231A"
  emerald-deep: "#0B3D2E"
  emerald-shade: "#0E2A20"
  emerald-signal: "#145C43"
  emerald-signal-hover: "#0F6B4E"
  emerald-bright: "#1F8A5F"
  emerald-brightest: "#2FA36F"
  emerald-line: "#3F6A57"
  gold-foil: "#C9A96A"
  cream-paper: "#FAF9F4"
  cream-deep: "#F2EFE6"
  ink-deep: "#12231C"
  ink-soft: "#33473F"
  ink-faint: "#5B6D65"
  paper-on-dark: "#F3F6F1"
typography:
  display:
    fontFamily: "Reem Kufi, Segoe UI, Tahoma, sans-serif"
    fontSize: "clamp(2.3rem, 3.6vw + 1rem, 4.25rem)"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: normal
  body:
    fontFamily: "Mada, Segoe UI, Tahoma, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.65
    letterSpacing: normal
rounded:
  sm: "10px"
  card: "16px"
  pill: "999px"
spacing:
  section-block: "clamp(3.5rem, 8vw, 6rem)"
  card-pad: "1.5rem"
components:
  button-primary:
    backgroundColor: "{colors.emerald-signal}"
    textColor: "{colors.paper-on-dark}"
    rounded: "{rounded.pill}"
    padding: "0.85rem 1.6rem"
  button-primary-hover:
    backgroundColor: "{colors.emerald-signal-hover}"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.emerald-signal}"
    rounded: "{rounded.pill}"
    padding: "0.6rem 1.15rem"
---

# Design System: فيترين (Vitrine)

## Overview

**Creative North Star: "The Vitrine" — a boutique display case**

Vitrine (فيترين, a loanword Arabic already uses for a shop's glass display case) rejects both ruts a phone-retail landing page defaults to: the dark-hero/floating-render SaaS template, and the dense, cluttered marketplace grid. Instead each phone is presented the way a jeweler presents a watch — alone, spotlit, on a plinth, with a small printed specimen label giving its name, one defining spec, and its price. The system is built from three borrowed grammars: museum/gallery signage (specimen labels, dotted certificate rings), boutique retail lighting (radial spotlight glow, pedestal shadow), and Arabic Kufic signage tradition (the geometric Reem Kufi display face, chosen because Kufic letterforms historically lived on architecture and signage — the same "object under a spotlight" register this system wants, not a training-data default).

No visual rejection was pinned by the brief beyond "green and white, professional" — the marketplace-density option and the flat SaaS-gradient option were both considered and rejected in favor of this single-object presentation, which is the one differentiator a discount reseller can't easily copy.

**Key Characteristics:**
- One object at a time, lit and labeled, never a dense grid of thumbnails.
- Deep emerald grounds carry roughly half the page; cream/white "label card" surfaces carry the rest.
- Gold-foil hairlines and dotted rings stand in for ornament — never gradients or drop shadows as decoration.
- Arabic-first, RTL-native; no tracking/letter-spacing is applied to Arabic type (it would break letterform joining).

## Colors

A committed, saturated-green strategy (not a neutral palette with a green accent) — emerald grounds carry whole sections, white/cream label-card surfaces interrupt them for contrast and pacing.

### Primary
- **Emerald Signal** (#145C43): every interactive surface — button fills, the WhatsApp FAB, link/price color, text selection. Chosen (over the brighter #1F8A5F first drafted) because the brighter shade fails 4.5:1 text contrast against both white cards and near-white text on dark; Signal holds ~7:1 in both directions.
- **Emerald Signal Hover** (#0F6B4E): the hover/active state for every Emerald Signal surface — brighter than rest state for feedback, still ≥5.9:1 against white or near-white text.

### Secondary
- **Emerald Bright** (#1F8A5F) / **Emerald Brightest** (#2FA36F): decorative only now — gradient stops inside the drawn phone-silhouette SVGs and the hero's breathing spotlight glow. Never used as a text or background color behind text, since neither clears body-text contrast.

### Neutral
- **Emerald Abyss** (#07231A): the darkest ground — footer, deepest gradient stop.
- **Emerald Deep** (#0B3D2E): the primary dark section ground (hero, commitment, contact gradients start here).
- **Emerald Shade** (#0E2A20): the phone-silhouette body color and the trust-ticker's text-on-cream color.
- **Emerald Line** (#3F6A57): hairline strokes on dark surfaces (phone silhouette outline, ghost-button borders).
- **Gold Foil** (#C9A96A): the one non-green hue — nav underline, ticker dots, seal rings, brand mark. Used only as thin lines, rings, and small marks, never as a fill behind text or a gradient.
- **Cream Paper** (#FAF9F4) / **Cream Deep** (#F2EFE6): light-section grounds and the trust-ticker band.
- **Ink Deep** (#12231C) / **Ink Soft** (#33473F) / **Ink Faint** (#5B6D65): text on light surfaces, darkest to lightest, all tinted green rather than true gray.
- **Paper on Dark** (#F3F6F1, and at 74% alpha for secondary text): text on every dark ground.

### Named Rules
**The One Hue Rule.** Gold never fills a shape or sits behind text — hairlines, rings, and small marks only. The moment it becomes a background or a gradient stop, it stops reading as foil and starts reading as decoration.

## Typography

**Display Font:** Reem Kufi (fallback: Segoe UI, Tahoma, sans-serif)
**Body Font:** Mada (fallback: Segoe UI, Tahoma, sans-serif)

**Character:** Reem Kufi is a geometric revival of Kufic signage lettering — confident, squared, built for a single large word rather than paragraphs; it carries the "museum label" register. Mada is a clean, warm geometric text face with a calmer, more neutral voice, deliberately not Cairo/Tajawal/Almarai (the three defaults nearly every Arabic template reaches for).

### Hierarchy
- **Display** (700, `clamp(2.3rem, 3.6vw + 1rem, 4.25rem)`, 1.25): the hero headline only.
- **Headline** (700, `clamp(1.7rem, 1.6vw + 1.1rem, 2.5rem)`, 1.25): section headings (المجموعة المختارة، التزامنا تجاهك...).
- **Title** (700, 1.15–1.5rem): product names, seal titles.
- **Body** (400, 1rem, 1.65): paragraph copy, capped at roughly 62ch measure.
- **Label** (600–700, 0.72–0.9rem, no letter-spacing): specimen-card eyebrows, prices, nav links, buttons.

### Named Rules
**The No-Tracking Rule.** Arabic text never receives letter-spacing, positive or negative — Arabic is a joined script and tracking breaks the connections between letterforms. Emphasis comes from weight and size only, never spacing.

## Layout

A centered `1180px` max-width container (`clamp(1.25rem, 4vw, 2.5rem)` side padding) holds every section. Sections alternate dark emerald and light cream grounds for pacing (hero dark → ticker light → collection light → commitment dark → voices light → contact/footer dark). The product gallery is a 4-column CSS grid at desktop (2-column at tablet, 1-column under 620px) with one horizontally-doubled feature card, sized so the grid always fills evenly rather than stranding a card alone in a near-empty row. The commitment section uses an explicit 2-column grid with per-item `margin-top` offsets (0, 2.5rem, 1rem, 3.5rem) to create an asymmetric "salon hang" rhythm instead of a uniform card row.

## Elevation & Depth

Hybrid: label cards (specimen card, product cards, voice cards) use a real two-layer offset shadow (a tight 1px contact shadow plus a soft 40px-blur ambient shadow, both shifted down); dark sections use no shadows at all, relying on the emerald gradient itself plus the gold hairline rings for depth. Elevation is declared once per surface — cards use shadow only, never a border stacked under a shadow.

### Shadow Vocabulary
- **card** (`0 1px 2px rgba(7,35,26,0.06), 0 18px 40px -14px rgba(7,35,26,0.35)`): resting state for every label card.
- **card-hover** (`0 1px 2px rgba(7,35,26,0.08), 0 26px 54px -14px rgba(7,35,26,0.45)`): product/seal/voice cards on hover, paired with a `-6px` lift.

## Shapes

Cards round at 16px (`--radius-card`); small controls (buttons, the WhatsApp FAB) are full pills. Nothing else takes a radius — the phone silhouettes use a 22–34px rounded-rect matching a real device's corner, not the card system. Seal "certificates" are marked by a dashed circular ring (SVG stroke, `stroke-dasharray: 2 5`) rather than a card container at all.

## Components

### Buttons
- **Shape:** full pill (`border-radius: 999px`).
- **Primary:** Emerald Signal fill, Paper-on-Dark text, soft green-tinted glow shadow.
- **Hover:** background steps to Emerald Signal Hover; a `-2px` lift on every button variant.
- **Outline / Ghost:** transparent fill, Emerald Signal or Paper-on-Dark border and text depending on the ground it sits on; inverts to a filled Emerald Deep on hover.

### Cards (specimen / product / voice)
- **Corner:** 16px.
- **Background:** white or Cream Paper.
- **Shadow:** see Elevation & Depth; no border.
- **Internal padding:** 1.4–1.75rem.
- **Distinctive behavior:** the "specimen label" pattern — a small eyebrow line, a bold name, one line of specs, then a price/action row pinned to the card's bottom edge via flex.

### Seals (signature component)
A certificate-style trust badge: a dashed gold ring behind short heading+paragraph text, no card background at all. Arranged in an explicit 2-column grid with staggered `margin-top` per item rather than a uniform row, so the four trust claims read as a considered arrangement instead of a repeated icon-card template.

### Navigation
Sticky header, transparent over the hero until scrolled (then solid Emerald Deep with a shadow). Links get a gold underline that grows from 0 width on hover. Below 980px, links move into a full-width dropdown panel opened by a three-line toggle that morphs into an X.

### WhatsApp FAB
Fixed circular pill, Emerald Signal fill, hidden (opacity 0, `pointer-events: none`) until the visitor scrolls roughly 60% past the hero — added specifically because it otherwise overlaps the hero's own specimen card on small screens at first paint.

## Do's and Don'ts

### Do:
- **Do** keep gold to hairlines, rings, and marks — never a fill, never a gradient stop.
- **Do** use Emerald Signal (#145C43), not Emerald Bright (#1F8A5F), for any new text-on-white or text-on-dark interactive surface; Bright is decorative-only per the contrast failure noted under Colors.
- **Do** vary card/section sizing (the feature product card, the staggered seals) rather than repeating a uniform grid — the single-object "vitrine" premise is the whole differentiator.

### Don't:
- **Don't** add letter-spacing/tracking to Arabic text.
- **Don't** reach for an icon-on-top/heading/one-line-text card grid for a new trust or feature section — use the seal/certificate pattern instead.
- **Don't** treat the product catalog, prices, contact number, or testimonials as real — every one is an authored placeholder (see PRODUCT.md's Capabilities and Constraints) until the shop owner supplies real data.

---

*Written directly from the built page in this session (scan mode), not from a separate design-tokens file — this is the project's first surface, so the code is the only source of truth. `.impeccable/design.json` (the Stitch-panel sidecar) was intentionally not generated: this project has no live-panel tooling in use, and the sidecar's value is specifically for that panel. Regenerate it later with `$impeccable document` if that changes.*
