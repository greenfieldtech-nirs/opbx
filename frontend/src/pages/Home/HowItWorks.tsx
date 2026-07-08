import { Phone, Server, Workflow } from 'lucide-react';

const steps = [
  {
    number: '1',
    title: 'Deploy & Configure',
    description:
      'Launch OPBX with docker-compose, create your organization, and set up users and extensions.',
    icon: Server,
  },
  {
    number: '2',
    title: 'Connect Cloudonix',
    description: 'Link your Cloudonix account with API credentials and configure your DID numbers.',
    icon: Phone,
  },
  {
    number: '3',
    title: 'Route Calls',
    description: 'Your PBX is live! Monitor calls in real-time, adjust routing, and scale as you grow.',
    icon: Workflow,
  },
];

export function HowItWorks() {
  return (
    <section id="how-it-works" className="container mx-auto px-4 py-20 md:py-32">
      <div className="text-center mb-16">
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
          <Workflow className="h-4 w-4 text-primary" />
          <span className="text-xl font-medium text-muted-foreground">How It Works</span>
        </div>
        <h2 className="text-3xl md:text-5xl font-bold mb-4 text-foreground">
          How It Works
        </h2>
        <p className="text-2xl text-muted-foreground max-w-2xl mx-auto">
          Get started with OPBX in three simple steps
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
        {steps.map((step, idx) => (
          <div key={idx} className="relative group">
            <div className="p-8 rounded-2xl bg-card border border-border h-full transition-all duration-300 hover:border-primary/50 hover:-translate-y-1 hover:shadow-xl">
              <div className="flex items-start justify-between mb-6">
                <div className="h-14 w-14 rounded-xl bg-primary/10 flex items-center justify-center">
                  <step.icon className="h-7 w-7 text-primary" />
                </div>
                <span className="text-4xl font-bold text-primary/20">{step.number}</span>
              </div>
              <h3 className="text-3xl font-bold mb-3 text-foreground">{step.title}</h3>
              <p className="text-muted-foreground leading-relaxed">{step.description}</p>
            </div>
            {idx < 2 && (
              <div className="hidden md:block absolute top-1/2 -right-4 w-8 h-[2px] bg-gradient-to-r from-primary/50 to-transparent" />
            )}
          </div>
        ))}
      </div>
    </section>
  );
}
