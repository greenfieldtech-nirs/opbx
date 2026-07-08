import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowRight, Check, Settings, Code, Shield } from 'lucide-react';

const highlights = [
  {
    icon: Settings,
    title: 'Easy Configuration',
    description:
      'Set up extensions, ring groups, and IVR menus through an intuitive web interface. No command-line expertise required.',
    points: ['Web-based setup wizard', 'Visual call flow designer', 'Real-time call management'],
  },
  {
    icon: Code,
    title: 'Developer Friendly',
    description:
      'Built with Laravel and React, OPBX offers a modern API and webhook architecture. It makes it easy to customize.',
    points: ['RESTful API', 'Webhook events', 'Modern React UI'],
  },
  {
    icon: Shield,
    title: 'Self-Hosted & Secure',
    description:
      'Keep your data on your own infrastructure. OPBX supports multi-tenant organizations with enterprise-grade security.',
    points: ['Multi-tenant isolation', 'RBAC and audit logging', 'Enterprise security'],
  },
];

export function WhatIsOPBX() {
  return (
    <section id="what-is-opbx" className="container mx-auto px-4 py-20 md:py-32">
      <div className="max-w-6xl mx-auto">
        <div className="text-center mb-16">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
            <span className="text-xl font-medium text-muted-foreground">Open Source PBX</span>
          </div>
          <h2 className="text-3xl md:text-5xl font-bold mb-6 text-foreground">What is OPBX?</h2>
          <p className="text-xl text-muted-foreground max-w-3xl mx-auto leading-relaxed">
            OPBX is an open-source business PBX platform that transforms how organizations handle
            voice communications. Built on the Cloudonix CPaaS, OPBX eliminates the complexities of
            managing VoIP infrastructure while giving you complete control over your phone system.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
          {highlights.map((item, idx) => (
            <Card
              key={idx}
              className="bg-card border-border hover:border-primary/50 transition-all h-full"
            >
              <CardHeader>
                <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                  <item.icon className="h-6 w-6 text-primary" />
                </div>
                <CardTitle className="text-3xl text-foreground">{item.title}</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-muted-foreground mb-4">{item.description}</p>
                <ul className="space-y-2 text-xl text-muted-foreground">
                  {item.points.map((point) => (
                    <li key={point} className="flex items-center gap-2">
                      <Check className="h-5 w-5 text-primary" />
                      {point}
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="text-center">
          <Button size="lg" asChild className="bg-primary text-primary-foreground hover:bg-primary/90">
            <Link to="/ui/register">
              Get Started Free
              <ArrowRight className="ml-2 h-5 w-5" />
            </Link>
          </Button>
        </div>
      </div>
    </section>
  );
}
