import { Bot, MessageSquare, UserCheck } from 'lucide-react';

export function AIHandoff() {
  return (
    <section className="py-20 md:py-28 bg-card/30 border-y border-border">
      <div className="container mx-auto px-4">
        <div className="max-w-5xl mx-auto">
          <div className="text-center mb-16">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
              <Bot className="h-4 w-4 text-primary" />
              <span className="text-xl font-medium text-muted-foreground">AI + Human Handoff</span>
            </div>
            <h2 className="text-3xl md:text-5xl font-bold mb-4 text-foreground">
              AI agents that know when to hand off
            </h2>
            <p className="text-2xl text-muted-foreground max-w-3xl mx-auto">
              OPBX Dograh agents handle routine inquiries and escalate to the right team member when a
              call needs a human touch. Context, transcript, and suggested next actions travel with
              the transfer.
            </p>
          </div>

          <div className="relative rounded-3xl border border-border bg-card p-8 md:p-12 overflow-hidden">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-center">
              <div className="flex flex-col items-center text-center gap-4">
                <div className="relative h-24 w-24 rounded-full bg-primary/10 flex items-center justify-center">
                  <MessageSquare className="h-10 w-10 text-primary" />
                  <div className="absolute inset-0 rounded-full border border-primary animate-ring-pulse" />
                </div>
                <h3 className="text-xl font-bold text-foreground">Inbound call</h3>
                <p className="text-xl text-muted-foreground">A customer reaches your DID number and OPBX routes it to a Dograh AI agent.</p>
              </div>

              <div className="flex flex-col items-center text-center gap-4">
                <div className="h-24 w-24 rounded-full bg-primary/10 flex items-center justify-center">
                  <Bot className="h-10 w-10 text-primary" />
                </div>
                <h3 className="text-xl font-bold text-foreground">OPBX evaluates</h3>
                <p className="text-xl text-muted-foreground">Classifies intent, urgency, and the right destination using your routing rules.</p>
              </div>

              <div className="flex flex-col items-center text-center gap-4">
                <div className="h-24 w-24 rounded-full bg-primary/10 flex items-center justify-center">
                  <UserCheck className="h-10 w-10 text-primary" />
                </div>
                <h3 className="text-xl font-bold text-foreground">Human takes over</h3>
                <p className="text-xl text-muted-foreground">Warm handoff with summary, transcript, and suggested next steps.</p>
              </div>
            </div>

            <svg
              className="absolute top-1/2 left-0 w-full h-24 -translate-y-1/2 hidden md:block pointer-events-none"
              viewBox="0 0 800 100"
              preserveAspectRatio="none"
            >
              <path
                d="M 160 50 C 260 50, 280 20, 400 50 C 520 80, 540 50, 640 50"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                className="text-primary/30"
                strokeDasharray="8 8"
              >
                <animate attributeName="stroke-dashoffset" from="100" to="0" dur="2s" repeatCount="indefinite" />
              </path>
              <circle r="4" fill="currentColor" className="text-primary">
                <animateMotion path="M 160 50 C 260 50, 280 20, 400 50 C 520 80, 540 50, 640 50" dur="3s" repeatCount="indefinite" />
              </circle>
            </svg>
          </div>
        </div>
      </div>
    </section>
  );
}
