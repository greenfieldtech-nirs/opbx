# OPBX Public Homepage Redesign

> **Date:** 2026-07-07  
> **Status:** Design approved, ready for implementation plan  
> **Branch:** develop  
> **Related memory:** `.my_agent/memory/ai-assistants.md`

---

## Goal

Update the OPBX public marketing homepage (`frontend/src/pages/Home.tsx`) to:

1. Reflect all new features and enhancements added to the platform since v1.
2. Emphasize **Dograh Cloud** and **Dograh OSS** AI voice-agent capabilities as strategic differentiators.
3. Adopt the **clean, airy layout and typography** of the Dograh website while staying true to OPBX’s existing dark theme and primary-blue color language.

---

## Design Decisions

### 1. Page Structure

| Section | Purpose |
|---------|---------|
| **Header** | Sticky navigation; simplified logo + links (Features, How It Works, Dograh, Docs, Login, Get Started). |
| **Hero** | Retain the general PBX positioning, but lead with the **AI-First** angle and mention AI voice agents. |
| **Dograh Spotlight** | Dedicated, high-impact section with two cards comparing **Dograh Cloud** (managed) and **Dograh OSS** (self-hosted). |
| **Features Grid** | Showcase the full feature set: AI Voice Agents, Auto Dialer, Call Tracking, AI Load Balancers, Smart Routing, Ring Groups, IVR Menus, Business Hours, Real-Time Monitoring, Call Recording, Security, Open Source. |
| **How It Works** | Three-step deployment flow, lightly updated copy. |
| **Technology / Integration** | Cloudonix, Docker, Laravel, React, MySQL, Redis. |
| **FAQ** | Keep accordion; update answers to reference new features. |
| **Final CTA** | Retain existing gradient call-to-action. |
| **Footer** | Reorganized links, add Dograh mention. |

### 2. Visual Style

- **Font:** Add **Inter** via Google Fonts in `frontend/index.html` and use it as the page font.
- **Typography:** Larger, more readable headings (`text-5xl`/`text-6xl` hero), tighter tracking, increased line-height.
- **Spacing:** Dograh-inspired generous whitespace (`py-20`/`py-32` sections).
- **Cards:** `rounded-2xl`, subtle borders (`border`), minimal shadows, hover lift.
- **Colors:** Keep the existing dark theme and primary-blue accent from `frontend/src/index.css`.

### 3. Components

Break the monolithic `Home.tsx` into section components under `frontend/src/pages/Home/`:

- `Hero.tsx`
- `DograhSpotlight.tsx`
- `FeaturesGrid.tsx`
- `HowItWorks.tsx`
- `FAQSection.tsx`
- `Footer.tsx`
- `Home.tsx` becomes the composer that imports and arranges the sections.

### 4. Dograh Spotlight Content

- **Headline:** *“AI Voice Agents for Every PBX”*
- **Subheadline:** *“Plug cloud-managed or self-hosted AI agents directly into your inbound call flow.”*
- **Dograh Cloud card:**
  - Managed by Dograh
  - Fixed endpoint: `wss://api.dograh.com/api/v1/agent-stream/cloudonix`
  - Fastest path to production
- **Dograh OSS card:**
  - Self-hosted
  - Bring your own WebSocket endpoint
  - Full data control

### 5. Features Grid Content

| Feature | Description |
|---------|-------------|
| AI Voice Agents | Cloud & OSS Dograh integration, plus generic AI assistant support. |
| Auto Dialer | Outbound campaign manager with distribution lists and scheduling. |
| Call Tracking | Campaign tracking, DNI snippets, analytics. |
| AI Load Balancers | Distribute inbound calls across AI assistants. |
| Smart Call Routing | DID → extension, ring group, IVR, or AI assistant. |
| Ring Groups | Simultaneous, round-robin, and weighted strategies. |
| IVR Menus | Interactive voice response with custom routing. |
| Business Hours | Time-of-day, holiday, and custom schedule routing. |
| Real-Time Monitoring | Live calls, presence, and session updates. |
| Call Recording | Automatic recording with secure storage. |
| Enterprise Security | RBAC, multi-tenant isolation, audit logging. |
| Open Source | MIT licensed, self-hosted, Docker-ready. |

### 6. Responsive Behavior

- Mobile: single-column stacked sections.
- Tablet: 2-column feature grid.
- Desktop: 4-column feature grid, Dograh cards side-by-side.

---

## Out of Scope

- No backend or API changes.
- No new routes or navigation outside the homepage.
- No new animation libraries or heavy motion design.
- No marketing copy beyond the homepage sections.

---

## Acceptance Criteria

- [ ] `Home.tsx` renders without errors and passes `npm run type-check`.
- [ ] The Dograh Spotlight section is visible and correctly styled.
- [ ] The expanded Features Grid includes all 12 items listed above.
- [ ] The page remains responsive across mobile, tablet, and desktop widths.
- [ ] The Inter font loads correctly and replaces the default sans-serif.
- [ ] The existing dark theme and primary-blue accent are preserved.
- [ ] The section components are isolated and reusable.

---

## Dependencies

- Google Fonts Inter `<link>` added to `frontend/index.html`.
- `lucide-react` icons for all features (already installed).
- Existing shadcn/ui `Button` and `Card` components.

---

## Risks

- The existing `Home.tsx` is large; extracting sections is straightforward but must preserve all imports and JSX.
- The `OPBXLogo` image is large (`h-32`); the header may need adjustment for the cleaner look.
- Keeping Dograh’s layout without adopting Dograh’s colors requires careful spacing and typography to avoid a generic look.
