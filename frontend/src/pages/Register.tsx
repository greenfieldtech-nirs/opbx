/**
 * Register Page
 *
 * Organization registration page with admin user creation
 * Design matches Login page - clean two-column layout
 */

import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { useAuth } from '@/hooks/useAuth';
import { useEmailValidation } from '@/hooks/useEmailValidation';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AuroraBackgroundProvider } from '@nauverse/react-aurora-background';
import { CheckCircle, XCircle, Loader2, AlertCircle } from 'lucide-react';
import opbxLogo from '@/assets/opbx_logo.png';

const commonTimezones = [
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'America/Anchorage',
  'Pacific/Honolulu',
  'Europe/London',
  'Europe/Paris',
  'Europe/Berlin',
  'Asia/Tokyo',
  'Asia/Shanghai',
  'Asia/Dubai',
  'Australia/Sydney',
  'UTC',
];

const organizationSchema = z.object({
  name: z.string()
    .min(2, 'Organization name must be at least 2 characters')
    .max(100, 'Organization name must be less than 100 characters')
    .regex(/^[a-zA-Z0-9\s\-_'.]+$/, 'Organization name can only contain letters, numbers, spaces, hyphens, underscores, and periods'),
  timezone: z.string().min(1, 'Please select a timezone'),
});

const adminSchema = z.object({
  name: z.string()
    .min(2, 'Name must be at least 2 characters')
    .max(100, 'Name must be less than 100 characters'),
  email: z.string().email('Invalid email address'),
  password: z.string()
    .min(8, 'Password must be at least 8 characters')
    .regex(/[A-Z]/, 'Password must contain at least one uppercase letter')
    .regex(/[a-z]/, 'Password must contain at least one lowercase letter')
    .regex(/[0-9]/, 'Password must contain at least one number')
    .regex(/[^a-zA-Z0-9]/, 'Password must contain at least one special character'),
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation'],
});

const registerSchema = z.object({
  organization: organizationSchema,
  admin: adminSchema,
});

type RegisterFormData = z.infer<typeof registerSchema>;

export default function Register() {
  const [isLoading, setIsLoading] = useState(false);
  const [step, setStep] = useState<'organization' | 'admin'>('organization');
  const navigate = useNavigate();

  const {
    register: formRegister,
    handleSubmit,
    trigger,
    watch,
    formState: { errors },
    setError,
    clearErrors,
  } = useForm<RegisterFormData>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      organization: {
        name: '',
        timezone: 'America/New_York',
      },
      admin: {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
      },
    },
  });

  const organizationName = watch('organization.name');
  const adminEmail = watch('admin.email');

  const {
    status: emailValidationStatus,
    message: emailValidationMessage,
    suggestion: emailSuggestion,
    isValid: isEmailValid,
    isValidating: isEmailValidating,
    hasError: hasEmailError,
    validateEmail,
    resetValidation: resetEmailValidation,
  } = useEmailValidation(500); // 500ms debounce

  const { register: registerUser, isAuthenticated: authIsAuthenticated, isLoading: authLoading } = useAuth();

  // Watch email changes and trigger validation
  useEffect(() => {
    if (adminEmail && adminEmail.length > 3) {
      validateEmail(adminEmail);
    } else {
      resetEmailValidation();
    }
  }, [adminEmail, validateEmail, resetEmailValidation]);

  // Sync email validation errors with form errors
  useEffect(() => {
    if (hasEmailError && emailValidationMessage) {
      setError('admin.email', {
        type: 'manual',
        message: emailValidationMessage,
      });
    } else if (isEmailValid) {
      clearErrors('admin.email');
    }
  }, [hasEmailError, emailValidationMessage, isEmailValid, setError, clearErrors]);

  useEffect(() => {
    if (!authLoading && authIsAuthenticated) {
      navigate('/ui/dashboard', { replace: true });
    }
  }, [authIsAuthenticated, authLoading, navigate]);

  const handleContinue = async () => {
    const isValid = await trigger('organization');
    if (isValid) {
      setStep('admin');
    }
  };

  const handleBack = () => {
    setStep('organization');
    resetEmailValidation();
  };

  const onSubmit = async (data: RegisterFormData) => {
    // Final email validation check
    if (!isEmailValid) {
      toast.error(emailValidationMessage || 'Please enter a valid email address');
      return;
    }

    setIsLoading(true);

    try {
      await registerUser({
        organization: {
          name: data.organization.name,
          timezone: data.organization.timezone,
        },
        admin: {
          name: data.admin.name,
          email: data.admin.email,
          password: data.admin.password,
          password_confirmation: data.admin.password_confirmation,
        },
      }, () => navigate('/ui/dashboard'));

      toast.success('Organization registered successfully!');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Registration failed');
    } finally {
      setIsLoading(false);
    }
  };

  // Get email validation indicator
  const getEmailValidationIndicator = () => {
    if (emailValidationStatus === 'idle' || !adminEmail) return null;

    if (isEmailValidating) {
      return (
        <div className="absolute right-3 top-1/2 -translate-y-1/2">
          <Loader2 className="h-5 w-5 text-muted-foreground animate-spin" />
        </div>
      );
    }

    if (isEmailValid) {
      return (
        <div className="absolute right-3 top-1/2 -translate-y-1/2">
          <CheckCircle className="h-5 w-5 text-green-500" />
        </div>
      );
    }

    if (hasEmailError) {
      return (
        <div className="absolute right-3 top-1/2 -translate-y-1/2">
          <XCircle className="h-5 w-5 text-destructive" />
        </div>
      );
    }

    return null;
  };

  // Get email validation message with suggestion
  const getEmailValidationMessage = () => {
    if (emailValidationStatus === 'idle') return null;

    if (emailSuggestion && hasEmailError) {
      return (
        <div className="text-sm mt-1">
          <span className="text-destructive">{emailValidationMessage}</span>
          <button
            type="button"
            onClick={() => {
              // Update form value with suggestion
              const emailInput = document.getElementById('adminEmail') as HTMLInputElement;
              if (emailInput && emailSuggestion) {
                emailInput.value = emailSuggestion;
                // Trigger change event
                emailInput.dispatchEvent(new Event('input', { bubbles: true }));
              }
            }}
            className="ml-2 text-blue-600 hover:text-blue-800 underline"
          >
            Use {emailSuggestion} instead
          </button>
        </div>
      );
    }

    if (hasEmailError) {
      return <p className="text-sm text-destructive mt-1">{emailValidationMessage}</p>;
    }

    if (isEmailValid) {
      return <p className="text-sm text-green-600 mt-1">Email looks good!</p>;
    }

    return null;
  };

  return (
    <div className="min-h-screen bg-white" style={{ fontFamily: 'Roboto, sans-serif' }}>
      <div className="grid lg:grid-cols-2 min-h-screen">
        <div className="relative flex flex-col justify-center bg-gray-900">
          <AuroraBackgroundProvider
            className="flex items-center justify-center"
            colors={['#3A29FF', '#00003a', '#030118']}
            numBubbles={2}
            animDuration={3}
            blurAmount="10vw"
            bgColor="#000000"
            useRandomness={true}
          >
            <div className="relative z-10 max-w-lg mx-auto space-y-8">
              <div className="flex items-center justify-center mb-8">
                <img src={opbxLogo} alt="OPBX Logo" className="h-32 w-auto" />
              </div>

              <div className="space-y-4 text-center">
                <p className="text-xl text-blue-200 mt-4">
                  Create your Business PBX
                </p>
                <p className="text-lg text-gray-400">
                  Set up your organization in minutes
                </p>
              </div>
            </div>
          </AuroraBackgroundProvider>
        </div>

        <div className="flex flex-col justify-center p-12 bg-white">
          <div className="max-w-md mx-auto w-full">
            <Card className="border-0 shadow-none">
              <CardHeader className="space-y-2 pb-8">
                <CardTitle className="text-2xl font-bold text-left">
                  {step === 'organization' ? 'Create Organization' : 'Create Admin Account'}
                </CardTitle>
                <CardDescription className="text-left">
                  {step === 'organization'
                    ? 'Enter your organization details to get started'
                    : 'Create the first admin user for your organization'}
                </CardDescription>
              </CardHeader>
              <CardContent className="pt-0">
                <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                  {step === 'organization' ? (
                    <>
                      <div className="space-y-2">
                        <Label htmlFor="orgName" className="text-base font-medium">
                          Organization Name
                        </Label>
                        <Input
                          id="orgName"
                          type="text"
                          placeholder="Acme Corporation"
                          disabled={isLoading}
                          className="h-11"
                          {...formRegister('organization.name')}
                        />
                        {errors.organization?.name && (
                          <p className="text-sm text-destructive">{errors.organization.name.message}</p>
                        )}
                        <p className="text-xs text-muted-foreground">
                          This will be displayed in your PBX dashboard
                        </p>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="timezone" className="text-base font-medium">
                          Timezone
                        </Label>
                        <Select
                          defaultValue={watch('organization.timezone')}
                          onValueChange={(value) => {
                            formRegister('organization.timezone').onChange({ target: { value } });
                          }}
                          disabled={isLoading}
                        >
                          <SelectTrigger id="timezone" className="h-11">
                            <SelectValue placeholder="Select timezone" />
                          </SelectTrigger>
                          <SelectContent>
                            {commonTimezones.map((tz) => (
                              <SelectItem key={tz} value={tz}>
                                {tz.replace('_', ' ')}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        {errors.organization?.timezone && (
                          <p className="text-sm text-destructive">{errors.organization.timezone.message}</p>
                        )}
                      </div>

                      <Button
                        type="button"
                        className="w-full h-11"
                        onClick={handleContinue}
                        disabled={!organizationName || isLoading}
                      >
                        Continue
                      </Button>
                    </>
                  ) : (
                    <>
                      <div className="space-y-2">
                        <Label htmlFor="adminName" className="text-base font-medium">
                          Your Name
                        </Label>
                        <Input
                          id="adminName"
                          type="text"
                          placeholder="John Doe"
                          disabled={isLoading}
                          className="h-11"
                          {...formRegister('admin.name')}
                        />
                        {errors.admin?.name && (
                          <p className="text-sm text-destructive">{errors.admin.name.message}</p>
                        )}
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="adminEmail" className="text-base font-medium">
                          Email Address
                          <span className="text-muted-foreground text-sm font-normal ml-2">
                            (will be validated)
                          </span>
                        </Label>
                        <div className="relative">
                          <Input
                            id="adminEmail"
                            type="email"
                            placeholder="admin@example.com"
                            disabled={isLoading}
                            className="h-11 pr-10"
                            {...formRegister('admin.email')}
                          />
                          {getEmailValidationIndicator()}
                        </div>
                        {errors.admin?.email && (
                          <p className="text-sm text-destructive">{errors.admin.email.message}</p>
                        )}
                        {getEmailValidationMessage()}
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="adminPassword" className="text-base font-medium">
                          Password
                        </Label>
                        <Input
                          id="adminPassword"
                          type="password"
                          placeholder="Create a strong password"
                          disabled={isLoading}
                          className="h-11"
                          {...formRegister('admin.password')}
                        />
                        {errors.admin?.password && (
                          <p className="text-sm text-destructive">{errors.admin.password.message}</p>
                        )}
                        <p className="text-xs text-muted-foreground">
                          At least 8 characters with uppercase, lowercase, number, and special character
                        </p>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="adminPasswordConfirm" className="text-base font-medium">
                          Confirm Password
                        </Label>
                        <Input
                          id="adminPasswordConfirm"
                          type="password"
                          placeholder="Confirm your password"
                          disabled={isLoading}
                          className="h-11"
                          {...formRegister('admin.password_confirmation')}
                        />
                        {errors.admin?.password_confirmation && (
                          <p className="text-sm text-destructive">{errors.admin.password_confirmation.message}</p>
                        )}
                      </div>

                      <div className="flex gap-3">
                        <Button
                          type="button"
                          variant="outline"
                          className="flex-1 h-11"
                          onClick={handleBack}
                          disabled={isLoading}
                        >
                          Back
                        </Button>
                        <Button
                          type="submit"
                          className="flex-1 h-11"
                          disabled={isLoading || !isEmailValid || isEmailValidating}
                        >
                          {isLoading ? 'Creating...' : 'Create Organization'}
                        </Button>
                      </div>

                      {/* Info notice about email validation */}
                      {emailValidationStatus === 'error' && (
                        <div className="flex items-start gap-2 p-3 bg-amber-50 border border-amber-200 rounded-md">
                          <AlertCircle className="h-5 w-5 text-amber-600 mt-0.5 flex-shrink-0" />
                          <p className="text-sm text-amber-800">
                            Unable to validate email at this time. Please try again later or contact support.
                          </p>
                        </div>
                      )}
                    </>
                  )}
                </form>

                <div className="mt-6 text-center">
                  <p className="text-sm text-gray-600">
                    Already have an account?{' '}
                    <a
                      href="/ui/login"
                      className="text-sm text-blue-600 hover:text-blue-800 font-medium"
                      onClick={(e) => {
                        e.preventDefault();
                        navigate('/ui/login');
                      }}
                    >
                      Sign in
                    </a>
                  </p>
                </div>

                <div className="mt-8 text-center text-sm text-gray-600">
                  <p>Made with love by <a href="https://cloudonix.com">Cloudonix</a></p>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </div>
  );
}
