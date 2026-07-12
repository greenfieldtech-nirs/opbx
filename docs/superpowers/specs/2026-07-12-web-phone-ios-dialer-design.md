# Web Phone iOS-Style Dialer Design Specification

> **Project:** OpBX - Open Source Business PBX on Cloudonix CPaaS
> **Feature:** Modernize the Web Phone UI to look like an iOS mobile dialer
> **Date:** 2026-07-12

---

## 1. Goal

Update the Web Phone drawer so it resembles the familiar iOS Phone app dialer: a centered number display, large circular keys, and prominent green/red circular action buttons, while preserving all existing functionality.

---

## 2. Design Direction

Adopt the **iOS-style Light** direction selected by the user:
- Light, clean surface consistent with the existing shadcn/ui theme.
- Large circular keys (1-9, *, 0, #) arranged in a standard 3×4 grid.
- Number display centered above the dial pad.
- Large circular **green** call button and **red** hangup button.
- Compact, friendly layout with proper spacing and visual hierarchy.
- Subtle shadows and rounded surfaces to match a mobile app feel.

---

## 3. Scope

### In Scope
- Redesign the dial pad with circular keys and larger touch targets.
- Redesign the number display area to be centered and phone-like.
- Replace the existing call/hangup button row with large circular green/red buttons.
- Add a backspace icon next to the number display (replacing the separate backspace button).
- Keep the existing mute/hold in-call controls but style them to match the new dialer aesthetic.
- Keep incoming call handling with answer/reject buttons styled consistently.
- Preserve the existing status line, dial pad functionality, and error states.

### Out of Scope
- Dark mode variant.
- New calling features (transfer, conference, contacts).
- Animations beyond standard Tailwind transitions.
- Custom haptics or native mobile behaviors.

---

## 4. Layout

```
+-----------------------------------+
|  Header: Web Phone  |  X (close)  |
+-----------------------------------+
|                                   |
|         Status line               |
|                                   |
|                                   |
|              1234                 |  <-- Number display (large, centered)
|                                   |
|        [backspace icon]           |
|                                   |
|   1     2     3                   |
|   4     5     6                   |  <-- Circular dial pad keys
|   7     8     9                   |
|   *     0     #                   |
|                                   |
|        [GREEN CALL]   [RED END]   |  <-- Large circular action buttons
|                                   |
+-----------------------------------+
```

During a connected call, the dial pad is replaced by the in-call control panel (mute, hold, hangup) with the same circular button style.

---

## 5. Visual Details

### Dial Pad Keys
- Shape: circle.
- Size: 64px × 64px on desktop, 56px × 56px on mobile.
- Background: `bg-secondary` / `bg-gray-100` (`#f2f2f7`).
- Text: dark gray, font-semibold, text-xl.
- Hover: slight scale and shadow.
- Active/tap: scale down and darken slightly.
- Sub-labels (e.g., ABC on 2) are not required for v1; only the digit is shown.

### Number Display
- Centered horizontally.
- Font: `text-3xl` / `font-medium`.
- Letter spacing: `tracking-widest`.
- Backspace icon: `X` or delete icon, positioned to the right of the number, only shown when digits are present.

### Action Buttons
- Call button: large circle, `bg-green-500`, white phone icon, 64px.
- Hangup/End button: large circle, `bg-red-500`, white phone-off icon, 64px.
- Answer button: green circle, `PhoneCall` icon.
- Reject button: red circle, `PhoneOff` icon.
- Mute/Hold buttons: circular outline style, active state filled.

### Layout Spacing
- Use `gap-4` between dial pad rows.
- Center all content vertically within the drawer.
- Add more padding and breathing room compared to the current compact grid.

---

## 6. Component Structure

The implementation stays in `frontend/src/components/WebPhone/WebPhoneDrawer.tsx` and uses inline Tailwind classes. No new components are required unless the dial pad grows beyond a single file.

Key states to render:
- **Ready state:** number display + dial pad + call/hangup buttons.
- **Ringing/connected state:** show call status and in-call controls (mute, hold, hangup) instead of the dial pad.
- **Incoming call state:** overlay with answer/reject buttons and caller info.
- **Error/no-extension state:** unchanged center-aligned message layout.

---

## 7. Testing

1. **Frontend build:** `npm run type-check` and `npm run build` must pass.
2. **Backend tests:** `./run-tests.sh` must still pass (no backend changes expected).
3. **Visual regression:** Verify the drawer renders correctly at drawer width `sm:max-w-[380px]` and on mobile.

---

## 8. Files to Modify

- `frontend/src/components/WebPhone/WebPhoneDrawer.tsx` — redesign the dial pad, number display, and call controls.

---

## 9. References

- iOS Phone app dialer visual reference.
- Existing Web Phone implementation: `frontend/src/components/WebPhone/WebPhoneDrawer.tsx`.
- Tone indications design: `docs/superpowers/specs/2026-07-12-tone-indications-design.md`.
