import { Link } from 'react-router-dom';
import { Github, Phone } from 'lucide-react';

export function Footer() {
  return (
    <footer className="border-t py-12 bg-background">
      <div className="container mx-auto px-4">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
          <div>
            <div className="flex items-center gap-2 mb-4">
              <Phone className="h-5 w-5 text-primary" />
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
        <div className="border-t pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
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
              <Github className="h-5 w-5" />
            </a>
          </div>
        </div>
      </div>
    </footer>
  );
}
