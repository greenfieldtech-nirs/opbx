import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { ArrowRight, Github } from 'lucide-react';
import { useEffect, useRef } from 'react';
import opbxLogo from '@/assets/opbx_logo.png';

function DiscordIcon({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.076.076 0 0 0-.041.105c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
    </svg>
  );
}

type NodeSpec = {
  id: string;
  base: { x: number; y: number };
  linkId: string;
  flowId: string;
  logo: string;
  speed: number;
  amp: number;
};

function AnimatedPBX() {
  const svgRef = useRef<SVGSVGElement>(null);
  const rafRef = useRef<number | null>(null);
  const startRef = useRef<number>(performance.now());
  const nodeElsRef = useRef<Record<string, SVGGElement>>({});
  const lineElsRef = useRef<Record<string, SVGLineElement>>({});
  const flowElsRef = useRef<Record<string, SVGPathElement>>({});
  const coreCircleRef = useRef<SVGCircleElement>(null);
  const coreHaloRef = useRef<SVGCircleElement>(null);
  const coreGroupRef = useRef<SVGGElement>(null);

  const coreBase = { x: 200, y: 230 };
  const coreRadius = 77;

  const nodes: NodeSpec[] = [
    { id: 'node-in1', base: { x: 60, y: 60 }, linkId: 'line-in1', flowId: 'flow-in1', logo: '/logos/node-1.webp', speed: 1.4, amp: 12 },
    { id: 'node-in2', base: { x: 200, y: 40 }, linkId: 'line-in2', flowId: 'flow-in2', logo: '/logos/node-2.webp', speed: 1.1, amp: 14 },
    { id: 'node-in3', base: { x: 340, y: 60 }, linkId: 'line-in3', flowId: 'flow-in3', logo: '/logos/node-3.webp', speed: 1.6, amp: 12 },
    { id: 'node-in4', base: { x: 40, y: 200 }, linkId: 'line-in4', flowId: 'flow-in4', logo: '/logos/node-4.webp', speed: 1.2, amp: 13 },
    { id: 'node-in5', base: { x: 360, y: 200 }, linkId: 'line-in5', flowId: 'flow-in5', logo: '/logos/node-5.webp', speed: 1.8, amp: 11 },
    { id: 'node-out1', base: { x: 60, y: 400 }, linkId: 'line-out1', flowId: 'flow-out1', logo: '/logos/node-6.webp', speed: 1.3, amp: 12.5 },
    { id: 'node-out2', base: { x: 140, y: 420 }, linkId: 'line-out2', flowId: 'flow-out2', logo: '/logos/node-7.webp', speed: 1.5, amp: 14 },
    { id: 'node-out3', base: { x: 260, y: 420 }, linkId: 'line-out3', flowId: 'flow-out3', logo: '/logos/node-8.webp', speed: 1.0, amp: 11 },
    { id: 'node-out4', base: { x: 340, y: 400 }, linkId: 'line-out4', flowId: 'flow-out4', logo: '/logos/node-9.webp', speed: 1.7, amp: 12.5 },
  ];

  const nodeInstances = useRef(
    nodes.map((node) => ({
      ...node,
      phase: Math.random() * Math.PI * 2,
      phase2: Math.random() * Math.PI * 2,
      current: { ...node.base },
    }))
  ).current;

  useEffect(() => {
    const svg = svgRef.current;
    if (!svg) return;

    nodeInstances.forEach((node) => {
      nodeElsRef.current[node.id] = svg.getElementById(node.id) as SVGGElement;
      lineElsRef.current[node.linkId] = svg.getElementById(node.linkId) as SVGLineElement;
      flowElsRef.current[node.flowId] = svg.getElementById(node.flowId) as SVGPathElement;
    });

    function getOffset(t: number, speed: number, amp: number, phase: number, phase2: number) {
      const x = Math.sin(t * speed + phase) * amp + Math.sin(t * speed * 1.7 + phase2) * (amp * 0.5);
      const y = Math.cos(t * speed * 0.8 + phase) * amp + Math.cos(t * speed * 1.3 + phase2) * (amp * 0.5);
      return { x, y };
    }

    function getEdgePoint(cx: number, cy: number, radius: number, tx: number, ty: number) {
      const dx = tx - cx;
      const dy = ty - cy;
      const dist = Math.sqrt(dx * dx + dy * dy) || 1;
      return {
        x: cx + (dx / dist) * radius,
        y: cy + (dy / dist) * radius,
      };
    }

    function animate(now: number) {
      const t = (now - startRef.current) / 1000;
      const coreX = coreBase.x;
      const coreY = coreBase.y;
      const coreR = coreRadius;

      coreCircleRef.current?.setAttribute('cx', String(coreX));
      coreCircleRef.current?.setAttribute('cy', String(coreY));
      coreCircleRef.current?.setAttribute('r', String(coreR));
      coreHaloRef.current?.setAttribute('cx', String(coreX));
      coreHaloRef.current?.setAttribute('cy', String(coreY));
      coreHaloRef.current?.setAttribute('r', String(coreR * 1.3));
      coreGroupRef.current?.setAttribute('transform', `translate(${coreX}, ${coreY}) scale(1)`);

      nodeInstances.forEach((node) => {
        const off = getOffset(t, node.speed, node.amp, node.phase, node.phase2);
        node.current.x = node.base.x + off.x;
        node.current.y = node.base.y + off.y;

        const scale = 1 + Math.sin(t * node.speed * 2 + node.phase) * 0.12;
        nodeElsRef.current[node.id]?.setAttribute(
          'transform',
          `translate(${node.current.x}, ${node.current.y}) scale(${scale})`
        );

        const coreEdge = getEdgePoint(coreX, coreY, coreR - 2, node.current.x, node.current.y);
        const nodeEdge = getEdgePoint(node.current.x, node.current.y, 24, coreX, coreY);

        const lineEl = lineElsRef.current[node.linkId];
        if (lineEl) {
          lineEl.setAttribute('x1', String(coreEdge.x));
          lineEl.setAttribute('y1', String(coreEdge.y));
          lineEl.setAttribute('x2', String(nodeEdge.x));
          lineEl.setAttribute('y2', String(nodeEdge.y));
        }

        const midX = (coreEdge.x + nodeEdge.x) / 2 + Math.sin(t * node.speed + node.phase) * 18;
        const midY = (coreEdge.y + nodeEdge.y) / 2 + Math.cos(t * node.speed + node.phase) * 18;
        flowElsRef.current[node.flowId]?.setAttribute(
          'd',
          `M${nodeEdge.x},${nodeEdge.y} Q${midX},${midY} ${coreEdge.x},${coreEdge.y}`
        );
      });

      rafRef.current = requestAnimationFrame(animate);
    }

    rafRef.current = requestAnimationFrame(animate);

    return () => {
      if (rafRef.current) {
        cancelAnimationFrame(rafRef.current);
      }
    };
  }, [nodeInstances]);

  return (
    <div className="relative w-full h-full min-h-[420px] flex items-center justify-center">
      <svg
        ref={svgRef}
        viewBox="0 0 400 460"
        className="w-full h-full max-w-[420px] drop-shadow-2xl"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="flowGrad" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stopColor="#1e40af" stopOpacity="0" />
            <stop offset="50%" stopColor="#60a5fa" />
            <stop offset="100%" stopColor="#1e40af" stopOpacity="0" />
          </linearGradient>
          <clipPath id="logoClip">
            <circle r="22" />
          </clipPath>
        </defs>

        <g>
          <line id="line-in1" className="hero-net-line" />
          <line id="line-in2" className="hero-net-line" />
          <line id="line-in3" className="hero-net-line" />
          <line id="line-in4" className="hero-net-line" />
          <line id="line-in5" className="hero-net-line" />
          <line id="line-out1" className="hero-net-line" />
          <line id="line-out2" className="hero-net-line" />
          <line id="line-out3" className="hero-net-line" />
          <line id="line-out4" className="hero-net-line" />

          <path id="flow-in1" className="hero-net-flow" />
          <path id="flow-in2" className="hero-net-flow" style={{ animationDelay: '-0.4s' }} />
          <path id="flow-in3" className="hero-net-flow" style={{ animationDelay: '-0.8s' }} />
          <path id="flow-in4" className="hero-net-flow" style={{ animationDelay: '-1.2s' }} />
          <path id="flow-in5" className="hero-net-flow" style={{ animationDelay: '-1.6s' }} />
          <path id="flow-out1" className="hero-net-flow reverse" style={{ animationDelay: '-0.3s' }} />
          <path id="flow-out2" className="hero-net-flow reverse" style={{ animationDelay: '-0.7s' }} />
          <path id="flow-out3" className="hero-net-flow reverse" style={{ animationDelay: '-1.1s' }} />
          <path id="flow-out4" className="hero-net-flow reverse" style={{ animationDelay: '-1.5s' }} />
        </g>

        <circle
          ref={coreHaloRef}
          cx={coreBase.x}
          cy={coreBase.y}
          r={coreRadius * 1.3}
          fill="rgba(37, 99, 235, 0.12)"
        />
        <circle
          ref={coreCircleRef}
          cx={coreBase.x}
          cy={coreBase.y}
          r={coreRadius}
          className="hero-net-core"
        />
        <g ref={coreGroupRef} transform={`translate(${coreBase.x}, ${coreBase.y})`}>
          <image x="-45" y="-45" width="90" height="90" href={opbxLogo} className="hero-net-logo-image" />
        </g>

        {nodes.map((node) => (
          <g key={node.id} id={node.id} transform={`translate(${node.base.x}, ${node.base.y})`}>
            <circle r="24" className="hero-net-logo-frame" />
            <image
              x="-22"
              y="-22"
              width="44"
              height="44"
              href={node.logo}
              clipPath="url(#logoClip)"
              className="hero-net-logo-image"
            />
          </g>
        ))}
      </svg>
    </div>
  );
}

export function Hero() {
  return (
    <section className="relative overflow-hidden bg-background">
      <div className="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.03)_1px,transparent_1px)] bg-[size:60px_60px]" />
      <div className="container mx-auto px-4 py-24 md:py-32 relative">
        <div className="grid grid-cols-1 lg:grid-cols-[65%_35%] gap-12 items-center">
          <div className="text-center lg:text-left">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-card/50 backdrop-blur mb-6">
              <span className="relative flex h-2 w-2">
                <span className="animate-ring-pulse absolute inline-flex h-full w-full rounded-full bg-primary" />
                <span className="relative inline-flex rounded-full h-2 w-2 bg-primary" />
              </span>
              <span className="text-xl font-medium text-muted-foreground">AI-First Open Source PBX Platform</span>
            </div>

            <h1 className="text-5xl md:text-6xl lg:text-7xl font-bold tracking-tight mb-6 text-foreground leading-[1.1]">
              AI Voice Agents Meet
              <br />
              <span className="text-primary">Open-Source PBX</span>
            </h1>
            <p className="text-xl md:text-3xl text-muted-foreground mb-10 max-w-3xl mx-auto lg:mx-0 leading-relaxed">
              Build, deploy, and manage your business phone system with AI-powered voice agents, smart
              routing, and real-time monitoring. Built on Cloudonix.
            </p>
            <div className="flex flex-col sm:flex-row flex-wrap gap-4 justify-center lg:justify-start items-center">
              <Button size="lg" asChild className="bg-primary text-primary-foreground hover:bg-primary/90 text-2xl h-12 px-8">
                <Link to="/ui/register">
                  Try For Free
                  <ArrowRight className="ml-2 h-5 w-5" />
                </Link>
              </Button>
              <Button size="lg" variant="outline" asChild className="text-2xl h-12 px-8 border-border hover:bg-card">
                <a
                  href="https://github.com/greenfieldtech-nirs/OPBX"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <Github className="mr-2 h-5 w-5" />
                  View on GitHub
                </a>
              </Button>
              <Button size="lg" variant="outline" asChild className="text-2xl h-12 px-8 border-border hover:bg-card">
                <a
                  href="https://discord.gg/etCGgNh9VV"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <DiscordIcon className="mr-2 h-5 w-5" />
                  Join Discord
                </a>
              </Button>
            </div>
          </div>

          <div className="hidden lg:flex">
            <AnimatedPBX />
          </div>
        </div>
      </div>
    </section>
  );
}
