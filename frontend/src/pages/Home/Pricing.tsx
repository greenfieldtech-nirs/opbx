import { Github } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';

const selfHostedFeatures = [
  'Unlimited users & extensions',
  'AI voice agents',
  'Smart call routing',
  'Ring groups & IVR',
  'Call tracking & analytics',
  'Business hours routing',
  'Community support',
];

const cloudFeatures = [
  'Hosted OPBX instance',
  'All self-hosted features included',
  'Automatic updates & backups',
  'Managed Cloudonix connectivity',
  'Priority community support',
  'No server maintenance',
];

export function Pricing() {
  return (
    <section id="pricing" className="py-20 md:py-28 bg-card/30 border-y border-border">
      <div className="container mx-auto px-4">
        <div className="max-w-5xl mx-auto text-center">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
            <span className="text-xl font-medium text-muted-foreground">Pricing</span>
          </div>
          <h2 className="text-3xl md:text-5xl font-bold mb-4 text-foreground">
            Simple, transparent pricing
          </h2>
          <p className="text-2xl text-muted-foreground mb-12">
            Start free. Choose self-hosted control or managed cloud convenience.
          </p>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-stretch">
            <Card className="border border-border bg-card overflow-hidden text-left flex flex-col h-full">
              <CardHeader className="border-b border-border pb-6">
                <CardTitle className="text-3xl font-bold text-foreground">Self-Hosted</CardTitle>
                <div className="mt-4 flex items-baseline gap-2">
                  <span className="text-5xl font-bold text-foreground">$0</span>
                  <span className="text-muted-foreground">/ month</span>
                </div>
                <p className="text-xl text-muted-foreground mt-2">
                  MIT licensed. Run on your own infrastructure.
                </p>
              </CardHeader>
              <CardContent className="p-8 flex-grow flex flex-col">
                <ul className="space-y-3 mb-8">
                  {selfHostedFeatures.map((feature) => (
                    <li key={feature} className="flex items-center gap-3 text-xl text-muted-foreground">
                      <span className="text-primary">✓</span>
                      {feature}
                    </li>
                  ))}
                </ul>
                <div className="mt-auto">
                  <Button size="lg" asChild className="w-full bg-primary text-primary-foreground hover:bg-primary/90">
                    <a
                      href="https://github.com/greenfieldtech-nirs/OPBX"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <Github className="mr-2 h-5 w-5" />
                      View on GitHub
                    </a>
                  </Button>
                  <p className="text-xl text-muted-foreground mt-4">
                    Cloudonix usage billed separately.
                  </p>
                </div>
              </CardContent>
            </Card>

            <Card className="border border-border bg-card overflow-hidden text-left relative flex flex-col h-full">
              <div className="absolute top-4 right-4">
                <Badge className="bg-primary text-primary-foreground hover:bg-primary">
                  Limited Offer
                </Badge>
              </div>
              <CardHeader className="border-b border-border pb-6">
                <CardTitle className="text-3xl font-bold text-foreground">Cloud Service</CardTitle>
                <div className="mt-4 flex items-baseline gap-2">
                  <span className="text-5xl font-bold text-foreground">$0</span>
                  <span className="text-muted-foreground">/ month</span>
                </div>
                <p className="text-xl text-muted-foreground mt-2">
                  Managed OPBX instance, no ops overhead.
                </p>
              </CardHeader>
              <CardContent className="p-8 flex-grow flex flex-col">
                <ul className="space-y-3 mb-8">
                  {cloudFeatures.map((feature) => (
                    <li key={feature} className="flex items-center gap-3 text-xl text-muted-foreground">
                      <span className="text-primary">✓</span>
                      {feature}
                    </li>
                  ))}
                </ul>
                <div className="mt-auto">
                  <Button size="lg" asChild className="w-full bg-primary text-primary-foreground hover:bg-primary/90">
                    <Link to="/ui/register">Start Free Trial</Link>
                  </Button>
                  <p className="text-xl text-muted-foreground mt-4">
                    Cloudonix usage billed separately.
                  </p>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </section>
  );
}
