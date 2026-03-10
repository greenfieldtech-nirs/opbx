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
import { useRecaptcha } from '@/hooks/useRecaptcha';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AuroraBackgroundProvider } from '@nauverse/react-aurora-background';
import { CheckCircle, XCircle, Loader2, AlertCircle } from 'lucide-react';
import opbxLogo from '@/assets/opbx_logo.png';

// Comprehensive list of IANA timezones with display names
// Source: https://us.kintone.help/general/en/admin/list_systemadmin/list_localization/timezone
interface Timezone {
  value: string;
  label: string;
}

const commonTimezones: Timezone[] = [
  // UTC
  { value: 'UTC', label: '(UTC+00:00) Coordinated Universal Time' },
  { value: 'Etc/GMT', label: '(UTC+00:00) Coordinated Universal Time' },
  { value: 'Etc/GMT+12', label: '(UTC-12:00) International Date Line West' },
  { value: 'Etc/GMT+11', label: '(UTC-11:00) Coordinated Universal Time-11' },
  { value: 'Etc/GMT+2', label: '(UTC-02:00) Coordinated Universal Time-2' },
  { value: 'Etc/GMT-12', label: '(UTC+12:00) Coordinated Universal Time+12' },

  // North America
  { value: 'America/New_York', label: '(UTC-05:00) Eastern Time (US and Canada)' },
  { value: 'America/Chicago', label: '(UTC-06:00) Central Time (US and Canada)' },
  { value: 'America/Denver', label: '(UTC-07:00) Mountain Time (US and Canada)' },
  { value: 'America/Los_Angeles', label: '(UTC-08:00) Pacific Time (US and Canada)' },
  { value: 'America/Anchorage', label: '(UTC-09:00) Alaska' },
  { value: 'Pacific/Honolulu', label: '(UTC-10:00) Hawaii' },
  { value: 'America/Phoenix', label: '(UTC-07:00) Arizona' },
  { value: 'America/Regina', label: '(UTC-06:00) Saskatchewan' },
  { value: 'America/Mexico_City', label: '(UTC-06:00) Guadalajara, Mexico City, Monterrey' },
  { value: 'America/Guatemala', label: '(UTC-06:00) Central America' },
  { value: 'America/Bogota', label: '(UTC-05:00) Bogota, Lima, Quito' },
  { value: 'America/Indiana/Indianapolis', label: '(UTC-05:00) Indiana (East)' },
  { value: 'America/Halifax', label: '(UTC-04:00) Atlantic Time (Canada)' },
  { value: 'America/St_Johns', label: '(UTC-03:30) Newfoundland' },
  { value: 'America/Santa_Isabel', label: '(UTC-08:00) Baja California' },
  { value: 'America/Chihuahua', label: '(UTC-07:00) Chihuahua, La Paz, Mazatlan' },
  { value: 'America/Caracas', label: '(UTC-04:30) Caracas' },
  { value: 'America/La_Paz', label: '(UTC-04:00) Georgetown, La Paz, Manaus, San Juan' },
  { value: 'America/Cuiaba', label: '(UTC-04:00) Cuiaba' },
  { value: 'America/Santiago', label: '(UTC-04:00) Santiago' },
  { value: 'America/Asuncion', label: '(UTC-04:00) Asuncion' },

  // South America
  { value: 'America/Sao_Paulo', label: '(UTC-03:00) Brasilia' },
  { value: 'America/Argentina/Buenos_Aires', label: '(UTC-03:00) Buenos Aires' },
  { value: 'America/Montevideo', label: '(UTC-03:00) Montevideo' },
  { value: 'America/Cayenne', label: '(UTC-03:00) Cayenne, Fortaleza' },
  { value: 'America/Godthab', label: '(UTC-03:00) Greenland' },

  // Europe
  { value: 'Europe/London', label: '(UTC+00:00) Dublin, Edinburgh, Lisbon, London' },
  { value: 'Europe/Paris', label: '(UTC+01:00) Brussels, Copenhagen, Madrid, Paris' },
  { value: 'Europe/Berlin', label: '(UTC+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna' },
  { value: 'Europe/Budapest', label: '(UTC+01:00) Belgrade, Bratislava, Budapest, Ljubljana, Prague' },
  { value: 'Europe/Warsaw', label: '(UTC+01:00) Sarajevo, Skopje, Warsaw, Zagreb' },
  { value: 'Europe/Istanbul', label: '(UTC+02:00) Athens, Bucharest, Istanbul' },
  { value: 'Europe/Moscow', label: '(UTC+04:00) Moscow, St. Petersburg, Volgograd' },
  { value: 'Europe/Kiev', label: '(UTC+02:00) Helsinki, Kyiv, Riga, Sofia, Tallinn, Vilnius' },
  { value: 'Europe/Minsk', label: '(UTC+03:00) Minsk' },

  // Africa & Middle East
  { value: 'Africa/Cairo', label: '(UTC+02:00) Cairo' },
  { value: 'Africa/Johannesburg', label: '(UTC+02:00) Harare, Pretoria' },
  { value: 'Africa/Lagos', label: '(UTC+01:00) West Central Africa' },
  { value: 'Africa/Nairobi', label: '(UTC+03:00) Nairobi' },
  { value: 'Africa/Casablanca', label: '(UTC+00:00) Casablanca' },
  { value: 'Africa/Windhoek', label: '(UTC+01:00) Windhoek' },
  { value: 'Asia/Jerusalem', label: '(UTC+02:00) Jerusalem' },
  { value: 'Asia/Beirut', label: '(UTC+02:00) Beirut' },
  { value: 'Asia/Damascus', label: '(UTC+02:00) Damascus' },
  { value: 'Asia/Amman', label: '(UTC+02:00) Amman' },
  { value: 'Asia/Baghdad', label: '(UTC+03:00) Baghdad' },
  { value: 'Asia/Riyadh', label: '(UTC+03:00) Kuwait, Riyadh' },
  { value: 'Asia/Tehran', label: '(UTC+03:30) Tehran' },
  { value: 'Asia/Dubai', label: '(UTC+04:00) Abu Dhabi, Muscat' },

  // Asia
  { value: 'Asia/Tokyo', label: '(UTC+09:00) Osaka, Sapporo, Tokyo' },
  { value: 'Asia/Seoul', label: '(UTC+09:00) Seoul' },
  { value: 'Asia/Shanghai', label: '(UTC+08:00) Beijing, Chongqing, Hong Kong, Urumqi' },
  { value: 'Asia/Taipei', label: '(UTC+08:00) Taipei' },
  { value: 'Asia/Singapore', label: '(UTC+08:00) Kuala Lumpur, Singapore' },
  { value: 'Asia/Bangkok', label: '(UTC+07:00) Bangkok, Hanoi, Jakarta' },
  { value: 'Asia/Yangon', label: '(UTC+06:30) Yangon' },
  { value: 'Asia/Dhaka', label: '(UTC+06:00) Dhaka' },
  { value: 'Asia/Karachi', label: '(UTC+05:00) Islamabad, Karachi' },
  { value: 'Asia/Kolkata', label: '(UTC+05:30) Chennai, Kolkata, Mumbai, New Delhi' },
  { value: 'Asia/Colombo', label: '(UTC+05:30) Sri Jayewardenepura Kotte' },
  { value: 'Asia/Kathmandu', label: '(UTC+05:45) Kathmandu' },
  { value: 'Asia/Kabul', label: '(UTC+04:30) Kabul' },
  { value: 'Asia/Tashkent', label: '(UTC+05:00) Tashkent' },
  { value: 'Asia/Yerevan', label: '(UTC+04:00) Yerevan' },
  { value: 'Asia/Baku', label: '(UTC+04:00) Baku' },
  { value: 'Asia/Tbilisi', label: '(UTC+04:00) Tbilisi' },
  { value: 'Asia/Almaty', label: '(UTC+06:00) Astana' },
  { value: 'Asia/Yekaterinburg', label: '(UTC+06:00) Yekaterinburg' },
  { value: 'Asia/Novosibirsk', label: '(UTC+07:00) Novosibirsk' },
  { value: 'Asia/Krasnoyarsk', label: '(UTC+08:00) Krasnoyarsk' },
  { value: 'Asia/Irkutsk', label: '(UTC+09:00) Irkutsk' },
  { value: 'Asia/Yakutsk', label: '(UTC+10:00) Yakutsk' },
  { value: 'Asia/Vladivostok', label: '(UTC+11:00) Vladivostok' },
  { value: 'Asia/Magadan', label: '(UTC+12:00) Magadan' },
  { value: 'Asia/Ulaanbaatar', label: '(UTC+08:00) Ulaanbaatar' },

  // Australia & Pacific
  { value: 'Australia/Sydney', label: '(UTC+10:00) Canberra, Melbourne, Sydney' },
  { value: 'Australia/Brisbane', label: '(UTC+10:00) Brisbane' },
  { value: 'Australia/Perth', label: '(UTC+08:00) Perth' },
  { value: 'Australia/Adelaide', label: '(UTC+09:30) Adelaide' },
  { value: 'Australia/Darwin', label: '(UTC+09:30) Darwin' },
  { value: 'Australia/Hobart', label: '(UTC+10:00) Hobart' },
  { value: 'Pacific/Auckland', label: '(UTC+12:00) Auckland, Wellington' },
  { value: 'Pacific/Fiji', label: '(UTC+12:00) Fiji, Marshall Islands' },
  { value: 'Pacific/Guadalcanal', label: '(UTC+11:00) Solomon Islands, New Caledonia' },
  { value: 'Pacific/Port_Moresby', label: '(UTC+10:00) Guam, Port Moresby' },
  { value: 'Pacific/Tongatapu', label: '(UTC+13:00) Nuku\'alofa' },
  { value: 'Pacific/Apia', label: '(UTC+13:00) Samoa' },

  // Atlantic
  { value: 'Atlantic/Azores', label: '(UTC-01:00) Azores' },
  { value: 'Atlantic/Cape_Verde', label: '(UTC-01:00) Cape Verde' },
  { value: 'Atlantic/Reykjavik', label: '(UTC+00:00) Monrovia, Reykjavik' },

  // Indian Ocean
  { value: 'Indian/Mauritius', label: '(UTC+04:00) Port Louis' },
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

  const {
    isEnabled: isRecaptchaEnabled,
    executeRecaptcha,
    resetRecaptcha,
  } = useRecaptcha('register');

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
      // Execute reCAPTCHA if enabled
      let recaptchaToken = null;
      if (isRecaptchaEnabled) {
        recaptchaToken = await executeRecaptcha();
        if (!recaptchaToken) {
          toast.error('Security verification failed. Please try again.');
          setIsLoading(false);
          return;
        }
      }

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
        recaptcha_token: recaptchaToken,
      }, () => navigate('/ui/dashboard'));

      toast.success('Organization registered successfully!');
    } catch (error) {
      // Reset reCAPTCHA on error so user can retry
      resetRecaptcha();
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
                          <SelectContent className="max-h-[300px]">
                            {commonTimezones.map((tz) => (
                              <SelectItem key={tz.value} value={tz.value}>
                                {tz.label}
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
                        {errors.admin?.email ? (
                          <p className="text-sm text-destructive">{errors.admin.email.message}</p>
                        ) : (
                          getEmailValidationMessage()
                        )}
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
