import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import OPBXLogo from '@/assets/opbx_logo.png';
import { Menu, X, Github, Star } from 'lucide-react';
import { useEffect, useState } from 'react';

const navLinks = [
  { href: '#features', label: 'Features' },
  { href: '#how-it-works', label: 'How It Works' },
  { href: '#pricing', label: 'Pricing' },
  { href: '#faq', label: 'FAQ' },
  { href: 'https://developers.cloudonix.com/opbx', label: 'Docs', external: true },
];

const GITHUB_REPO = 'https://github.com/greenfieldtech-nirs/OPBX';

function GithubStars() {
  const [stars, setStars] = useState<number | null>(null);

  useEffect(() => {
    fetch('https://api.github.com/repos/greenfieldtech-nirs/OPBX')
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && typeof data.stargazers_count === 'number') {
          setStars(data.stargazers_count);
        }
      })
      .catch(() => {
        // ignore fetch errors
      });
  }, []);

  const formattedStars =
    stars === null
      ? '...'
      : stars >= 1000
        ? `${(stars / 1000).toFixed(1)}k`
        : stars.toString();

  return (
    <Button
      variant="outline"
      asChild
      className="border-border hover:bg-card gap-2"
    >
      <a href={GITHUB_REPO} target="_blank" rel="noopener noreferrer">
        <Star className="h-4 w-4 fill-current" />
        <span>{formattedStars}</span>
      </a>
    </Button>
  );
}

export function HomeHeader() {
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <header className="sticky top-0 z-50 w-full border-b border-border/40 bg-background/80 backdrop-blur-md">
      <div className="container mx-auto px-4 py-4 flex items-center justify-between">
        <Link to="/" className="flex items-center gap-3">
          <img src={OPBXLogo} alt="OPBX Logo" className="h-14 w-auto" />
        </Link>

        <nav className="hidden md:flex items-center gap-8 text-xl">
          {navLinks.map((link) =>
            link.external ? (
              <a
                key={link.href}
                href={link.href}
                target="_blank"
                rel="noopener noreferrer"
                className="font-medium text-muted-foreground hover:text-foreground transition-colors"
              >
                {link.label}
              </a>
            ) : (
              <a
                key={link.href}
                href={link.href}
                className="font-medium text-muted-foreground hover:text-foreground transition-colors"
              >
                {link.label}
              </a>
            )
          )}
        </nav>

        <div className="hidden md:flex items-center gap-3">
          <Button variant="outline" asChild className="border-border hover:bg-card">
            <Link to="/ui/login">Login</Link>
          </Button>
          <Button asChild className="bg-primary text-primary-foreground hover:bg-primary/90">
            <Link to="/ui/register">Get Started</Link>
          </Button>
          <Button
            variant="outline"
            size="icon"
            asChild
            className="border-border hover:bg-card"
          >
            <a href={GITHUB_REPO} target="_blank" rel="noopener noreferrer" aria-label="View OPBX on GitHub">
              <Github className="h-5 w-5" />
            </a>
          </Button>
          <GithubStars />
        </div>

        <div className="flex md:hidden items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            className="text-foreground hover:bg-card"
            onClick={() => setMenuOpen(!menuOpen)}
            aria-label={menuOpen ? 'Close menu' : 'Open menu'}
          >
            {menuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
          </Button>
        </div>
      </div>

      {menuOpen && (
        <div className="md:hidden border-t border-border bg-card">
          <div className="container mx-auto px-4 py-6">
            <nav className="flex flex-col gap-4 mb-6">
              {navLinks.map((link) =>
                link.external ? (
                  <a
                    key={link.href}
                    href={link.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-2xl font-medium text-muted-foreground hover:text-foreground transition-colors"
                    onClick={() => setMenuOpen(false)}
                  >
                    {link.label}
                  </a>
                ) : (
                  <a
                    key={link.href}
                    href={link.href}
                    className="text-2xl font-medium text-muted-foreground hover:text-foreground transition-colors"
                    onClick={() => setMenuOpen(false)}
                  >
                    {link.label}
                  </a>
                )
              )}
            </nav>
            <div className="flex flex-col gap-3">
              <Button variant="outline" asChild className="w-full border-border hover:bg-card">
                <Link to="/ui/login" onClick={() => setMenuOpen(false)}>Login</Link>
              </Button>
              <Button asChild className="w-full bg-primary text-primary-foreground hover:bg-primary/90">
                <Link to="/ui/register" onClick={() => setMenuOpen(false)}>Get Started</Link>
              </Button>
              <div className="flex gap-3">
                <Button
                  variant="outline"
                  asChild
                  className="flex-1 border-border hover:bg-card"
                >
                  <a href={GITHUB_REPO} target="_blank" rel="noopener noreferrer">
                    <Github className="mr-2 h-5 w-5" />
                    GitHub
                  </a>
                </Button>
                <GithubStars />
              </div>
            </div>
          </div>
        </div>
      )}
    </header>
  );
}
