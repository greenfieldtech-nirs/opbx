/**
 * OpBX Public Homepage
 * 
 * Marketing landing page for the OpBX project
 */

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Phone,
  Users,
  Radio,
  Clock,
  BarChart3,
  Settings,
  Zap,
  Shield,
  Github,
  ArrowRight,
  CheckCircle2,
  Globe,
  Server,
} from 'lucide-react';
import { Link } from 'react-router-dom';

export default function Home() {
  const features = [
    {
      icon: Users,
      title: 'Multi-Tenant',
      description: 'Isolated organizations with complete data separation and security',
    },
    {
      icon: Phone,
      title: 'Smart Call Routing',
      description: 'Route inbound calls to extensions, ring groups, or IVR menus',
    },
    {
      icon: Radio,
      title: 'Ring Groups',
      description: 'Simultaneous or sequential ringing across multiple extensions',
    },
    {
      icon: Settings,
      title: 'IVR Menus',
      description: 'Interactive voice response with custom routing logic',
    },
    {
      icon: Clock,
      title: 'Business Hours',
      description: 'Route calls differently based on time of day and schedules',
    },
    {
      icon: BarChart3,
      title: 'Real-Time Monitoring',
      description: 'Live call dashboard with active session tracking',
    },
    {
      icon: Zap,
      title: 'Fast & Reliable',
      description: 'Built on Laravel and React with Redis for performance',
    },
    {
      icon: Shield,
      title: 'Secure by Default',
      description: 'Role-based access control and audit logging built-in',
    },
  ];

  const steps = [
    {
      number: '1',
      title: 'Sign Up & Configure',
      description: 'Create your organization and set up users, extensions, and routing rules',
    },
    {
      number: '2',
      title: 'Connect Cloudonix',
      description: 'Link your Cloudonix account and configure your DID numbers',
    },
    {
      number: '3',
      title: 'Start Routing Calls',
      description: 'Your PBX is live! Monitor calls in real-time and adjust as needed',
    },
  ];

  return (
    <div className="min-h-screen bg-background">
      {/* Header */}
      <header className="border-b">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Phone className="h-6 w-6 text-primary" />
            <span className="text-xl font-bold">OpBX</span>
          </div>
          <div className="flex items-center gap-4">
            <Button variant="ghost" asChild>
              <Link to="/ui/login">Login</Link>
            </Button>
            <Button asChild>
              <Link to="/ui/register">Get Started</Link>
            </Button>
          </div>
        </div>
      </header>

      {/* Hero Section */}
      <section className="container mx-auto px-4 py-20 text-center">
        <div className="max-w-3xl mx-auto">
          <h1 className="text-5xl font-bold tracking-tight mb-6">
            Open Source Business PBX
            <br />
            <span className="text-primary">Built on Cloudonix</span>
          </h1>
          <p className="text-xl text-muted-foreground mb-8">
            A modern, containerized PBX platform for managing inbound calls, extensions,
            ring groups, and IVR menus. Self-hosted and fully open source.
          </p>
          <div className="flex gap-4 justify-center">
            <Button size="lg" asChild>
              <Link to="/ui/register">
                Get Started Free
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
            <Button size="lg" variant="outline" asChild>
              <a href="https://github.com/cloudonix/opbx" target="_blank" rel="noopener noreferrer">
                <Github className="mr-2 h-4 w-4" />
                View on GitHub
              </a>
            </Button>
          </div>
        </div>
      </section>

      {/* Features Grid */}
      <section className="container mx-auto px-4 py-20">
        <div className="text-center mb-12">
          <h2 className="text-3xl font-bold mb-4">Everything You Need</h2>
          <p className="text-muted-foreground">
            A complete business PBX solution with powerful features
          </p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {features.map((feature, idx) => (
            <Card key={idx}>
              <CardHeader>
                <feature.icon className="h-10 w-10 text-primary mb-2" />
                <CardTitle>{feature.title}</CardTitle>
              </CardHeader>
              <CardContent>
                <CardDescription>{feature.description}</CardDescription>
              </CardContent>
            </Card>
          ))}
        </div>
      </section>

      {/* How It Works */}
      <section className="bg-muted/50 py-20">
        <div className="container mx-auto px-4">
          <div className="text-center mb-12">
            <h2 className="text-3xl font-bold mb-4">How It Works</h2>
            <p className="text-muted-foreground">
              Get started with OpBX in three simple steps
            </p>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto">
            {steps.map((step, idx) => (
              <div key={idx} className="text-center">
                <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary text-primary-foreground text-2xl font-bold mb-4">
                  {step.number}
                </div>
                <h3 className="text-xl font-semibold mb-2">{step.title}</h3>
                <p className="text-muted-foreground">{step.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Open Source Section */}
      <section className="container mx-auto px-4 py-20">
        <div className="max-w-4xl mx-auto">
          <Card className="border-2">
            <CardHeader className="text-center">
              <div className="flex justify-center mb-4">
                <Github className="h-16 w-16 text-primary" />
              </div>
              <CardTitle className="text-3xl">Fully Open Source</CardTitle>
              <CardDescription className="text-lg">
                OpBX is licensed under MIT. Self-host on your infrastructure with Docker.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="flex items-start gap-3">
                  <CheckCircle2 className="h-5 w-5 text-primary mt-0.5" />
                  <div>
                    <h4 className="font-semibold mb-1">Docker Ready</h4>
                    <p className="text-sm text-muted-foreground">
                      One-command deployment with docker-compose
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <CheckCircle2 className="h-5 w-5 text-primary mt-0.5" />
                  <div>
                    <h4 className="font-semibold mb-1">Modern Stack</h4>
                    <p className="text-sm text-muted-foreground">
                      Laravel backend, React frontend, MySQL + Redis
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <CheckCircle2 className="h-5 w-5 text-primary mt-0.5" />
                  <div>
                    <h4 className="font-semibold mb-1">Self-Hosted</h4>
                    <p className="text-sm text-muted-foreground">
                      Full control over your data and infrastructure
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <CheckCircle2 className="h-5 w-5 text-primary mt-0.5" />
                  <div>
                    <h4 className="font-semibold mb-1">Active Development</h4>
                    <p className="text-sm text-muted-foreground">
                      Regular updates and community contributions
                    </p>
                  </div>
                </div>
              </div>
              <div className="mt-8 text-center">
                <Button variant="outline" size="lg" asChild>
                  <a href="https://github.com/cloudonix/opbx" target="_blank" rel="noopener noreferrer">
                    <Github className="mr-2 h-4 w-4" />
                    Star on GitHub
                  </a>
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      </section>

      {/* CTA Section */}
      <section className="bg-primary text-primary-foreground py-20">
        <div className="container mx-auto px-4 text-center">
          <h2 className="text-3xl font-bold mb-4">Ready to Get Started?</h2>
          <p className="text-lg mb-8 opacity-90">
            Create your organization and start routing calls in minutes
          </p>
          <Button size="lg" variant="secondary" asChild>
            <Link to="/ui/register">
              Sign Up Now
              <ArrowRight className="ml-2 h-4 w-4" />
            </Link>
          </Button>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t py-12">
        <div className="container mx-auto px-4">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
            <div>
              <div className="flex items-center gap-2 mb-4">
                <Phone className="h-5 w-5 text-primary" />
                <span className="font-bold">OpBX</span>
              </div>
              <p className="text-sm text-muted-foreground">
                Open source business PBX built on Cloudonix CPaaS platform
              </p>
            </div>
            <div>
              <h4 className="font-semibold mb-3">Product</h4>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li><Link to="/ui/register" className="hover:text-foreground">Get Started</Link></li>
                <li><Link to="/ui/login" className="hover:text-foreground">Login</Link></li>
                <li><a href="https://github.com/cloudonix/opbx" className="hover:text-foreground">Documentation</a></li>
              </ul>
            </div>
            <div>
              <h4 className="font-semibold mb-3">Resources</h4>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li><a href="https://developers.cloudonix.com" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">Cloudonix Docs</a></li>
                <li><a href="https://github.com/cloudonix/opbx" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">GitHub</a></li>
                <li><a href="https://github.com/cloudonix/opbx/issues" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">Report Issue</a></li>
              </ul>
            </div>
            <div>
              <h4 className="font-semibold mb-3">Community</h4>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li><a href="https://github.com/cloudonix/opbx/discussions" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">Discussions</a></li>
                <li><a href="https://github.com/cloudonix/opbx/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">Contributing</a></li>
                <li><a href="https://github.com/cloudonix/opbx/blob/main/LICENSE" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">License (MIT)</a></li>
              </ul>
            </div>
          </div>
          <div className="border-t mt-8 pt-8 text-center text-sm text-muted-foreground">
            <p>© {new Date().getFullYear()} OpBX. Open source under MIT License.</p>
          </div>
        </div>
      </footer>
    </div>
  );
}
