import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowRight, Cloud, Server } from 'lucide-react';

export function DograhSpotlight() {
  return (
    <section id="dograh" className="container mx-auto px-4 py-20 md:py-32">
      <div className="max-w-6xl mx-auto">
        <div className="text-center mb-16">
          <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
            <Cloud className="h-4 w-4 text-primary" />
            <span className="text-sm font-medium text-primary">AI Voice Agents</span>
          </div>
          <h2 className="text-4xl md:text-5xl font-bold mb-6">AI Voice Agents for Every PBX</h2>
          <p className="text-xl text-muted-foreground max-w-2xl mx-auto leading-relaxed">
            Plug cloud-managed or self-hosted AI agents directly into your inbound call flow with
            Dograh.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
          <Card className="border-2 hover:border-primary/50 transition-all">
            <CardHeader>
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                <Cloud className="h-6 w-6 text-primary" />
              </div>
              <CardTitle className="text-2xl">Dograh Cloud</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground mb-4">
                Fully managed AI agents hosted by Dograh. Connect with a fixed endpoint and start
                taking calls in minutes.
              </p>
              <ul className="space-y-2 text-sm">
                <li className="flex items-center gap-2">
                  <span className="text-primary">✓</span>
                  Managed infrastructure
                </li>
                <li className="flex items-center gap-2">
                  <span className="text-primary">✓</span>
                  Fixed WebSocket endpoint
                </li>
                <li className="flex items-center gap-2">
                  <span className="text-primary">✓</span>
                  Fastest path to production
                </li>
              </ul>
            </CardContent>
          </Card>

          <Card className="border-2 hover:border-primary/50 transition-all">
            <CardHeader>
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                <Server className="h-6 w-6 text-primary" />
              </div>
              <CardTitle className="text-2xl">Dograh OSS</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground mb-4">
                Self-hosted AI agents where you control the endpoint and the data. Ideal for
                compliance and custom deployments.
              </p>
              <ul className="space-y-2 text-sm">
                <li className="flex items-center gap-2">
                  <span className="text-primary">✓</span>
                  Bring your own endpoint
                </li>
                <li className="flex items-center gap-2">
                  <span className="text-primary">✓</span>
                  Full data control
                </li>
                <li className="flex items-center gap-2">
                  <span className="text-primary">✓</span>
                  Open-source flexibility
                </li>
              </ul>
            </CardContent>
          </Card>
        </div>

        <div className="mt-12 text-center">
          <Button size="lg" asChild>
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
