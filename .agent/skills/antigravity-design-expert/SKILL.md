---
name: antigravity-design-expert
description: Advanced UI/UX skill for weightless, spatial, and glassmorphism interfaces using GSAP and 3D CSS.
---

# Antigravity UI & Design Expert

## When to Use
- Building premium, highly interactive interfaces.
- Using **GSAP, 3D CSS, or React Three Fiber**.
- Designing landing pages or dashboards with "Spatial Depth".

## Design Principles (The "Antigravity" Vibe)
- **Weightlessness**: Floating elements with layered, soft, diffused shadows.
- **Glassmorphism**: `backdrop-filter: blur(12px)` + semi-transparent borders.
- **Spatial Depth**: Z-axis layering and `perspective` transforms.
- **Isometric Snapping**: Tilting UI cards (`rotateX(60deg) rotateZ(-45deg)`).

## Motion Rules
- **No Instant Snaps**: `0.3s ease-out` minimum for all states.
- **GSAP ScrollTrigger**: Elements float into view with rotation.
- **Domino Stagger**: Entrance animations staggered by `0.1s`.
- **Parallax**: Background moves slower than foreground for depth.

## Technical Requirements
- Default stack: **React/Next.js + Tailwind + GSAP**.
- Performance: Use `will-change: transform`.
- A11y: Respect `prefers-reduced-motion`.
