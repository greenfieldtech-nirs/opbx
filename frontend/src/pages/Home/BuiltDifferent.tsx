import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Brain, Sparkles, Bot } from 'lucide-react';

const pillars = [
  {
    icon: Brain,
    title: 'Human Architected',
    description:
      'Every feature starts with deep domain expertise. Our architects design the system with decades of telecom experience, ensuring scalability, security, and reliability.',
    points: [
      'Multi-tenant architecture designed for scale',
      'Enterprise-grade security from day one',
      'Real-time call state management',
    ],
  },
  {
    icon: Sparkles,
    title: 'Vibe Coded',
    description:
      'The implementation leverages cutting-edge AI assistance, allowing us to move fast without sacrificing quality. Every commit is reviewed, tested, and refined.',
    points: [
      'Rapid iteration with AI pair programming',
      'Comprehensive test coverage',
      'Type-safe, modern codebase',
    ],
  },
  {
    icon: Bot,
    title: 'AI Coding Ready',
    description:
      'OPBX is designed from the ground up to be AI-developer friendly. Clear documentation, consistent patterns, and well-structured code make it easy for AI assistants to help you customize.',
    points: [
      'Comprehensive inline documentation',
      'Consistent coding patterns throughout',
      'Modular, extensible architecture',
    ],
  },
];

export function BuiltDifferent() {
  return (
    <section className="bg-card/30 border-y border-border py-20 md:py-32">
      <div className="container mx-auto px-4">
        <div className="max-w-6xl mx-auto">
          <div className="text-center mb-16">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
              <span className="text-xl font-medium text-muted-foreground">Built Different</span>
            </div>
            <h2 className="text-3xl md:text-5xl font-bold mb-6 text-foreground">
              Human Architected, Vibe Coded
            </h2>
            <p className="text-xl text-muted-foreground max-w-3xl mx-auto leading-relaxed">
              OPBX represents a new way of building software. Every line of code is born from human
              vision and brought to life through AI collaboration. No shortcuts, no
              compromises—just pure engineering excellence.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
            {pillars.map((pillar, idx) => (
              <Card
                key={idx}
                className="bg-card border-border hover:border-primary/50 transition-all h-full"
              >
                <CardHeader>
                  <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                    <pillar.icon className="h-6 w-6 text-primary" />
                  </div>
                  <CardTitle className="text-3xl text-foreground">{pillar.title}</CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-muted-foreground mb-4">{pillar.description}</p>
                  <ul className="space-y-2 text-xl text-muted-foreground">
                    {pillar.points.map((point) => (
                      <li key={point} className="flex items-center gap-2">
                        <span className="text-primary">✓</span>
                        {point}
                      </li>
                    ))}
                  </ul>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
