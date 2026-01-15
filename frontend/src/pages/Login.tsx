/**
 * Login Page
 *
 * User authentication page with email/password form
 * Design inspired by Hackerrank - clean two-column layout
 */

import {useState, useEffect} from 'react';
import {useNavigate} from 'react-router-dom';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {toast} from 'sonner';
import {useAuth} from '@/hooks/useAuth';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {Label} from '@/components/ui/label';
import {Card, CardContent, CardDescription, CardHeader, CardTitle} from '@/components/ui/card';
import {Checkbox} from '@/components/ui/checkbox';
import {AuroraBackgroundProvider} from '@nauverse/react-aurora-background';
import opbxLogo from '@/assets/opbx_logo.png';

// Form validation schema
const loginSchema = z.object({
    email: z.string().email('Invalid email address'),
    password: z.string().min(6, 'Password must be at least 6 characters'),
});

type LoginFormData = z.infer<typeof loginSchema>;

export default function Login() {
    const [isLoading, setIsLoading] = useState(false);
    const [rememberMe, setRememberMe] = useState(false);
    const {login, isAuthenticated, isLoading: authLoading} = useAuth();
    const navigate = useNavigate();

    // Redirect to dashboard if already authenticated
    useEffect(() => {
        if (!authLoading && isAuthenticated) {
            navigate('/dashboard', {replace: true});
        }
    }, [isAuthenticated, authLoading, navigate]);

    const {
        register,
        handleSubmit,
        formState: {errors},
    } = useForm<LoginFormData>({
        resolver: zodResolver(loginSchema),
    });

    const onSubmit = async (data: LoginFormData) => {
        setIsLoading(true);

        try {
            await login(data, () => navigate('/dashboard'));
            toast.success('Login successful!');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Login failed');
        } finally {
            setIsLoading(false);
        }
    };

    // Show loading while checking auth status
    if (authLoading) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-white">
                <div className="text-center">
                    <div
                        className="h-12 w-12 animate-spin rounded-full border-4 border-primary border-t-transparent mx-auto"/>
                    <p className="mt-4 text-muted-foreground">Loading...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-white" style={{fontFamily: 'Roboto, sans-serif'}}>
            <div className="grid lg:grid-cols-2 min-h-screen">
                {/* Left Side - Branding & Welcome */}
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
                            {/* Logo */}
                            <div className="flex items-center justify-center mb-8">
                                <img src={opbxLogo} alt="OPBX Logo" className="h-32 w-auto"/>
                            </div>

                            {/* Welcome Message */}
                            <div className="space-y-4 text-center">
                                <p className="text-xl text-blue-200 mt-4">
                                    Open-source Business PBX powered by Cloudonix
                                </p>
                                <p className="text-lg text-gray-400">
                                    Professional PBX solution for modern businesses
                                </p>
                            </div>
                        </div>
                    </AuroraBackgroundProvider>
                </div>

                {/* Right Side - Login Form */}
                <div className="flex flex-col justify-center p-12 bg-white">
                    <div className="max-w-md mx-auto w-full">
                        <Card className="border-0 shadow-none">
                            <CardHeader className="space-y-2 pb-8">
                                <CardTitle className="text-2xl font-bold text-left">
                                    Welcome back!
                                </CardTitle>
                                <CardDescription className="text-left">
                                    Login to your account to access your PBX admin panel
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="pt-0">
                                <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                                    {/* Email Field */}
                                    <div className="space-y-2">
                                        <Label htmlFor="email" className="text-base font-medium">
                                            Email
                                        </Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            placeholder="admin@example.com"
                                            disabled={isLoading}
                                            className="h-11"
                                            {...register('email')}
                                        />
                                        {errors.email && (
                                            <p className="text-sm text-destructive">{errors.email.message}</p>
                                        )}
                                    </div>

                                    {/* Password Field */}
                                    <div className="space-y-2">
                                        <Label htmlFor="password" className="text-base font-medium">
                                            Password
                                        </Label>
                                        <Input
                                            id="password"
                                            type="password"
                                            placeholder="Enter your password"
                                            disabled={isLoading}
                                            className="h-11"
                                            {...register('password')}
                                        />
                                        {errors.password && (
                                            <p className="text-sm text-destructive">{errors.password.message}</p>
                                        )}
                                    </div>

                                    {/* Remember Me & Forgot Password */}
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center space-x-2">
                                            <Checkbox
                                                id="remember"
                                                checked={rememberMe}
                                                onCheckedChange={setRememberMe}
                                                disabled={isLoading}
                                            />
                                            <Label
                                                htmlFor="remember"
                                                className="text-sm font-normal cursor-pointer"
                                            >
                                                Remember me
                                            </Label>
                                        </div>
                                        <a
                                            href="#"
                                            className="text-sm text-blue-600 hover:text-blue-800 font-medium"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                toast.info('Contact your administrator to reset password');
                                            }}
                                        >
                                            Forgot password?
                                        </a>
                                    </div>

                                    {/* Submit Button */}
                                    <Button type="submit" className="w-full h-11" disabled={isLoading}>
                                        {isLoading ? 'Signing in...' : 'Log In'}
                                    </Button>
                                </form>

                                {/* Footer Info */}
                                <div className="mt-8 text-center text-sm text-gray-600">
                                    <p>Made with ❤️ by <a href="https://cloudonix.com">Cloudonix</a><br/>No call home
                                        scripts included!</p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    );
}
