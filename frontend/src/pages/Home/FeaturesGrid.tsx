import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  BarChart3,
  Bot,
  Clock,
  Mic,
  Phone,
  PhoneCall,
  Radio,
  Settings,
  Shield,
  Users,
  Workflow,
  Zap,
} from 'lucide-react';

const features = [
  {
    icon: Bot,
    title: 'AI Voice Agents',
    description: 'Cloud and OSS Dograh integration plus generic AI assistant support.',
  },
  {
    icon: Phone,
    title: 'Auto Dialer',
    description: 'Outbound campaign manager with distribution lists and scheduling.',
  },
  {
    icon: BarChart3,
    title: 'Call Tracking',
    description: 'Campaign tracking, DNI snippets, and analytics.',
  },
  {
    icon: Workflow,
    title: 'AI Load Balancers',
    description: 'Distribute inbound calls across AI assistants.',
  },
  {
    icon: PhoneCall,
    title: 'Smart Call Routing',
    description: 'Route calls to extensions, ring groups, IVR, or AI assistants.',
  },
  {
    icon: Users,
    title: 'Ring Groups',
    description: 'Simultaneous, round-robin, and weighted ringing strategies.',
  },
  {
    icon: Mic,
    title: 'IVR Menus',
    description: 'Interactive voice response with custom routing logic.',
  },
  {
    icon: Clock,
    title: 'Business Hours',
    description: 'Time-of-day, holiday, and custom schedule routing.',
  },
  {
    icon: Radio,
    title: 'Real-Time Monitoring',
    description: 'Live call dashboard with presence and session updates.',
  },
  {
    icon: Settings,
    title: 'Call Recording',
    description: 'Automatic recording with secure storage and compliance.',
  },
  {
    icon: Shield,
    title: 'Enterprise Security',
    description: 'RBAC, multi-tenant isolation, and audit logging.',
  },
  {
    icon: Zap,
    title: 'Lightning Fast',
    description: 'Laravel + React with Redis for high-performance call processing.',
  },
];

export function FeaturesGrid() {
  return (
    <section id="features" className="container mx-auto px-4 py-20 md:py-32">
      <div className="text-center mb-16">
        <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
          <Zap className="h-4 w-4 text-primary" />
          <span className="text-sm font-medium text-primary">Features</span>
        </div>
        <h2 className="text-4xl md:text-5xl font-bold mb-4">Everything You Need</h2>
        <p className="text-lg text-muted-foreground max-w-3xl mx-auto">
          A complete business PBX solution with powerful features that scale with your organization
        </p>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {features.map((feature, idx) => (
          <Card
            key={idx}
            className="relative group hover:shadow-lg transition-all duration-300 hover:-translate-y-1 border-2 hover:border-primary/50"
          >
            <CardHeader>
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4 group-hover:bg-primary/20 transition-colors">
                <feature.icon className="h-6 w-6 text-primary" />
              </div>
              <CardTitle className="text-lg">{feature.title}</CardTitle>
            </CardHeader>
            <CardContent>
              <CardDescription className="text-base">{feature.description}</CardDescription>
            </CardContent>
          </Card>
        ))}
      </div>
    </section>
  );
}
