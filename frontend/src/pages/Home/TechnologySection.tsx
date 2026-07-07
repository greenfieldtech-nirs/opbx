import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Cloud, Code, Github, Lock, Phone, Server, Shield, Zap } from 'lucide-react';

const highlights = [
  {
    icon: Server,
    title: 'Docker Ready',
    description: 'One-command deployment with docker-compose. Production-ready containerization.',
  },
  {
    icon: Code,
    title: 'Modern Stack',
    description: 'Laravel backend, React frontend, MySQL + Redis for optimal performance.',
  },
  {
    icon: Lock,
    title: 'Self-Hosted',
    description: 'Full control over your data and infrastructure. No vendor lock-in.',
  },
  {
    icon: Github,
    title: 'Open Source',
    description: 'MIT licensed. Active development with community contributions.',
  },
];

const cloudonixBenefits = [
  {
    icon: Phone,
    title: 'VoIP Infrastructure',
    description: 'Cloudonix handles all SIP, media, and telephony operations.',
  },
  {
    icon: Zap,
    title: 'Real-Time CXML',
    description: 'Dynamic call routing with CXML responses in real-time.',
  },
  {
    icon: Shield,
    title: 'Enterprise Grade',
    description: 'Carrier-grade reliability with global phone number support.',
  },
];

export function TechnologySection() {
  return (
    <section className="bg-muted/30 py-20 md:py-32">
      <div className="container mx-auto px-4">
        <div className="max-w-6xl mx-auto">
          <div className="text-center mb-16">
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
              <Code className="h-4 w-4 text-primary" />
              <span className="text-sm font-medium text-primary">Technology</span>
            </div>
            <h2 className="text-4xl md:text-5xl font-bold mb-4">Built for Developers</h2>
            <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
              Modern architecture, production-ready deployment, complete control
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-16">
            {highlights.map((highlight, idx) => (
              <Card
                key={idx}
                className="bg-background/50 backdrop-blur border-2 hover:border-primary/50 transition-all"
              >
                <CardHeader>
                  <highlight.icon className="h-10 w-10 text-primary mb-4" />
                  <CardTitle className="text-lg">{highlight.title}</CardTitle>
                </CardHeader>
                <CardContent>
                  <CardDescription className="text-base">
                    {highlight.description}
                  </CardDescription>
                </CardContent>
              </Card>
            ))}
          </div>

          <div className="p-8 rounded-2xl bg-gradient-to-br from-primary/10 to-primary/5 border-2 border-primary/20">
            <div className="text-center mb-8">
              <h3 className="text-3xl font-bold mb-3">Powered by Cloudonix</h3>
              <p className="text-muted-foreground text-lg max-w-2xl mx-auto">
                OPBX integrates seamlessly with the Cloudonix CPaaS platform for enterprise-grade
                telephony infrastructure
              </p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {cloudonixBenefits.map((item, idx) => (
                <Card key={idx} className="border-2 bg-background/70">
                  <CardHeader>
                    <item.icon className="h-10 w-10 text-primary mx-auto mb-4" />
                    <CardTitle className="text-lg text-center">{item.title}</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <CardDescription className="text-base text-center">
                      {item.description}
                    </CardDescription>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
