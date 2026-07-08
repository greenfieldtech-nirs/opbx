# OPBX Homepage Redesign — Design Spec

**Date:** 2026-07-08
**Approach:** "A + B" hybrid inspired by voicemail.work, but telling the OPBX product story.

## Visual System

- **Page background:** `#333333` (`rgb(51 51 51 / var(--tw-bg-opacity, 1))`), full-bleed.
- **Card/surface background:** `#252526`.
- **Primary accent:** gold `#c9a227` for CTAs, labels, highlights.
- **Text:** near-white `#f5f5f5` for headings, soft gray `#cccccc` for body, muted `#888888` for secondary text.
- **Borders:** `#444444`.
- **Typography:** keep `Oranienbaum` for the home page (already applied).

## Section Structure (redesign everything)

1. **HomeHeader** — dark transparent/sticky, logo, nav, sign-in + CTA.
2. **Hero** — large emotional headline "The calls you miss are the customers you lose", subhead, CTAs, animated phone/call demo.
3. **Missed-Calls Calculator** — sliders for missed calls/week, job value, booking rate, showing estimated revenue lost.
4. **How It Works** — three numbered steps: forward line, AI answers & routes, hear back when it matters.
5. **AI Agent + Human Handoff** — animated conversation flow diagram.
6. **Features Grid** — 8 PBX feature cards (AI Voice, Smart Routing, Live Monitor, Auto Dialer, Ring Groups, IVR, Call Tracking, Business Hours).
7. **Use Case Cards** — Trades, Healthcare, Legal/Professional.
8. **Technology / Trust** — Cloudonix + Laravel + React + Redis + open-source MIT.
9. **Pricing** — simple card with free trial CTA.
10. **FAQ** — expandable accordion.
11. **Final CTA** — "Stop letting the phone cost you jobs." with trial button.
12. **Footer** — links, copyright, GitHub.

## Animations

- **Hero demo:** subtle CSS animation of a phone receiving a call and routing to an AI agent.
- **Scroll reveals:** sections fade/slide in as they enter viewport.
- **Calculator:** sliders update the revenue number in real time.
- **Handoff diagram:** animated path/pulses showing caller → AI → human/CRM.
- **Micro-interactions:** hover states on cards and buttons.

## Scope

- Modify only files under `frontend/src/pages/Home/` and `frontend/src/pages/Home.tsx`.
- Add home-page-specific styles to `frontend/src/index.css` (a scoped `.home-dark` theme).
- No backend changes.
- Keep existing routing and links.

## Verification

- `npm run type-check` passes.
- `npm run build` passes.
- Visual review in browser.
