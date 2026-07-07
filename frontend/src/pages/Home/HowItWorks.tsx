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
        <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
          <Workflow className="h-4 w-4 text-primary" />
          <span className="text-sm font-medium text-primary">Getting Started</span>
        </div>
        <h2 className="text-4xl md:text-5xl font-bold mb-4">How It Works</h2>
        <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
          Get started with OPBX in three simple steps
        </p>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
        {steps.map((step, idx) => (
          <div key={idx} className="relative">
            <div className="text-center">
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary text-primary-foreground text-2xl font-bold mb-6">
                {step.number}
              </div>
              <div className="mb-4">
                <step.icon className="h-12 w-12 text-primary mx-auto" />
              </div>
              <h3 className="text-2xl font-semibold mb-3">{step.title}</h3>
              <p className="text-muted-foreground text-lg">{step.description}</p>
            </div>
            {idx < 2 && (
              <div className="hidden md:block absolute top-8 left-[60%] w-[80%] h-0.5 bg-gradient-to-r from-primary to-transparent" />
            )}
          </div>
        ))}
      </div>
    </section>
  );
}
