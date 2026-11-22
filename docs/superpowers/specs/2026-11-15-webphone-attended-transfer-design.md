# Web Phone attended transfer — design

**Status:** decisions D1–D4 answered (2026-11-17); ready for an implementation plan. Builds on blind transfer
(`e770de942`, `ddabde367`, `c07a8c694`, fork fix `9ae9183d9`).

## Intent

A Web Phone user on a call wants to talk to the transfer target before handing the call over:
"I have Alice on the line about her invoice, can you take her?" Blind transfer sends the call
without that conversation. Attended transfer adds it: the caller waits on hold, the user consults
the target, then either completes the transfer (the caller and the target are connected, the user
leaves) or returns to the caller.

Terms used below:

- **A**: the party the user is talking to when the transfer starts (the caller, or the person the user called).
- **B**: the transfer target (an extension or an outside number).
- **Consult call**: the user's call to B.

## Decisions

- **D1 — Option C: hold A in the browser.** The Web Phone puts A on SIP hold and places the
  consult call as a second call. A stays on the user's own call until the transfer completes.
- **D2 — A waits on hold for no longer than 90 seconds.**
- **D3 — Complete or return only.** Once B is called, the user either completes the transfer or
  returns to A; that ends the consult. Consulting again means starting a new consult.
- **D4 — warn, then return to A.** From 75 seconds on hold the consult screen shows a countdown
  warning; at 90 seconds the Web Phone returns to A exactly as **Return to caller** does.

## Goals

- From the in-call screen: pick a target as for blind transfer, then **Consult first**.
- While consulting: A is on hold (and hears something, not dead air); the user talks to B.
- **Complete transfer**: A and B are connected; both of the user's calls end.
- **Return to caller**: the consult call ends (or B never answered); A comes off hold.
- Same call sources as blind transfer: calls the user placed, calls to their extension, ring group
  and call queue calls (including the conference-mediated modes).
- Same rules as blind transfer: B is routed as if the user's extension dialled it (the consult call
  is an ordinary call from the Web Phone, so extension routing, the outbound whitelist and the
  extension's outbound caller ID all apply). Ownership of both calls is proven the same way
  (`CallTransferService::findSession`).
- Queue calls: closed as answered by the agent and transferred when the transfer completes
  (`QueueCallLifecycleService::finalizeTransferred`), never when the consult starts.
- Embed: governed by the same per-snippet **Allow call transfer** switch.

## Non-goals

- Conference (three-way) calling: the user cannot join A and B and stay on.
- Switching back and forth between A and B (D3).
- Transferring to ring groups, IVRs or queues by name (typing their extension number works, as for blind).
- Announcing A to B automatically (whisper). The user does it by talking.
- Desk phones / SIP apps: attended transfer is a Web Phone feature.

## Anti-goals (a failure even if it "works")

- **A is lost because of something OPBX did.** A failed Complete must leave A on hold with the user,
  never alone in a room or dropped.
- A hears dead air without knowing they are on hold.
- A second queue statistic for the same call, or the agent left "busy" after the transfer.

**Accepted risk (comes with D1):** A's call lives in the browser. If the user closes the tab, loses
the network or signs out during a consult, A's call ends with it. The Web Phone warns before the
tab is closed while a call is on hold (browser "leave page?" prompt); nothing more can save it.

## How it works

### Consult

1. The user opens Transfer, picks or types B, and presses **Consult first**.
2. The Web Phone puts A on hold (the existing Hold function), starts the 90-second hold timer, and
   places the consult call to B with its normal `ua.call()`.
3. What A hears: Cloudonix's behaviour on a SIP hold is untested. If it is silence, the Web Phone
   plays hold music into A's call itself, by replacing the held call's outgoing microphone track
   with a music track (`RTCRtpSender.replaceTrack`) and restoring it when A comes off hold.
   **Verify on a live call before building; the fallback is the builder's to add if needed.**

### Complete transfer (B answered)

`POST /v1/webphone/transfer/attended {call_id, consult_call_id}` (A's Web Phone Call-ID, B's).

1. OPBX proves both calls are the user's (`findSession` for each; B's must be one the extension
   placed). Unknown or not yours → 404; A's call already ended → 410.
2. OPBX creates a two-party room (name within Cloudonix's 16-character limit, as
   `RingGroupConferenceService::roomName`) and a signed CXML URL that joins it
   (`CxmlBuilder::dialConferenceRoom`, hold music while alone, end-on-exit for both members, so
   either party hanging up ends the other).
3. **B first**: B's call was placed by the Web Phone, so it is forked (live-verified semantics:
   `caller` → the room URL takes B there, `callee` → hangup ends the Web Phone's leg).
4. **Then A**, with the same three modes as blind transfer, to the room URL instead of the
   transfer URL: conference-bridged (switch the caller's own session), placed by the Web Phone
   (fork), or dialled inside the caller's session (switch).
5. If step 4 fails, B is already in the room: OPBX switches B's session to a short "the transfer
   could not be completed" message and hangup, answers 502, and the Web Phone takes A off hold.
   A is never left alone in the room.
6. On success: queue call finalized (once), the Web Phone hangs up its legs if any remain.

Moving B first is what keeps the anti-goal: whichever step fails, A is still on hold with the user.

### Return to caller

Browser only: the Web Phone hangs up the consult call (or cancels it while it rings) and takes A off
hold. No server call.

### The 90-second hold limit

The Web Phone counts from the moment A is put on hold for the consult. From 75 seconds the consult
screen shows a countdown ("Returning to Alice in 15 s"); at 90 seconds it returns to A exactly as
**Return to caller** does (D4). A Complete already in flight at 90 seconds is not interrupted.

## Web Phone changes

The Web Phone becomes able to hold two calls during a consult. This is the largest part of the work:

- A second JsSIP session with its own state, Call-ID, timer and remote audio element.
- Busy guard: a new incoming call during a consult is rejected as busy (as during any call today).
- Keypad, Mute and the volume control act on the consult call while it is up.
- Calls log: both calls appear as they do today (A's and the consult call).
- Session token display (shipped `9d73c843f`) shows the consult call's token while consulting.

### Consult screen (replaces the in-call view)

- Top: "On hold: Alice (+1 555…)" with the hold timer counting toward 90 seconds.
- Middle: the consult call to B, with its own timer and the usual Mute / Keypad controls.
- Bottom: **Complete transfer** (primary) and **Return to caller**.
- While B rings: **Complete transfer** is disabled; **Return to caller** cancels the consult call.
- B busy / unanswered / failed / hangs up: a message ("Bob did not answer"), and **Return to caller**
  is the only action.
- A hangs up while on hold: a message ("Alice hung up"); the consult call becomes an ordinary call.
- Header status during consult: "Consulting".

## API

| Method | Endpoint | Body | Result |
|--------|----------|------|--------|
| POST | `/v1/webphone/transfer/attended` | `call_id`, `consult_call_id` | 200; 404 either call unknown or not yours; 410 A's call ended; 422 invalid; 502 Cloudonix refused (A stays on hold with the user) |

Embed equivalent: `POST /v1/embed/transfer/attended`, gated by `allow_transfer`.

## Edge cases to cover in tests

1. Complete on each of A's source modes (switch, fork, conference-bridged): B forked first, then A
   moved; both land in the same room.
2. A's move fails after B's fork: B's session switched to the failure message, 502, no queue close.
3. B's fork fails: A untouched, 502.
4. `consult_call_id` not placed by this extension (another member's call, or a call to the
   extension) → 404.
5. A hangs up during consult → 410, consult call untouched, no queue close.
6. Queue call: closed once at complete, never at consult.
7. Double Complete → second answers 404/410, one fork, one queue close.
8. Web Phone: hold timer, Return to caller (ringing and answered), B fails, A hangs up, busy guard,
   leave-page warning, the 75-second warning and the 90-second return (D4).

## Left to the builder

- Room naming and the signed-URL payload (follow `CallTransferService::signedUrl`).
- Copy for messages, following the blind-transfer wording.
- The hold-music fallback in the browser, if the live check shows silence.
