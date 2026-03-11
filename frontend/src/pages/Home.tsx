/**
 * OPBX Public Homepage
 *
 * Marketing landing page for the OPBX project
 * Design inspired by modern SaaS aesthetics with dark theme and gradient accents
 */

import {Button} from '@/components/ui/button';
import {Card, CardContent, CardDescription, CardHeader, CardTitle} from '@/components/ui/card';
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
    Mic,
    PhoneCall,
    Workflow,
    Server,
    Lock,
    Code,
    ChevronDown,
    Play,
    HelpCircle,
} from 'lucide-react';
import {Link} from 'react-router-dom';
import OPBXLogo from '@/assets/opbx_logo.png';
import {useState} from 'react';

export default function Home() {
    const [openFaq, setOpenFaq] = useState<number | null>(null);

    const features = [
        {
            icon: PhoneCall,
            title: 'Smart Call Routing',
            description: 'Route inbound calls to extensions, ring groups, or IVR menus with intelligent logic',
        },
        {
            icon: Users,
            title: 'Ring Groups',
            description: 'Simultaneous or sequential ringing across multiple extensions with advanced strategies',
        },
        {
            icon: Mic,
            title: 'IVR Menus',
            description: 'Interactive voice response with custom routing logic and AI assistant integration',
        },
        {
            icon: Clock,
            title: 'Business Hours',
            description: 'Route calls differently based on time of day, holidays, and custom schedules',
        },
        {
            icon: BarChart3,
            title: 'Real-Time Monitoring',
            description: 'Live call dashboard with active session tracking and presence updates',
        },
        {
            icon: Workflow,
            title: 'Call Recording',
            description: 'Automatic call recording with secure storage and compliance features',
        },
        {
            icon: Shield,
            title: 'Enterprise Security',
            description: 'Role-based access control, audit logging, and multi-tenant isolation',
        },
        {
            icon: Zap,
            title: 'Lightning Fast',
            description: 'Built on Laravel and React with Redis for high-performance call processing',
        },
    ];

    const technicalHighlights = [
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

    const faqs = [
        {
            question: 'What is OPBX?',
            answer: 'OPBX is an open-source business PBX (Private Branch Exchange) platform built on top of the Cloudonix CPaaS platform. It handles call routing, IVR menus, ring groups, and business communications without requiring you to manage VoIP infrastructure.',
        },
        {
            question: 'How does OPBX work with Cloudonix?',
            answer: 'OPBX integrates with Cloudonix to handle all VoIP and telephony operations. Cloudonix manages the actual call infrastructure, while OPBX provides the configuration interface and runtime routing decisions. This separation allows you to focus on business logic without managing SIP servers.',
        },
        {
            question: 'Is OPBX really free?',
            answer: 'Yes! OPBX is fully open source under the MIT license. You can self-host it on your own infrastructure at no cost. You will need a Cloudonix account for telephony services, which has its own pricing structure.',
        },
        {
            question: 'What features are included?',
            answer: 'OPBX v1 includes multi-tenant organizations, user extensions, DID number mapping, inbound call routing (direct, ring groups, IVR), business hours routing, call logs, real-time call presence, and a complete admin UI.',
        },
        {
            question: 'Can I use OPBX for outbound calling?',
            answer: 'The current version (v1) focuses on inbound call routing and management. Outbound calling features are planned for future releases.',
        },
        {
            question: 'How do I get started?',
            answer: 'Sign up for an account, configure your organization and extensions, connect your Cloudonix account with API credentials, map your DID numbers, and start routing calls. The entire setup takes just minutes.',
        },
    ];

    const companyLogos = [
        {name: 'Laravel', icon: Code},
        {name: 'React', icon: Code},
        {name: 'Docker', icon: Server},
        {name: 'MySQL', icon: Server},
        {name: 'Redis', icon: Zap},
        {name: 'Cloudonix', icon: Phone},
    ];

    return (
        <div className="min-h-screen bg-background">
            {/* Header */}
            <header
                className="border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 sticky top-0 z-50">
                <div className="container mx-auto px-4 py-4 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <img src={OPBXLogo} alt="OPBX Logo" className="h-32 w-auto"/>
                    </div>
                    <nav className="hidden md:flex items-center gap-6">
                        <a href="#features" className="text-lg font-medium hover:text-primary transition-colors">
                            Features
                        </a>
                        <a href="#how-it-works" className="text-lg font-medium hover:text-primary transition-colors">
                            How It Works
                        </a>
                        <a href="#faq" className="text-lg font-medium hover:text-primary transition-colors">
                            FAQ
                        </a>
                        <a
                            href="https://developers.cloudonix.com/opbx"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-lg font-medium hover:text-primary transition-colors"
                        >
                            Docs
                        </a>
                    </nav>
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
            <section className="relative overflow-hidden bg-gradient-to-br from-background via-background to-primary/5">
                <div className="absolute inset-0 bg-grid-white/[0.02] bg-[size:50px_50px]"/>
                <div className="container mx-auto px-4 py-24 md:py-32 relative">
                    <div className="max-w-4xl mx-auto text-center">
                        <div
                            className="inline-flex items-center gap-2 px-3 py-1 rounded-full border bg-background/50 backdrop-blur mb-6">
              <span className="relative flex h-2 w-2">
                <span
                    className="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
              </span>
                            <span className="text-xs font-medium">AI-First Open Source PBX Platform</span>
                        </div>
                        <h1 className="text-5xl md:text-6xl font-bold tracking-tight mb-6 bg-clip-text text-transparent bg-gradient-to-r from-foreground to-foreground/70">
                            #1 Open Source Business PBX
                            <br/>
                            <span className="bg-clip-text text-transparent bg-gradient-to-r from-primary to-primary/60">
                Built on Cloudonix
              </span>
                        </h1>
                        <p className="text-xl md:text-2xl text-muted-foreground mb-10 max-w-3xl mx-auto">
                            Build, deploy, and manage your business phone system with ease. Modern containerized PBX
                            that scales
                            from startup to enterprise.
                        </p>
                        <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
                            <Button size="lg" asChild className="text-lg h-12 px-8">
                                <Link to="/ui/register">
                                    Try For Free
                                    <ArrowRight className="ml-2 h-5 w-5"/>
                                </Link>
                            </Button>
                            <Button size="lg" variant="outline" asChild className="text-lg h-12 px-8">
                                <a href="https://github.com/greenfieldtech-nirs/OPBX" target="_blank"
                                   rel="noopener noreferrer">
                                    <Github className="mr-2 h-5 w-5"/>
                                    View on GitHub
                                </a>
                            </Button>
                            <Button size="lg" variant="outline" asChild className="text-lg h-12 px-8 bg-[#5865F2] text-white border-[#5865F2] hover:bg-[#4752C4] hover:border-[#4752C4]">
                                <a href="https://discord.gg/etCGgNh9VV" target="_blank" rel="noopener noreferrer">
                                    <svg className="mr-2 h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                                    </svg>
                                    Join Discord
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Scroll indicator */}
                <div className="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
                    <ChevronDown className="h-6 w-6 text-muted-foreground"/>
                </div>
            </section>

            {/* Animated Logo Carousel */}
            {
                /*
          <section className="border-y bg-muted/30 py-12 overflow-hidden">
            <div className="container mx-auto px-4">
              <p className="text-center text-sm text-muted-foreground mb-8">BUILT WITH MODERN TECHNOLOGIES</p>
              <div className="relative">
                <div className="flex gap-12 animate-scroll">
                  {[...companyLogos, ...companyLogos].map((logo, idx) => (
                    <div
                      key={idx}
                      className="flex items-center justify-center gap-2 px-6 py-2 rounded-lg bg-background/50 border whitespace-nowrap"
                    >
                      <logo.icon className="h-5 w-5 text-muted-foreground" />
                      <span className="text-sm font-medium text-muted-foreground">{logo.name}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </section>

                 */
            }

            {/* What is OPBX? */}
            <section className="container mx-auto px-4 py-16 md:py-24">
                <div className="max-w-4xl mx-auto text-center">
                    <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
                        <Phone className="h-4 w-4 text-primary" />
                        <span className="text-sm font-medium text-primary">Open Source PBX</span>
                    </div>
                    <h2 className="text-4xl md:text-5xl font-bold mb-6">What is OPBX?</h2>
                    <p className="text-xl text-muted-foreground leading-relaxed mb-8">
                        OPBX is an <span className="text-foreground font-semibold">open-source business PBX platform</span> that transforms how organizations handle voice communications. Built on top of the Cloudonix CPaaS, OPBX eliminates the complexity of managing VoIP infrastructure while giving you complete control over your phone system.
                    </p>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
                        <div className="p-6 rounded-xl bg-muted/50 border">
                            <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                                <Settings className="h-5 w-5 text-primary" />
                            </div>
                            <h3 className="font-semibold mb-2">Easy Configuration</h3>
                            <p className="text-sm text-muted-foreground">Set up extensions, ring groups, and IVR menus through an intuitive web interface—no command line required.</p>
                        </div>
                        <div className="p-6 rounded-xl bg-muted/50 border">
                            <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                                <Code className="h-5 w-5 text-primary" />
                            </div>
                            <h3 className="font-semibold mb-2">Developer Friendly</h3>
                            <p className="text-sm text-muted-foreground">Built with Laravel and React, OPBX is fully customizable. Fork it, extend it, make it yours.</p>
                        </div>
                        <div className="p-6 rounded-xl bg-muted/50 border">
                            <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
                                <Shield className="h-5 w-5 text-primary" />
                            </div>
                            <h3 className="font-semibold mb-2">Self-Hosted & Secure</h3>
                            <p className="text-sm text-muted-foreground">Deploy on your own infrastructure. Your data stays under your control with enterprise-grade security.</p>
                        </div>
                    </div>
                    <div className="mt-10">
                        <Button size="lg" asChild>
                            <Link to="/ui/register">
                                Get Started Free
                                <ArrowRight className="ml-2 h-5 w-5"/>
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            {/* Human Architected, Vibe Coded */}
            <section className="bg-gradient-to-r from-primary/5 via-background to-primary/5 py-16 md:py-24 border-y">
                <div className="container mx-auto px-4">
                    <div className="text-center mb-12">
                        <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
                            <Zap className="h-4 w-4 text-primary" />
                            <span className="text-sm font-medium text-primary">Built Different</span>
                        </div>
                        <h2 className="text-4xl md:text-5xl font-bold mb-6">Human Architected, Vibe Coded</h2>
                        <p className="text-xl text-muted-foreground leading-relaxed max-w-4xl mx-auto">
                            OPBX represents a new way of building software. Every line of code is
                            <span className="text-foreground font-semibold"> born from human vision </span>
                            and
                            <span className="text-foreground font-semibold"> brought to life through AI collaboration</span>.
                            No shortcuts, no compromises—just pure engineering excellence.
                        </p>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div className="text-left p-6 rounded-2xl bg-background border-2 border-primary/20">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 flex items-center justify-center">
                                    <Users className="h-6 w-6 text-primary" />
                                </div>
                                <h3 className="text-xl font-bold">Human Architected</h3>
                            </div>
                            <p className="text-muted-foreground mb-6 text-sm">
                                Every feature starts with deep domain expertise. Our architects design the system
                                with decades of telecom experience, ensuring scalability, security, and reliability.
                            </p>
                            <ul className="space-y-3">
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Multi-tenant architecture designed for scale</span>
                                </li>
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Enterprise-grade security from day one</span>
                                </li>
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Real-time call state management</span>
                                </li>
                            </ul>
                        </div>
                        <div className="text-left p-6 rounded-2xl bg-background border-2 border-primary/20">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 flex items-center justify-center">
                                    <Zap className="h-6 w-6 text-primary" />
                                </div>
                                <h3 className="text-xl font-bold">Vibe Coded</h3>
                            </div>
                            <p className="text-muted-foreground mb-6 text-sm">
                                The implementation leverages cutting-edge AI assistance, allowing us to move fast
                                without sacrificing quality. Every commit is reviewed, tested, and refined.
                            </p>
                            <ul className="space-y-3">
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Rapid iteration with AI pair programming</span>
                                </li>
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Comprehensive test coverage</span>
                                </li>
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Type-safe, modern codebase</span>
                                </li>
                            </ul>
                        </div>
                        <div className="text-left p-6 rounded-2xl bg-background border-2 border-primary/20">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="h-12 w-12 rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 flex items-center justify-center">
                                    <Code className="h-6 w-6 text-primary" />
                                </div>
                                <h3 className="text-xl font-bold">AI Coding Ready</h3>
                            </div>
                            <p className="text-muted-foreground mb-6 text-sm">
                                OPBX is designed from the ground up to be AI-developer friendly. Clear documentation,
                                consistent patterns, and well-structured code make it easy for AI assistants to help you customize.
                            </p>
                            <ul className="space-y-3">
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Comprehensive inline documentation</span>
                                </li>
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Consistent coding patterns throughout</span>
                                </li>
                                <li className="flex items-center gap-3 text-sm">
                                    <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                                    <span>Modular, extensible architecture</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div className="mt-12 text-center">
                        <Button size="lg" variant="outline" asChild>
                            <a href="https://github.com/greenfieldtech-nirs/OPBX" target="_blank" rel="noopener noreferrer">
                                <Github className="mr-2 h-5 w-5"/>
                                Explore the Code
                            </a>
                        </Button>
                    </div>
                </div>
            </section>

            {/* Features Grid */}
            <section id="features" className="container mx-auto px-4 py-20 md:py-32">
                <div className="text-center mb-16">
                    <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
                        <Zap className="h-4 w-4 text-primary" />
                        <span className="text-sm font-medium text-primary">Features</span>
                    </div>
                    <h2 className="text-5xl md:text-5xl font-bold mb-4">Everything You Need</h2>
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
                                <div
                                    className="h-12 w-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4 group-hover:bg-primary/20 transition-colors">
                                    <feature.icon className="h-6 w-6 text-primary"/>
                                </div>
                                <CardTitle className="text-lg">{feature.title}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <CardDescription className="text-base">{feature.description}</CardDescription>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <div className="mt-12 text-center">
                    <Button size="lg" asChild>
                        <Link to="/ui/register">
                            Try All Features
                            <ArrowRight className="ml-2 h-5 w-5"/>
                        </Link>
                    </Button>
                </div>
            </section>

            {/* Interactive Demo Section
            <section className="bg-gradient-to-br from-primary/5 via-background to-primary/5 py-20 md:py-32">
                <div className="container mx-auto px-4">
                    <div className="max-w-4xl mx-auto">
                        <Card className="border-2 border-primary/20 shadow-2xl">
                            <CardHeader className="text-center pb-8">
                                <div
                                    className="inline-flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 mx-auto mb-6">
                                    <Play className="h-8 w-8 text-primary"/>
                                </div>
                                <CardTitle className="text-3xl md:text-4xl mb-4">See OPBX In Action</CardTitle>
                                <CardDescription className="text-lg">
                                    Experience the power of modern business communications
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="aspect-video bg-muted rounded-lg flex items-center justify-center">
                                    <div className="text-center">
                                        <Play className="h-16 w-16 text-muted-foreground mx-auto mb-4"/>
                                        <p className="text-muted-foreground">Live Demo Coming Soon</p>
                                    </div>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div className="text-center p-4 rounded-lg bg-background border">
                                        <div className="text-3xl font-bold text-primary mb-1">&lt;1s</div>
                                        <div className="text-sm text-muted-foreground">Call Routing Time</div>
                                    </div>
                                    <div className="text-center p-4 rounded-lg bg-background border">
                                        <div className="text-3xl font-bold text-primary mb-1">99.9%</div>
                                        <div className="text-sm text-muted-foreground">Uptime SLA</div>
                                    </div>
                                    <div className="text-center p-4 rounded-lg bg-background border">
                                        <div className="text-3xl font-bold text-primary mb-1">100%</div>
                                        <div className="text-sm text-muted-foreground">Open Source</div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </section>
            */}

            {/* How It Works */}
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
                    {[
                        {
                            number: '1',
                            title: 'Deploy & Configure',
                            description:
                                'Launch OPBX with docker-compose, create your organization, and set up users and extensions',
                            icon: Server,
                        },
                        {
                            number: '2',
                            title: 'Connect Cloudonix',
                            description: 'Link your Cloudonix account with API credentials and configure your DID numbers',
                            icon: Phone,
                        },
                        {
                            number: '3',
                            title: 'Route Calls',
                            description: 'Your PBX is live! Monitor calls in real-time, adjust routing, and scale as you grow',
                            icon: Workflow,
                        },
                    ].map((step, idx) => (
                        <div key={idx} className="relative">
                            <div className="text-center">
                                <div
                                    className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary text-primary-foreground text-2xl font-bold mb-6">
                                    {step.number}
                                </div>
                                <div className="mb-4">
                                    <step.icon className="h-12 w-12 text-primary mx-auto"/>
                                </div>
                                <h3 className="text-2xl font-semibold mb-3">{step.title}</h3>
                                <p className="text-muted-foreground text-lg">{step.description}</p>
                            </div>
                            {idx < 2 && (
                                <div
                                    className="hidden md:block absolute top-8 left-[60%] w-[80%] h-0.5 bg-gradient-to-r from-primary to-transparent"/>
                            )}
                        </div>
                    ))}
                </div>
                <div className="mt-16 text-center">
                    <Button size="lg" variant="outline" asChild>
                        <Link to="/ui/register">
                            Start Your Setup
                            <ArrowRight className="ml-2 h-5 w-5"/>
                        </Link>
                    </Button>
                </div>
            </section>

            {/* Technical Highlights */}
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
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            {technicalHighlights.map((highlight, idx) => (
                                <Card key={idx}
                                      className="bg-background/50 backdrop-blur border-2 hover:border-primary/50 transition-all">
                                    <CardHeader>
                                        <highlight.icon className="h-10 w-10 text-primary mb-4"/>
                                        <CardTitle className="text-lg">{highlight.title}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <CardDescription className="text-base">{highlight.description}</CardDescription>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        <div
                            className="mt-16 p-8 rounded-2xl bg-gradient-to-br from-primary/10 to-primary/5 border-2 border-primary/20">
                            <div className="flex flex-col md:flex-row items-center justify-between gap-6">
                                <div className="flex items-start gap-4">
                                    <div
                                        className="h-12 w-12 rounded-lg bg-background flex items-center justify-center flex-shrink-0">
                                        <Github className="h-6 w-6 text-primary"/>
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold mb-2">Fully Open Source</h3>
                                        <p className="text-muted-foreground text-lg">
                                            MIT licensed. Self-host on your infrastructure with complete transparency.
                                        </p>
                                    </div>
                                </div>
                                <Button size="lg" variant="outline" asChild className="flex-shrink-0">
                                    <a href="https://github.com/greenfieldtech-nirs/OPBX" target="_blank"
                                       rel="noopener noreferrer">
                                        <Github className="mr-2 h-5 w-5"/>
                                        Star on GitHub
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Integration Section */}
            <section className="container mx-auto px-4 py-20 md:py-32">
                <div className="max-w-4xl mx-auto text-center">
                    <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
                        <Phone className="h-4 w-4 text-primary" />
                        <span className="text-sm font-medium text-primary">Integration</span>
                    </div>
                    <h2 className="text-4xl md:text-5xl font-bold mb-6">Powered by Cloudonix</h2>
                    <p className="text-xl text-muted-foreground mb-12">
                        OPBX integrates seamlessly with Cloudonix CPaaS platform for enterprise-grade telephony
                        infrastructure
                    </p>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        {[
                            {
                                icon: Phone,
                                title: 'VoIP Infrastructure',
                                description: 'Cloudonix handles all SIP, media, and telephony operations',
                            },
                            {
                                icon: Zap,
                                title: 'Real-Time CXML',
                                description: 'Dynamic call routing with CXML responses in real-time',
                            },
                            {
                                icon: Shield,
                                title: 'Enterprise Grade',
                                description: 'Carrier-grade reliability with global phone number support',
                            },
                        ].map((item, idx) => (
                            <Card key={idx} className="border-2">
                                <CardHeader>
                                    <item.icon className="h-10 w-10 text-primary mx-auto mb-4"/>
                                    <CardTitle className="text-lg">{item.title}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <CardDescription className="text-base">{item.description}</CardDescription>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                    <div className="mt-12">
                        <Button size="lg" variant="outline" asChild>
                            <a href="https://cloudonix.com" target="_blank" rel="noopener noreferrer">
                                Learn More About Cloudonix
                                <ArrowRight className="ml-2 h-4 w-4"/>
                            </a>
                        </Button>&nbsp;
                        <Button size="lg" variant="outline" asChild>
                            <a href="https://developers.cloudonix.com/opbx" target="_blank" rel="noopener noreferrer">
                                Read our developer documentation
                                <ArrowRight className="ml-2 h-4 w-4"/>
                            </a>
                        </Button>
                    </div>
                </div>
            </section>

            {/* FAQ Section */}
            <section id="faq" className="bg-muted/30 py-20 md:py-32">
                <div className="container mx-auto px-4">
                    <div className="max-w-3xl mx-auto">
                        <div className="text-center mb-16">
                            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 mb-6">
                                <HelpCircle className="h-4 w-4 text-primary" />
                                <span className="text-sm font-medium text-primary">FAQ</span>
                            </div>
                            <h2 className="text-4xl md:text-5xl font-bold mb-4">Frequently Asked Questions</h2>
                            <p className="text-xl text-muted-foreground">Everything you need to know about OPBX</p>
                        </div>
                        <div className="space-y-4">
                            {faqs.map((faq, idx) => (
                                <Card
                                    key={idx}
                                    className="cursor-pointer transition-all hover:shadow-md"
                                    onClick={() => setOpenFaq(openFaq === idx ? null : idx)}
                                >
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-4">
                                        <CardTitle className="text-lg font-semibold pr-8">{faq.question}</CardTitle>
                                        <ChevronDown
                                            className={`h-5 w-5 text-muted-foreground transition-transform flex-shrink-0 ${
                                                openFaq === idx ? 'rotate-180' : ''
                                            }`}
                                        />
                                    </CardHeader>
                                    {openFaq === idx && (
                                        <CardContent className="pt-0">
                                            <p className="text-muted-foreground leading-relaxed">{faq.answer}</p>
                                        </CardContent>
                                    )}
                                </Card>
                            ))}
                        </div>
                        <div className="mt-12 text-center">
                            <p className="text-muted-foreground mb-4">Still have questions?</p>
                            <Button size="lg" variant="outline" asChild>
                                <a href="https://discord.gg/etCGgNh9VV" target="_blank" rel="noopener noreferrer">
                                    <svg className="mr-2 h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                                    </svg>
                                    Ask on Discord
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            {/* Final CTA */}
            <section
                className="relative overflow-hidden bg-gradient-to-br from-primary via-primary/90 to-primary/80 text-primary-foreground py-20 md:py-32">
                <div className="absolute inset-0 bg-grid-white/[0.05] bg-[size:50px_50px]"/>
                <div className="container mx-auto px-4 text-center relative">
                    <h2 className="text-4xl md:text-5xl font-bold mb-6">Ready to Transform Your Business
                        Communications?</h2>
                    <p className="text-xl mb-10 opacity-90 max-w-2xl mx-auto">
                        Deploy OPBX today and start managing calls like a pro. Free, open source, and production-ready.
                    </p>
                    <div className="flex flex-col sm:flex-row gap-4 justify-center">
                        <Button size="lg" variant="secondary" asChild className="text-lg h-12 px-8">
                            <Link to="/ui/register">
                                Get Started Free
                                <ArrowRight className="ml-2 h-5 w-5"/>
                            </Link>
                        </Button>
                        <Button size="lg" variant="outline" asChild
                                className="text-lg h-12 px-8 bg-transparent border-white text-white hover:bg-white hover:text-primary">
                            <a href="https://developers.cloudonix.com/opbx" target="_blank"
                               rel="noopener noreferrer">
                                <Github className="mr-2 h-5 w-5"/>
                                View Documentation
                            </a>
                        </Button>
                    </div>
                </div>
            </section>

            {/* Footer */}
            <footer className="border-t py-12 bg-background">
                <div className="container mx-auto px-4">
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                        <div>
                            <div className="flex items-center gap-2 mb-4">
                                <Phone className="h-5 w-5 text-primary"/>
                                <span className="font-bold">OPBX</span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Open source business PBX built on Cloudonix CPaaS platform
                            </p>
                        </div>
                        <div>
                            <h4 className="font-semibold mb-3">Product</h4>
                            <ul className="space-y-2 text-sm text-muted-foreground">
                                <li>
                                    <Link to="/ui/register" className="hover:text-foreground transition-colors">
                                        Get Started
                                    </Link>
                                </li>
                                <li>
                                    <Link to="/ui/login" className="hover:text-foreground transition-colors">
                                        Login
                                    </Link>
                                </li>
                                <li>
                                    <a
                                        href="https://developers.cloudonix.com/opbx"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        Documentation
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h4 className="font-semibold mb-3">Resources</h4>
                            <ul className="space-y-2 text-sm text-muted-foreground">
                                <li>
                                    <a
                                        href="https://developers.cloudonix.com/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        Cloudonix Docs
                                    </a>
                                </li>
                                <li>
                                    <a
                                        href="https://github.com/greenfieldtech-nirs/OPBX"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        GitHub
                                    </a>
                                </li>
                                <li>
                                    <a
                                        href="https://github.com/greenfieldtech-nirs/OPBX/issues"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        Report Issue
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h4 className="font-semibold mb-3">Community</h4>
                            <ul className="space-y-2 text-sm text-muted-foreground">
                                <li>
                                    <a
                                        href="https://discord.gg/etCGgNh9VV"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        Discord Community
                                    </a>
                                </li>
                                <li>
                                    <a
                                        href="https://github.com/greenfieldtech-nirs/OPBX/blob/main/CONTRIBUTING.md"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        Contributing
                                    </a>
                                </li>
                                <li>
                                    <a
                                        href="https://github.com/greenfieldtech-nirs/OPBX/blob/main/LICENSE"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="hover:text-foreground transition-colors"
                                    >
                                        License (MIT)
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div className="border-t pt-8 flex flex-col md:flex-row justify-between items-center gap-4">
                        <p className="text-sm text-muted-foreground">
                            © {new Date().getFullYear()} OPBX. Open source under MIT License.
                        </p>
                        <div className="flex items-center gap-4">
                            <a
                                href="https://github.com/greenfieldtech-nirs/OPBX"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground transition-colors"
                            >
                                <Github className="h-5 w-5"/>
                            </a>
                            <a
                                href="https://discord.gg/etCGgNh9VV"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground transition-colors"
                            >
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </footer>

            {/* Custom CSS for animations */}
            <style>{`
        @keyframes scroll {
          0% {
            transform: translateX(0);
          }
          100% {
            transform: translateX(-50%);
          }
        }
        .animate-scroll {
          animation: scroll 30s linear infinite;
        }
        .bg-grid-white\/\[0\.02\] {
          background-image: linear-gradient(to right, rgba(255, 255, 255, 0.02) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
        }
        .bg-grid-white\/\[0\.05\] {
          background-image: linear-gradient(to right, rgba(255, 255, 255, 0.05) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
        }
      `}</style>
        </div>
    );
}
