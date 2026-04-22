---
name: elite-brand-design
description: |
  Brand identity creation and visual identity strategy. Covers defining brand personality, positioning, tone of voice, color psychology, typography as voice, visual identity systems, and living brand documentation. Use when asked about: brand design, visual identity, logo, brand guidelines, brand system, brand strategy, branding, brand colors, brand typography, style guide, brand refresh, brand personality, tone of voice, brand identity, logo design, brand consistency, visual branding, brand guidelines creation, brand package, generate brand assets, brand deliverables, brand identity package, or when translating a brand brief into web design decisions. Brand decisions translate to implementation via elite-design-core (tokens), elite-gsap (motion language), and elite-ux-strategy (conversion copy).
---

Strategic brand identity creation — from personality definition to visual system.

## Quick Reference
| Topic | Reference File |
|-------|---------------|
| Brand fundamentals | [brand-fundamentals.md](references/brand-fundamentals.md) |
| Visual identity | [visual-identity-system.md](references/visual-identity-system.md) |
| Color psychology | [brand-color-psychology.md](references/brand-color-psychology.md) |
| Typography as voice | [brand-typography.md](references/brand-typography.md) |
| Tone of voice | [brand-tone-voice.md](references/brand-tone-voice.md) |
| Style guide creation | [brand-style-guide.md](references/brand-style-guide.md) |
| Brand touchpoints | [brand-touchpoints.md](references/brand-touchpoints.md) |
| Brand evolution | [brand-evolution.md](references/brand-evolution.md) |
| Accessible branding | [brand-accessibility.md](references/brand-accessibility.md) |
| Brand package generator | [brand-package.md](references/brand-package.md) |

## Related Skills
- **elite-design-core** — Design tokens, spacing, type scales (the implementation layer)
- **elite-gsap** — Logo animation, motion language
- **elite-css-animations** — CSS-native brand motion effects
- **elite-layouts** — Brand-consistent layout systems
- **elite-performance** — Brand asset optimization
- **elite-accessibility** — Inclusive design requirements
- **elite-ux-strategy** — Brand as conversion driver
- **elite-inspiration** — Archetype case studies

---

## Brand vs Design
**Brand** is what people feel when they encounter you. It exists in their minds, not in your files.

**Branding** is the deliberate act of shaping that perception through consistent signals.

**Brand identity** is the system of visual, verbal, and experiential elements that carry those signals.

This skill covers creating brand identity from scratch — the strategic decisions that inform every design choice downstream.

---

### Phase 1: Discovery
Who are you? Who are you for? What do you believe?
→ See [brand-fundamentals.md](references/brand-fundamentals.md)

### Phase 2: Personality
What archetype fits? What traits define your voice?
→ See [brand-fundamentals.md](references/brand-fundamentals.md)

### Phase 3: Visual Identity
Logo, color, typography, texture — the visible system.
→ See [visual-identity-system.md](references/visual-identity-system.md), [brand-color-psychology.md](references/brand-color-psychology.md), [brand-typography.md](references/brand-typography.md)

### Phase 4: Verbal Identity
Tone of voice, messaging hierarchy, microcopy.
→ See [brand-tone-voice.md](references/brand-tone-voice.md)

### Phase 5: Documentation
Living style guide that scales with the team.
→ See [brand-style-guide.md](references/brand-style-guide.md)

---

Five production archetypes showing how brand personality maps to design:

| Archetype | Personality | Type Signal | Color Signal | Shape Signal |
|-----------|-------------|-------------|-------------|--------------|
| **Modern/Bold** | Confident, direct, technical | Geometric sans (Space Grotesk) | High-contrast dark + saturated accent | Moderate radius |
| **Luxury/Editorial** | Refined, aspirational, sensory | High-contrast serif (Cormorant Garamond) | Warm metallics on neutral | Soft radius |
| **Warm/Inclusive** | Safe, empathetic, human | Soft variable serif (Fraunces) | Muted expanded palette (plum/sage/lavender) | Generous radius |
| **Authoritative** | Commanding, trustworthy, established | Sturdy serif (Gelasio) | Traditional power colors (navy/gold) | Zero radius (sharp) |
| **Professional** | Competent, reliable, caring | Paired serif+sans (DM family) | Clean, functional neutrals | Standard radius |

- "Authoritative" → serif display font + `border-radius: 0 !important` globally + navy/gold tokens
- "Warm/Inclusive" → variable font with `SOFT` axis + plum-tinted shadows + generous border-radius
- "Modern/Bold" → geometric sans + tight letter-spacing + grain texture overlay
- "Luxury/Editorial" → high-contrast serif + italic accent font + gold/warm-neutral tokens

→ See **elite-design-core** for token implementation, **elite-gsap** for motion language.

---

## Common Pitfalls
1. **Starting with colors** — Start with personality and audience. Colors follow.
2. **Too many fonts** — Two families maximum. Three font roles (display, body, mono) is the ceiling.
3. **Inconsistent application** — A brand system only works if every touchpoint follows it. Document everything.
4. **Copying competitors** — Differentiation is the point. Study competitors to avoid their choices.
5. **Ignoring accessibility** — A brand that can't be perceived by all users isn't a complete brand.
6. **Decoration over purpose** — Every brand element should communicate something. If it's just decoration, remove it.
7. **Skipping verbal identity** — Tone of voice is as important as visual identity. A beautiful site with generic copy feels hollow.

---

## Generating the Brand Package
After completing brand discovery (Phases 1-5), generate a complete `brand-assets/` folder with all deliverables:

→ See [brand-package.md](references/brand-package.md)

This produces:
- Design tokens (Tailwind v4 @theme + semantic layer)
- Logo placeholders (icon, wordmark, combination SVGs)
- Favicon set (SVG favicon + Apple touch icon)
- Social templates (OG image + profile image)
- Starter CSS (base styles + component library)
- Brand guidelines document

All branded with the user's discovery answers — not generic templates.
