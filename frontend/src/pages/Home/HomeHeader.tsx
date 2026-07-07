import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import OPBXLogo from '@/assets/opbx_logo.png';

export function HomeHeader() {
  return (
    <header className="border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 sticky top-0 z-50">
      <div className="container mx-auto px-4 py-4 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <img src={OPBXLogo} alt="OPBX Logo" className="h-10 w-auto" />
          <span className="font-bold text-xl">OPBX</span>
        </div>
        <nav className="hidden md:flex items-center gap-6">
          <a href="#features" className="text-sm font-medium hover:text-primary transition-colors">
            Features
          </a>
          <a href="#how-it-works" className="text-sm font-medium hover:text-primary transition-colors">
            How It Works
          </a>
          <a href="#dograh" className="text-sm font-medium hover:text-primary transition-colors">
            Dograh
          </a>
          <a
            href="https://developers.cloudonix.com/opbx"
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm font-medium hover:text-primary transition-colors"
          >
            Docs
          </a>
        </nav>
        <div className="flex items-center gap-2">
          <Button variant="ghost" asChild>
            <Link to="/ui/login">Login</Link>
          </Button>
          <Button asChild>
            <Link to="/ui/register">Get Started</Link>
          </Button>
        </div>
      </div>
    </header>
  );
}
