<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>{{ $title ?? 'Oops... I Messed Up' }}</title>
<style>
    :root {
        --bg1: #0f1020;
        --bg2: #1a1d3a;
        --panel: rgba(20, 24, 45, 0.88);
        --panel-border: rgba(255, 255, 255, 0.08);
        --text: #eef2ff;
        --muted: #aeb8d6;
        --user: #5eead4;
        --bot: #f9a8d4;
        --accent: #fbbf24;
        --danger: #fb7185;
        --shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
    }

    * {
        box-sizing: border-box;
    }

    html, body {
        height: 100%;
        margin: 0;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: var(--text);
        background:
            radial-gradient(circle at top left, #312e81 0%, transparent 35%),
            radial-gradient(circle at bottom right, #831843 0%, transparent 35%),
            linear-gradient(135deg, var(--bg1), var(--bg2));
        overflow: hidden;
    }

    .noise {
        position: fixed;
        inset: 0;
        pointer-events: none;
        opacity: 0.05;
        background-image:
            linear-gradient(0deg, transparent 24%, rgba(255,255,255,.15) 25%, rgba(255,255,255,.15) 26%, transparent 27%, transparent 74%, rgba(255,255,255,.15) 75%, rgba(255,255,255,.15) 76%, transparent 77%, transparent),
            linear-gradient(90deg, transparent 24%, rgba(255,255,255,.12) 25%, rgba(255,255,255,.12) 26%, transparent 27%, transparent 74%, rgba(255,255,255,.12) 75%, rgba(255,255,255,.12) 76%, transparent 77%, transparent);
        background-size: 14px 14px;
        animation: drift 10s linear infinite;
    }

    @keyframes drift {
        from { transform: translate(0, 0); }
        to   { transform: translate(14px, 14px); }
    }

    .wrap {
        min-height: 100%;
        display: grid;
        place-items: center;
        padding: 24px;
    }

    .chat-shell {
        width: min(920px, 100%);
        height: min(700px, 90vh);
        background: var(--panel);
        border: 1px solid var(--panel-border);
        border-radius: 24px;
        box-shadow: var(--shadow);
        backdrop-filter: blur(16px);
        overflow: hidden;
        display: grid;
        grid-template-rows: auto 1fr auto;
    }

    .chat-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 22px;
        border-bottom: 1px solid var(--panel-border);
        background: rgba(255,255,255,0.03);
    }

    .chat-title {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }

    .orb {
        width: 14px;
        height: 14px;
        border-radius: 999px;
        background: linear-gradient(135deg, var(--accent), var(--danger));
        box-shadow: 0 0 18px rgba(251, 191, 36, 0.6);
        animation: pulse 1.8s ease-in-out infinite;
        flex: 0 0 auto;
    }

    @keyframes pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.3); opacity: 0.75; }
    }

    .chat-title h1 {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .chat-title p {
        margin: 2px 0 0;
        color: var(--muted);
        font-size: 0.85rem;
    }

    .status-badge {
        font-size: 0.82rem;
        color: #111827;
        background: linear-gradient(135deg, #fde68a, #fca5a5);
        padding: 8px 12px;
        border-radius: 999px;
        font-weight: 700;
        white-space: nowrap;
    }

    .chat-body {
        overflow: auto;
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 18px;
        scroll-behavior: smooth;
    }

    .msg-row {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }

    .msg-row.user {
        justify-content: flex-end;
    }

    .avatar {
        width: 42px;
        height: 42px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        font-size: 1.1rem;
        flex: 0 0 auto;
        user-select: none;
    }

    .avatar.bot {
        background: linear-gradient(135deg, rgba(249,168,212,.24), rgba(251,113,133,.28));
        border: 1px solid rgba(249,168,212,.25);
    }

    .avatar.user {
        background: linear-gradient(135deg, rgba(94,234,212,.24), rgba(56,189,248,.28));
        border: 1px solid rgba(94,234,212,.25);
    }

    .bubble {
        max-width: min(78ch, 78%);
        padding: 14px 16px;
        border-radius: 18px;
        line-height: 1.5;
        position: relative;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    .bubble.bot {
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.08);
        color: var(--text);
        border-bottom-left-radius: 6px;
    }

    .bubble.user {
        background: linear-gradient(135deg, rgba(34,197,94,.16), rgba(34,211,238,.15));
        border: 1px solid rgba(94,234,212,.16);
        color: #d1fae5;
        border-bottom-right-radius: 6px;
    }

    .meta {
        margin-top: 8px;
        font-size: 0.76rem;
        color: var(--muted);
        opacity: 0.9;
    }

    .typing::after {
        content: "▋";
        display: inline-block;
        margin-left: 2px;
        animation: blink .9s steps(1) infinite;
        color: var(--accent);
    }

    @keyframes blink {
        50% { opacity: 0; }
    }

    .chat-footer {
        border-top: 1px solid var(--panel-border);
        padding: 16px 20px;
        display: flex;
        gap: 12px;
        align-items: center;
        justify-content: space-between;
        background: rgba(255,255,255,0.03);
    }

    .footer-left {
        color: var(--muted);
        font-size: 0.9rem;
    }

    .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .btn {
        appearance: none;
        border: 0;
        border-radius: 14px;
        padding: 12px 16px;
        font-weight: 700;
        cursor: pointer;
        transition: transform .15s ease, opacity .15s ease, box-shadow .2s ease;
    }

    .btn:hover {
        transform: translateY(-1px);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn-primary {
        background: linear-gradient(135deg, #f59e0b, #fb7185);
        color: #111827;
        box-shadow: 0 12px 24px rgba(251, 113, 133, 0.18);
    }

    .btn-secondary {
        background: rgba(255,255,255,0.07);
        color: var(--text);
        border: 1px solid rgba(255,255,255,0.08);
    }

    .glitch {
        position: relative;
        display: inline-block;
    }

    .glitch::before,
    .glitch::after {
        content: attr(data-text);
        position: absolute;
        inset: 0;
        pointer-events: none;
        opacity: 0.5;
    }

    .glitch::before {
        transform: translate(1px, -1px);
        color: #67e8f9;
    }

    .glitch::after {
        transform: translate(-1px, 1px);
        color: #f9a8d4;
    }

    @media (max-width: 640px) {
        .chat-shell {
            height: 100vh;
            border-radius: 0;
        }

        .bubble {
            max-width: 88%;
        }

        .chat-footer {
            flex-direction: column;
            align-items: stretch;
        }

        .actions {
            width: 100%;
        }

        .btn {
            width: 100%;
        }
    }
</style>
</head>
<body data-error-code="{{ $code ?? '404' }}">
<div class="noise"></div>

<div class="wrap">
    <main class="chat-shell" aria-label="Error chat interface">
        <header class="chat-header">
            <div class="chat-title">
                <div class="orb" aria-hidden="true"></div>
                <div>
                    <h1><span class="glitch" id="errorTitle" data-text="{{ $title ?? 'System Oops' }}">{{ $title ?? 'System Oops' }}</span></h1>
                    <p id="subTitle">{{ $subtitle ?? 'A tiny catastrophe has been detected.' }}</p>
                </div>
            </div>
            <div class="status-badge" id="statusBadge">Error {{ $code ?? '???' }}</div>
        </header>

        <section class="chat-body" id="chatBody">
            <div class="msg-row user">
                <div class="bubble user">
                    Hey... what happened here?
                    <div class="meta">visitor • just now</div>
                </div>
                <div class="avatar user">🙂</div>
            </div>

            <div class="msg-row">
                <div class="avatar bot">🤖</div>
                <div class="bubble bot typing" id="typedMessage">{{ $message ?? 'Something went wrong.' }}</div>
            </div>
        </section>

        <footer class="chat-footer">
            <div class="footer-left" id="footerHint">{{ $hint ?? 'I am deeply, profoundly, almost artistically sorry.' }}</div>
            <div class="actions">
                <button class="btn btn-secondary" onclick="history.back()">Go Back</button>
                <button class="btn btn-primary" onclick="location.href='/'">Take Me Home</button>
            </div>
        </footer>
    </main>
</div>

<script>
(function () {
    const ERROR_MAP = {
        403: {
            title: "403 Forbidden",
            subtitle: "Permission denied, with extra dramatic flair.",
            message:
`Um... hi.
I hate to say this, but I am not allowed to let you in here.

This is a 403 Forbidden error.
Which is a very formal way of saying:
"I see you. I respect your curiosity. But I must, with trembling humility, refuse."

Possible reasons:
• You do not have permission for this page
• The server is being protective
• I have failed you socially and technically

Please forgive this embarrassingly firm boundary.`,
            hint: "I wish I could be more helpful, but rules have pinned me to the floor."
        },
        404: {
            title: "404 Not Found",
            subtitle: "The page has vanished into the digital void.",
            message:
`Oh no.
I appear to have misplaced the page you were looking for.

This is a 404 Not Found error.
Which means the destination is missing, moved, or perhaps never existed except in our shared hopes.

I am so sorry.
I had one job.
Actually, I had several jobs, but finding this page was definitely one of them.

Please allow me to apologize in lowercase:
sorry.`,
            hint: "The page is gone. My dignity went with it."
        },
        405: {
            title: "405 Method Not Allowed",
            subtitle: "That request method made the server uncomfortable.",
            message:
`Ah. Right. About that.

This is a 405 Method Not Allowed error.
The page exists, but it did not appreciate the way it was approached.

In other words, the request arrived with the wrong HTTP method.
A bold move. A creative move. A rejected move.

I am not judging.
The server is absolutely judging.
And I am here, meekly relaying its disappointment.`,
            hint: "Same URL. Wrong approach. I am apologizing on behalf of the protocol."
        },
        500: {
            title: "500 Internal Server Error",
            subtitle: "Something inside the machine has emotionally collapsed.",
            message:
`I need to be honest with you.

This is a 500 Internal Server Error.
Something broke on the inside.
Not in a cool mysterious way.
More in a "dropped all the plates while making eye contact" kind of way.

The server tried.
I tried.
We all tried.
And yet here we are, standing in the warm glow of unexpected failure.

Please accept this humble confession:
it is not you.
It is catastrophically, unmistakably me.`,
            hint: "The server tripped over its own shoelaces and I am writing the apology."
        }
    };

    function getErrorCode() {
        const params = new URLSearchParams(window.location.search);
        const fromQuery = parseInt(params.get("code"), 10);
        if (ERROR_MAP[fromQuery]) return fromQuery;

        const bodyCode = parseInt(document.body.dataset.errorCode, 10);
        if (ERROR_MAP[bodyCode]) return bodyCode;

        return 404;
    }

    function typeText(el, text, speed = 18) {
        return new Promise((resolve) => {
            let i = 0;
            el.textContent = "";

            const timer = setInterval(() => {
                el.textContent += text.charAt(i);
                i++;

                const chatBody = document.getElementById("chatBody");
                chatBody.scrollTop = chatBody.scrollHeight;

                if (i >= text.length) {
                    clearInterval(timer);
                    el.classList.remove("typing");
                    resolve();
                }
            }, speed);
        });
    }

    const code = getErrorCode();
    const data = ERROR_MAP[code];

    document.title = `${data.title} • Oops`;
    document.getElementById("statusBadge").textContent = `Error ${code}`;
    document.getElementById("errorTitle").textContent = data.title;
    document.getElementById("errorTitle").setAttribute("data-text", data.title);
    document.getElementById("subTitle").textContent = data.subtitle;
    document.getElementById("footerHint").textContent = data.hint;

    typeText(document.getElementById("typedMessage"), data.message, 16);
})();
</script>
</body>
</html>
