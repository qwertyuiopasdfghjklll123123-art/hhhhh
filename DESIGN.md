---
name: فيترين (Vitrine)
description: A boutique display-case system for showcasing phones as objects worth wanting, not rows in a marketplace list.
colors:
  emerald-abyss: "#124A38"
  emerald-deep: "#176D54"
  emerald-shade: "#1B5C46"
  emerald-signal: "#1B7A54"
  emerald-signal-hover: "#145C43"
  emerald-bright: "#1F8A5F"
  emerald-brightest: "#2FA36F"
  emerald-line: "#4C8A70"
  gold-foil: "#C9A96A"
  cream-paper: "#FAF9F4"
  cream-deep: "#F2EFE6"
  ink-deep: "#12231C"
  ink-soft: "#33473F"
  ink-faint: "#5B6D65"
  paper-on-dark: "#F3F6F1"
  paper-on-dark-soft: "#D7E6DE"
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
- Medium-emerald grounds carry roughly half the page; cream/white "label card" surfaces carry the rest — lightened from the original near-black draft on direct request, not a fade toward gray.
- Gold-foil hairlines and dotted rings stand in for ornament — never gradients or drop shadows as decoration.
- Arabic-first, RTL-native; no tracking/letter-spacing is applied to Arabic type (it would break letterform joining).
- Motion is deliberately loud and constant, not a single restrained moment: something is always moving somewhere on the page (see each component's entry below), per an explicit request to amplify it past the first draft's restraint.

## Colors

A committed, saturated-green strategy (not a neutral palette with a green accent) — emerald grounds carry whole sections, white/cream label-card surfaces interrupt them for contrast and pacing. The whole scale was lightened one pass from the original near-black-emerald draft on direct request; the two tones below (signal vs. deep) exist specifically because a single "make it lighter" value couldn't satisfy both roles without breaking contrast (see the Named Rule).

### Primary
- **Emerald Signal** (#1B7A54): every interactive surface — button fills, the WhatsApp FAB, link/price color, text selection. This is the lightened, "أقل درجة" green; it still holds 4.86:1 against near-white text and 5.1:1 against white card backgrounds, right at the edge of comfortable AA compliance.
- **Emerald Signal Hover** (#145C43): the hover/active state — this is the *original* darker draft value, repurposed as a press-down/darken feedback once the resting state got lighter, rather than trying to find an even-lighter hover tone (there isn't contrast headroom left above Signal for that direction).

### Secondary
- **Emerald Bright** (#1F8A5F) / **Emerald Brightest** (#2FA36F): decorative only — gradient stops inside the drawn phone-silhouette SVGs. Never used as a text or background color behind text, since neither clears body-text contrast.

### Neutral
- **Emerald Abyss** (#124A38): the darkest ground — footer, and the outer stop of every full-bleed section gradient.
- **Emerald Deep** (#176D54): the primary dark section ground and the *brightest* stop those gradients are allowed to reach — deliberately one step darker than Emerald Signal (see Named Rule).
- **Emerald Shade** (#1B5C46): the phone-silhouette body color and the trust-ticker's text-on-cream color.
- **Emerald Line** (#4C8A70): hairline strokes on dark surfaces (phone silhouette outline, ghost-button borders).
- **Gold Foil** (#C9A96A): the one non-green hue — nav underline, ticker dots, seal rings, brand mark. Used only as thin lines, rings, and small marks, never as a fill behind text or a gradient.
- **Cream Paper** (#FAF9F4) / **Cream Deep** (#F2EFE6): light-section grounds and the trust-ticker band.
- **Ink Deep** (#12231C) / **Ink Soft** (#33473F) / **Ink Faint** (#5B6D65): text on light surfaces, darkest to lightest, all tinted green rather than true gray.
- **Paper on Dark** (#F3F6F1): primary text on every dark ground.
- **Paper on Dark Soft** (#D7E6DE): secondary/muted text on dark grounds — a *solid* pale sage, not a translucent white. An alpha-reduced white was the original approach, but once the background lightened, a 74%-opacity white against the lightest gradient stop measured only ~3.4:1 against WCAG's 4.5:1 floor; a solid, deliberately-tinted color was the fix, matching the craft rule that secondary text on a color should be tinted from that hue, never just faded.

### Named Rules
**The One Hue Rule.** Gold never fills a shape or sits behind text — hairlines, rings, and small marks only. The moment it becomes a background or a gradient stop, it stops reading as foil and starts reading as decoration.

**The Brightest-Is-Interactive Rule.** Emerald Signal (#1B7A54) is the lightest tone allowed to carry body text or sit behind it at scale. Section backgrounds cap out one step darker (Emerald Deep, #176D54) specifically so that secondary on-dark text keeps a real contrast margin. Don't reach for Signal as a full-bleed background fill — that was tried and it broke Paper on Dark Soft's contrast.

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
- **Hover:** background steps *darker* to Emerald Signal Hover (not brighter — see Colors); lifts and scales up slightly (`translateY(-2px) scale(1.04)`).
- **Outline / Ghost:** transparent fill, Emerald Signal or Paper-on-Dark border and text depending on the ground it sits on; inverts to a filled Emerald Deep on hover.
- **Motion:** the hero's primary CTA additionally carries a slow (2.6s) breathing glow on its shadow — the only button that pulses at rest, to draw the eye to the one action that matters most on first paint.

### Hero device (signature component)
A CSS-drawn phone frame (rounded body, top notch, bottom home-indicator bar) standing on the pedestal, its screen filled by an actual screenshot of this site (`img/site-preview.png`) rather than an abstract silhouette or generated art — a literal "phone browsing our site," per direct request. The frame floats on a continuous 5s up/down cycle; the pedestal shadow beneath it pulses in sync-adjacent rhythm (3.6s) rather than identically, so the two don't read as one mechanical loop.

### Cards (specimen / product / voice)
- **Corner:** 16px.
- **Background:** white or Cream Paper.
- **Shadow:** see Elevation & Depth; no border.
- **Internal padding:** 1.4–1.75rem.
- **Distinctive behavior:** the "specimen label" pattern — a small eyebrow line, a bold name, one line of specs, then a price/action row pinned to the card's bottom edge via flex.
- **Motion:** product cards lift, scale up slightly, and zoom their device icon on hover; voice cards lift and tilt a degree (alternating left/right by position) rather than lifting flat. All cards, plus every section heading, fade and rise into place once on scroll, staggered a fraction of a second apart rather than arriving together.

### Seals (signature component)
A certificate-style trust badge: a dashed gold ring behind short heading+paragraph text, no card background at all. Arranged in an explicit 2-column grid with staggered `margin-top` per item rather than a uniform row, so the four trust claims read as a considered arrangement instead of a repeated icon-card template. The dashed ring spins continuously and slowly (18s per rotation, like a second hand's cousin), speeding up on hover — a "living certificate" rather than a static badge.

### Navigation
Sticky header, transparent over the hero until scrolled (then solid Emerald Deep with a shadow). Links get a gold underline that grows from 0 width on hover. Below 980px, links move into a full-width dropdown panel opened by a three-line toggle that morphs into an X, dropping in with a small overshoot bounce rather than simply appearing.

### WhatsApp FAB
Fixed circular pill, Emerald Signal fill, hidden (opacity 0, `pointer-events: none`) until the visitor scrolls roughly 60% past the hero — added specifically because it otherwise overlaps the hero's own specimen card on small screens at first paint. Once visible, a soft ring pulses outward from it every ~2.2s (a "ping"), so it keeps calling attention to itself as a standing invitation to message the shop, not just a static icon.

## Do's and Don'ts

### Do:
- **Do** keep gold to hairlines, rings, and marks — never a fill, never a gradient stop.
- **Do** use Emerald Signal (#1B7A54), not Emerald Bright (#1F8A5F), for any new text-on-white or text-on-dark interactive surface; Bright is decorative-only per the contrast failure noted under Colors.
- **Do** vary card/section sizing (the feature product card, the staggered seals) rather than repeating a uniform grid — the single-object "vitrine" premise is the whole differentiator.
- **Do** give every new section at least one animated moment (an entrance, a hover response, or a continuous idle loop) — per this project's explicit direction, stillness is the exception here, not the default.
- **Do** respect `prefers-reduced-motion`: every animation and transition in this system collapses to near-zero duration under that media query. A new animation that skips this check breaks the one accessibility guarantee the rest of the system relies on.

### Don't:
- **Don't** add letter-spacing/tracking to Arabic text.
- **Don't** reach for an icon-on-top/heading/one-line-text card grid for a new trust or feature section — use the seal/certificate pattern instead.
- **Don't** treat the product catalog, prices, contact number, or testimonials as real — every one is an authored placeholder (see PRODUCT.md's Capabilities and Constraints) until the shop owner supplies real data.
- **Don't** use Emerald Signal (or anything lighter) as a full-bleed section background — see The Brightest-Is-Interactive Rule under Colors.
- **Don't** animate a static `transform` and a keyframe `animation` on the same element without checking for conflicts — this system uses the standalone `translate` property (not `transform`) for the specimen card's rise/drift specifically because the card also needs a static `transform: translateX(50%)` for centering on mobile; the two would otherwise silently fight over the same property.

---

*Written directly from the built page in this session (scan mode), not from a separate design-tokens file — this is the project's first surface, so the code is the only source of truth. `.impeccable/design.json` (the Stitch-panel sidecar) was intentionally not generated: this project has no live-panel tooling in use, and the sidecar's value is specifically for that panel. Regenerate it later with `$impeccable document` if that changes.*
