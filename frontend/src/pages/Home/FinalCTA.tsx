import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { ArrowRight, Github } from 'lucide-react';

export function FinalCTA() {
  return (
    <section className="relative overflow-hidden bg-gradient-to-br from-primary via-primary/90 to-primary/80 text-primary-foreground py-20 md:py-32">
      <div className="absolute inset-0 bg-grid-white/[0.05] bg-[size:50px_50px]" />
      <div className="container mx-auto px-4 text-center relative">
        <h2 className="text-4xl md:text-5xl font-bold mb-6">
          Ready to Transform Your Business Communications?
        </h2>
        <p className="text-xl mb-10 opacity-90 max-w-2xl mx-auto">
          Deploy OPBX today and start managing calls like a pro. Free, open source, and
          production-ready.
        </p>
        <div className="flex flex-col sm:flex-row gap-4 justify-center">
          <Button size="lg" variant="secondary" asChild className="text-lg h-12 px-8">
            <Link to="/ui/register">
              Get Started Free
              <ArrowRight className="ml-2 h-5 w-5" />
            </Link>
          </Button>
          <Button
            size="lg"
            variant="outline"
            asChild
            className="text-lg h-12 px-8 bg-transparent border-white text-white hover:bg-white hover:text-primary"
          >
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
