import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AuroraBackgroundProvider } from '@nauverse/react-aurora-background';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useToast } from '@/hooks/use-toast';

export const LoginPage: React.FC = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const { toast } = useToast();
    const navigate = useNavigate();

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        
        if (!email || !password) {
            toast({
                title: 'Error',
                description: 'Please enter both email and password.',
                variant: 'destructive',
            });
            return;
        }

        setIsLoading(true);

        try {
            const response = await fetch('/api/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({ email, password }),
            });

            if (response.ok) {
                toast({
                    title: 'Success',
                    description: 'Login successful. Redirecting...',
                });
                navigate('/dashboard');
            } else {
                const data = await response.json();
                toast({
                    title: 'Error',
                    description: data.message || 'Invalid credentials.',
                    variant: 'destructive',
                });
            }
        } catch (error) {
            toast({
                title: 'Error',
                description: 'An unexpected error occurred. Please try again.',
                variant: 'destructive',
            });
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex">
            {/* Left side - Aurora Background */}
            <div className="flex-1 relative overflow-hidden flex items-center justify-center">
                <AuroraBackgroundProvider
                    colors={['#3A29FF', '#FF94B4', '#FF3232']}
                    numBubbles={4}
                    animDuration={5}
                    blurAmount="10vw"
                    bgColor="#3f5efb"
                    useRandomness={false}
                    className="w-full h-full flex items-center justify-center"
                >
                    <div className="text-white text-center">
                        <h1 className="text-6xl font-bold mb-4">OpBX</h1>
                        <p className="text-xl opacity-90">Cloud PBX Administration</p>
                    </div>
                </AuroraBackgroundProvider>
            </div>

            {/* Right side - Login Form */}
            <div className="flex-1 flex items-center justify-center p-8 bg-gray-50">
                <div className="w-full max-w-md bg-white rounded-lg shadow-lg p-8">
                    <div className="space-y-1 mb-6">
                        <h2 className="text-2xl font-bold text-center">Sign In</h2>
                        <p className="text-gray-600 text-center">
                            Enter your credentials to access the PBX administration
                        </p>
                    </div>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <label htmlFor="email" className="text-sm font-medium">
                                Email
                            </label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="admin@example.com"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                disabled={isLoading}
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label htmlFor="password" className="text-sm font-medium">
                                Password
                            </label>
                            <Input
                                id="password"
                                type="password"
                                placeholder="Enter your password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                disabled={isLoading}
                                className="w-full"
                            />
                        </div>
                        <Button 
                            type="submit" 
                            className="w-full" 
                            size="lg"
                            disabled={isLoading}
                        >
                            {isLoading ? 'Signing in...' : 'Sign In'}
                        </Button>
                        <div className="text-center text-sm text-gray-600">
                            <a href="#" className="hover:underline">
                                Forgot your password?
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
};
