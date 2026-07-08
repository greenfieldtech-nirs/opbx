import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { ArrowRight, Github } from 'lucide-react';

export function FinalCTA() {
  return (
    <section className="relative overflow-hidden bg-card/30 border-y border-border py-20 md:py-32">
      <div className="absolute inset-0 bg-[linear-gradient(rgba(59,130,246,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(59,130,246,0.03)_1px,transparent_1px)] bg-[size:60px_60px]" />
      <div className="container mx-auto px-4 text-center relative">
        <div className="inline-flex items-center justify-center h-16 w-16 rounded-full bg-primary/10 mb-8">
          <Github className="h-8 w-8 text-primary" />
        </div>
        <h2 className="text-4xl md:text-5xl font-bold mb-6 text-foreground">
          Ready to Transform Your Business Communications?
        </h2>
        <p className="text-xl mb-10 text-muted-foreground max-w-2xl mx-auto">
          Deploy OPBX today and start managing calls like a pro. Free, open source, and
          production-ready.
        </p>
        <div className="flex flex-col sm:flex-row gap-4 justify-center">
          <Button size="lg" asChild className="text-2xl h-12 px-8 bg-primary text-primary-foreground hover:bg-primary/90">
            <Link to="/ui/register">
              Get Started Free
              <ArrowRight className="ml-2 h-5 w-5" />
            </Link>
          </Button>
          <Button size="lg" variant="outline" asChild className="text-2xl h-12 px-8 border-border hover:bg-card">
            <a
              href="https://developers.cloudonix.com/opbx"
              target="_blank"
              rel="noopener noreferrer"
            >
              <Github className="mr-2 h-5 w-5" />
              View Documentation
            </a>
          </Button>
        </div>
      </div>
    </section>
  );
}
