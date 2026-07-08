import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowRight, Check, Cloud, Server } from 'lucide-react';

const providers = [
  'VAPI',
  'Retell',
  '11Labs',
  'x.AI',
  'Synthflow',
  'Dasha',
  'Superdash',
  'Ultravox',
  'Deepvox',
  'RelayHawk',
  'Voicehub',
  'Fonio',
  'Sigmamind',
  'Modon',
  'Puretalk',
  'Millis',
  'Rapida',
  'Assembly',
  'Smallest',
  'Call2Me',
  'Revring',
];

export function DograhSpotlight() {
  return (
    <section id="dograh" className="container mx-auto px-4 py-20 md:py-32">
      <div className="max-w-6xl mx-auto">
        <div className="text-center mb-16">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
            <Cloud className="h-4 w-4 text-primary" />
            <span className="text-xl font-medium text-muted-foreground">
              AI Voice Agents
            </span>
          </div>
          <h2 className="text-3xl md:text-5xl font-bold mb-6 text-foreground">
            20+ AI Voice Agent Platforms Directly Connected
          </h2>
          <p className="text-xl text-muted-foreground max-w-3xl mx-auto leading-relaxed">
            Plug cloud-managed or self-hosted AI agents directly into the OPBX phone system, using
            Dograh, xAI, Rapida, VAPI, Retell, 11Labs, and others.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
          <Card className="bg-card border-border hover:border-primary/50 transition-all h-full">
            <CardHeader>
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                <Cloud className="h-6 w-6 text-primary" />
              </div>
              <CardTitle className="text-3xl text-foreground">Dograh Cloud</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground mb-4">
                Fully managed AI agents hosted by Dograh. Connect with a fixed endpoint and start
                taking calls in minutes.
              </p>
              <ul className="space-y-2 text-xl text-muted-foreground">
                <li className="flex items-center gap-2">
                  <Check className="h-5 w-5 text-primary" />
                  Managed infrastructure
                </li>
                <li className="flex items-center gap-2">
                  <Check className="h-5 w-5 text-primary" />
                  Fixed WebSocket endpoint
                </li>
                <li className="flex items-center gap-2">
                  <Check className="h-5 w-5 text-primary" />
                  Fastest path to production
                </li>
              </ul>
            </CardContent>
          </Card>

          <Card className="bg-card border-border hover:border-primary/50 transition-all h-full">
            <CardHeader>
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                <Server className="h-6 w-6 text-primary" />
              </div>
              <CardTitle className="text-3xl text-foreground">Dograh OSS</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground mb-4">
                Self-hosted AI agents where you control the endpoint and the data. Ideal for
                compliance and custom deployments.
              </p>
              <ul className="space-y-2 text-xl text-muted-foreground">
                <li className="flex items-center gap-2">
                  <Check className="h-5 w-5 text-primary" />
                  Bring your own endpoint
                </li>
                <li className="flex items-center gap-2">
                  <Check className="h-5 w-5 text-primary" />
                  Full data control
                </li>
                <li className="flex items-center gap-2">
                  <Check className="h-5 w-5 text-primary" />
                  Open-source flexibility
                </li>
              </ul>
            </CardContent>
          </Card>
        </div>

        <div className="text-center">
          <p className="text-xl text-muted-foreground mb-4">
            Or use any of these platforms directly:
          </p>
          <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-4 text-center">
            {providers.map((provider) => (
              <span
                key={provider}
                className="text-xl font-medium text-foreground"
              >
                {provider}
              </span>
            ))}
          </div>
        </div>

        <div className="mt-12 text-center">
          <Button size="lg" asChild className="bg-primary text-primary-foreground hover:bg-primary/90">
            <Link to="/ui/register">
              Get Started
              <ArrowRight className="ml-2 h-5 w-5" />
            </Link>
          </Button>
        </div>
      </div>
    </section>
  );
}
