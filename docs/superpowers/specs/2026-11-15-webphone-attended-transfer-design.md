# Web Phone attended transfer — design

**Status:** draft for review (2026-11-15). Builds on blind transfer (`e770de942`, `ddabde367`, `c07a8c694`).

## Intent

A Web Phone user on a call wants to talk to the transfer target before handing the call over:
"I have Alice on the line about her invoice, can you take her?" Blind transfer sends the call
without that conversation. Attended transfer adds it: the caller waits, the user consults the
target, then either completes the transfer (the caller and the target are connected, the user
leaves) or returns to the caller.

Terms used below:

- **A**: the party the user is talking to when the transfer starts (the caller, or the person the user called).
- **B**: the transfer target (an extension or an outside number).
- **Consult call**: the user's call to B.

## Goals

- From the in-call screen: pick a target as for blind transfer, then **Consult first**.
- While consulting: A waits (and hears something, see decision D1); the user talks to B.
- **Complete transfer**: A and B are connected; the user's calls end.
- **Return to caller**: the consult call ends (or B never answered); the user is back with A.
- Same call sources as blind transfer: calls the user placed, calls to their extension, ring group
  and call queue calls (including the conference-mediated modes).
- Same rules as blind transfer: B is routed as if the user's extension dialled it (extension
  routing, outbound whitelist, the extension's outbound caller ID). Ownership is proven the same
  way (`CallTransferService::findSession`).
- Queue calls: closed as answered by the agent and transferred when the transfer completes
  (`QueueCallLifecycleService::finalizeTransferred`), not when the consult starts.
- Embed: governed by the same per-snippet **Allow call transfer** switch.

## Non-goals

- Conference (three-way) calling: the user cannot join A and B and stay on.
- Transferring to ring groups, IVRs or queues by name (typing their extension number works, as for blind).
- Announcing A to B automatically (whisper). The user does it by talking.
- Desk phones / SIP apps: attended transfer is a Web Phone feature (desk phones keep their own SIP REFER).

## Anti-goals (a failure even if it "works")

- **A is lost.** Whatever fails (B busy, OPBX error, the user closes the tab), A must end up
  talking to someone or in voicemail, never stuck in a silent room or dropped.
- A hears dead air without knowing they are on hold.
- A second queue statistic for the same call, or the agent left "busy" after the transfer.

## Building blocks that already exist

| Need | Existing piece |
|------|----------------|
| Find and verify the user's call by SIP Call-ID | `CallTransferService::findSession` |
| Move the other party of the user's call elsewhere (3 modes) | `CallTransferService::blindTransfer` (switch / fork / conference-bridged) |
| Signed CXML callback URLs | `CallTransferService::signedUrl` pattern, `voice.transfer` route |
| Two-party room with hold music, ends when a member leaves | `CxmlBuilder::dialConferenceRoom(holdMusic, endOnExit)`; rooms named within Cloudonix's 16-character limit (`RingGroupConferenceService::roomName`) |
| Delayed safety net | `ExpireRingGroupOfferJob` pattern (queued job with delay) |
| Session-ended hooks | `QueueCallLifecycleService::trackSessionState` → `RingGroupConferenceService::sessionEnded` |
| Special dial strings handled by the routing engine | `CoachRoutingService::tryHandle` (`To` sentinel patterns) |

Cloudonix has no "bridge two sessions" API (checked: Calls API lists application, fork, mute,
conferences). Connecting A and B means putting both into one conference room.

## The design choice: where A waits (decision D1)

### Option P: park A in a room on the server (recommended)

1. **Consult first** → `POST /v1/webphone/transfer/consult {call_id, destination}`. OPBX proves
   ownership, then moves A into a private park room (the same three modes as blind transfer, with
   CXML that joins room `x<15 hex>` with hold music). The Web Phone's leg to A ends. OPBX stores
   the consult in Redis: `xfer:{id}` → organization, user, extension, room, A's session token,
   destination, queue call id if any. Response: `{transfer_id}`.
2. The Web Phone places the consult call to B with its normal `ua.call()`, so B's routing,
   whitelist and caller ID are exactly those of a normal call from the extension.
3. **Complete** → `POST /v1/webphone/transfer/{id}/complete {call_id}` (the consult call's Call-ID,
   proven as placed by the extension). OPBX forks the consult call: the Web Phone's side hangs up,
   B joins the park room. Both A and B have `endConferenceOnExit`, so either hanging up ends the
   other. Queue call finalized. State deleted.
4. **Return to caller** → the Web Phone ends the consult call (if any) and dials the sentinel
   `*xfer<id>` (new pattern next to the coaching sentinels). The routing engine checks that the
   dialling extension owns that consult and answers with CXML that joins the park room. State deleted.
5. **Safety net**: a delayed job fires after T minutes (decision D2). If the consult is still open,
   OPBX switches A's session to ring the transferring extension (then its normal no-answer
   handling, such as voicemail).
6. **A hangs up while parked**: A's session ends; the session-ended hook marks the consult as gone.
   Complete then answers 410 "The caller hung up", and the Web Phone keeps the consult call as an
   ordinary call. Return to caller answers 410 too.

**For:** the browser only ever holds one call, so the Web Phone's single-call state model stays.
A hears hold music by construction. Every failure path ends with A in a room that OPBX controls,
with the safety net behind it. It reuses the room and signed-URL patterns already in production
for ring groups and queues.

**Against:** returning to A is a new leg (about 1–2 s before the user hears A again). Going back
and forth between A and B means parking and retrieving each time.

### Option C: hold A in the browser (two SIP calls)

The Web Phone puts A on SIP hold (the existing Hold button), places the consult call as a second
JsSIP session, and swaps between them locally. Complete asks OPBX to move A's other party and B's
callee into a fresh room (blind-transfer modes for A, fork for B). Cancel = hang up B, unhold A.

**For:** instant swap; no server state until Complete.

**Against:** what A hears on SIP hold is up to Cloudonix (needs testing; likely silence). The Web
Phone becomes multi-call (state, timers, audio element, busy guard, tones, keypad, calls log):
the largest change in this feature. If the tab closes mid-consult, A's leg ends with it: A is lost,
which breaks the anti-goal.

**Recommendation: Option P.**

## Web Phone UI (Option P)

- The Transfer form gets two actions: **Transfer now** (today's blind transfer) and **Consult first**.
- Consult screen (replaces the in-call view):
  - Top: "On hold: Alice (+1 555…)" with a hold timer.
  - Middle: the consult call to B, with its own timer and the usual Mute / Keypad controls.
  - Bottom: **Complete transfer** (primary) and **Return to caller**.
  - While B rings: **Complete transfer** is disabled; **Return to caller** cancels the consult call.
  - B busy / unanswered / failed: a message ("Bob did not answer"), and **Return to caller** stays the only action.
- Leaving the screen any other way (closing the tab, signing out) leaves A to the safety net.
- Header status during consult: "Consulting".

## API (Option P)

| Method | Endpoint | Body | Result |
|--------|----------|------|--------|
| POST | `/v1/webphone/transfer/consult` | `call_id`, `destination` | 200 `{transfer_id}`; 404 / 422 / 502 as blind transfer |
| POST | `/v1/webphone/transfer/{id}/complete` | `call_id` (consult call) | 200; 404 unknown or not yours; 410 caller gone; 502 Cloudonix refused (both calls stay as they are) |
| POST | `/v1/webphone/transfer/{id}/cancel` | none | 200; ends the consult record without retrieving (for "A hung up"); the retrieve itself is the `*xfer<id>` dial |
| — | `*xfer<id>` dialled by the Web Phone | — | CXML joining the park room; unavailable message when not yours or gone |

Embed equivalents under `/v1/embed/transfer/...`, gated by `allow_transfer`.

## Edge cases to cover in tests

1. Consult on each source mode (switch, fork, conference-bridged); A always lands in the room.
2. Complete while B is still ringing: rejected (the UI disables it; the server checks B's call is answered).
3. A hangs up during consult → complete answers 410, consult call untouched, no queue close.
4. B hangs up during consult → user returns to caller.
5. Safety-net job: open consult → A switched to ring the extension; closed consult → no-op.
6. `*xfer<id>` dialled by another extension → refused.
7. Queue call: closed once at complete, never at consult; abandoned during park → normal CDR path.
8. Double complete / complete after cancel → idempotent (one fork, one queue close).

## Decisions needed from you

- **D1.** Option P (park A on the server, hold music, single browser call) or Option C (SIP hold in the browser, two calls)? Recommended: P.
- **D2.** Safety-net timeout and target: after how long does a parked caller go back to the user's extension? Proposed: 5 minutes, then ring the extension (its no-answer handling applies, e.g. voicemail).
- **D3.** Back and forth: is **Return to caller** after a consult enough, or must the user be able to swap between A and B several times before deciding? With Option P each swap is a re-park. Proposed: complete or return only.

## Left to the builder

- Room naming (`x` + 15 hex chars of a hash of the consult id) and Redis TTLs.
- The exact sentinel syntax, as long as the transfer form's validation (digits only) still rejects it from user input.
- Copy for messages, following the blind-transfer wording.
