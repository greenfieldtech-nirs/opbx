/**
 * Social Authentication Buttons
 *
 * Renders enabled Auth0 provider buttons on login/register pages.
 */

import { Button } from '@/components/ui/button';
import { useConfig } from '@/context/ConfigContext';
import { auth0Service } from '@/services/auth0.service';
import { toast } from 'sonner';

const PROVIDERS = [
  { key: 'google', label: 'Google' },
  { key: 'facebook', label: 'Facebook' },
  { key: 'microsoft', label: 'Microsoft' },
  { key: 'github', label: 'GitHub' },
  { key: 'x', label: 'X' },
];

interface SocialAuthButtonsProps {
  intent: 'login' | 'register';
}

export function SocialAuthButtons({ intent }: SocialAuthButtonsProps) {
  const { saasEnabled, auth0Config } = useConfig();

  if (!saasEnabled || !auth0Config.enabled || !auth0Config.providers?.length) {
    return null;
  }

  const enabledProviders = PROVIDERS.filter((p) => auth0Config.providers?.includes(p.key));

  const handleClick = async (provider: string) => {
    try {
      const { redirect_url } = await auth0Service.redirect(provider, intent);
      window.location.href = redirect_url;
    } catch (error) {
      toast.error('Failed to start social login. Please try again.');
    }
  };

  return (
    <div className="space-y-3">
      <div className="relative">
        <div className="absolute inset-0 flex items-center">
          <span className="w-full border-t" />
        </div>
        <div className="relative flex justify-center text-xs uppercase">
          <span className="bg-background px-2 text-muted-foreground">Or continue with</span>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        {enabledProviders.map((provider) => (
          <Button
            key={provider.key}
            variant="outline"
            onClick={() => handleClick(provider.key)}
            type="button"
          >
            {provider.label}
          </Button>
        ))}
      </div>
    </div>
  );
}
