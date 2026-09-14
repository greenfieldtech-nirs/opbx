import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  BarChart3,
  Bot,
  BrainCircuit,
  Clock,
  KeyRound,
  Mic,
  MonitorSmartphone,
  Network,
  Phone,
  PhoneCall,
  Radio,
  Settings,
  Shield,
  Users,
  Workflow,
  Zap,
} from 'lucide-react';

type FeatureStatus = 'production' | 'development';

const features: { icon: typeof Bot; title: string; description: string; status: FeatureStatus }[] = [
  {
    icon: Bot,
    title: 'AI Voice Agents',
    description: 'Cloud and OSS Dograh integration plus generic AI assistant support.',
    status: 'production',
  },
  {
    icon: Phone,
    title: 'Auto Dialer',
    description: 'Outbound campaign manager with distribution lists and scheduling.',
    status: 'production',
  },
  {
    icon: BarChart3,
    title: 'Call Tracking',
    description: 'Campaign tracking, DNI snippets, and analytics.',
    status: 'development',
  },
  {
    icon: Workflow,
    title: 'AI Load Balancers',
    description: 'Distribute inbound calls across AI assistants.',
    status: 'production',
  },
  {
    icon: PhoneCall,
    title: 'Smart Call Routing',
    description: 'Route calls to extensions, ring groups, IVR, or AI assistants.',
    status: 'production',
  },
  {
    icon: Users,
    title: 'Ring Groups',
    description: 'Simultaneous, round-robin, and weighted ringing strategies.',
    status: 'production',
  },
  {
    icon: Mic,
    title: 'IVR Menus',
    description: 'Interactive voice response with custom routing logic.',
    status: 'production',
  },
  {
    icon: Clock,
    title: 'Business Hours',
    description: 'Time-of-day, holiday, and custom schedule routing.',
    status: 'production',
  },
  {
    icon: Radio,
    title: 'Real-Time Monitoring',
    description: 'Live call dashboard with presence and session updates.',
    status: 'production',
  },
  {
    icon: Settings,
    title: 'Call Recording',
    description: 'Automatic recording with secure storage and compliance.',
    status: 'production',
  },
  {
    icon: Network,
    title: 'Trunk Management',
    description: 'Create and manage inbound and outbound SIP trunks, with a built-in configuration cheatsheet.',
    status: 'production',
  },
  {
    icon: MonitorSmartphone,
    title: 'Web Phone',
    description: 'Browser-based softphone with dialer, call log, and supervisor coaching.',
    status: 'production',
  },
  {
    icon: BrainCircuit,
    title: 'MCP Server for AI Agents',
    description: '112 tools let Claude, Cursor, and other AI agents manage the PBX via the Model Context Protocol.',
    status: 'development',
  },
  {
    icon: KeyRound,
    title: 'Scoped API Keys',
    description: 'Fine-grained, per-resource API keys for safe automation and integrations.',
    status: 'production',
  },
  {
    icon: Shield,
    title: 'Enterprise Security',
    description: 'RBAC, multi-tenant isolation, inbound blacklist, outbound whitelist, and audit logging.',
    status: 'production',
  },
];

export function FeaturesGrid() {
  return (
    <section id="features" className="container mx-auto px-4 py-20 md:py-32">
      <div className="text-center mb-16">
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 mb-6">
          <Zap className="h-4 w-4 text-primary" />
          <span className="text-xl font-medium text-muted-foreground">Features</span>
        </div>
        <h2 className="text-3xl md:text-5xl font-bold mb-4 text-foreground">Everything You Need</h2>
        <p className="text-2xl text-muted-foreground max-w-3xl mx-auto">
          A complete business PBX solution with powerful features that scale with your organization
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {features.map((feature, idx) => (
          <Card
            key={idx}
            className="group bg-card border-border hover:border-primary/50 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
          >
            <CardHeader>
              <div className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4 group-hover:bg-primary/20 transition-colors">
                <feature.icon className="h-6 w-6 text-primary" />
              </div>
              <CardTitle className="text-2xl text-foreground flex items-center gap-2 flex-wrap">
                {feature.title}
                <span
                  className={
                    feature.status === 'development'
                      ? 'inline-flex items-center rounded-full border border-amber-400/30 bg-amber-400/10 px-2 py-0.5 text-xs font-medium text-amber-400'
                      : 'inline-flex items-center rounded-full border border-green-400/30 bg-green-400/10 px-2 py-0.5 text-xs font-medium text-green-400'
                  }
                >
                  {feature.status === 'development' ? 'Under Development' : 'Production Ready'}
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <CardDescription className="text-base text-muted-foreground">
                {feature.description}
              </CardDescription>
            </CardContent>
          </Card>
        ))}
      </div>
    </section>
  );
}
