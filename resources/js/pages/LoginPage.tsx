import React from 'react';
import { AuroraBackgroundProvider } from '@nauverse/react-aurora-background';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export const LoginPage: React.FC = () => {
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
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <label htmlFor="email" className="text-sm font-medium">
                                Email
                            </label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="admin@example.com"
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
                                className="w-full"
                            />
                        </div>
                        <Button className="w-full" size="lg">
                            Sign In
                        </Button>
                        <div className="text-center text-sm text-gray-600">
                            <a href="#" className="hover:underline">
                                Forgot your password?
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};