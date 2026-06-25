/**
 * Auth0 Onboarding Page
 *
 * Lets new Auth0 users create an organization or request to join one.
 */

import { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { auth0Service } from '@/services/auth0.service';
import { toast } from 'sonner';

export default function Auth0Onboarding() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const [slug, setSlug] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const email = searchParams.get('email') || '';
  const provider = searchParams.get('provider') || '';
  const subject = searchParams.get('subject') || '';
  const name = searchParams.get('name') || '';

  const createOrganization = async () => {
    setIsSubmitting(true);
    try {
      // Re-initiate Auth0 with register intent
      const { redirect_url } = await auth0Service.redirect(provider, 'register');
      window.location.href = redirect_url;
    } catch {
      toast.error('Failed to start organization creation.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const requestJoin = async () => {
    setIsSubmitting(true);
    try {
      await auth0Service.submitJoinRequest({
        organization_slug: slug,
        provider,
        provider_subject: subject,
        email,
        name,
      });
      toast.success('Join request submitted. Please wait for approval.');
      navigate('/ui/login');
    } catch {
      toast.error('Failed to submit join request.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <div className="max-w-md w-full space-y-6">
        <h1 className="text-2xl font-bold">Complete your account</h1>
        <div className="space-y-4">
          <Button onClick={createOrganization} disabled={isSubmitting} className="w-full">
            Create a new organization
          </Button>
          <div className="relative">
            <div className="absolute inset-0 flex items-center"><span className="w-full border-t" /></div>
            <div className="relative flex justify-center text-xs uppercase">
              <span className="bg-background px-2 text-muted-foreground">Or</span>
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="slug">Organization slug</Label>
            <Input id="slug" value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="acme-corp" />
          </div>
          <Button onClick={requestJoin} disabled={isSubmitting || !slug} variant="outline" className="w-full">
            Request to join organization
          </Button>
        </div>
      </div>
    </div>
  );
}
