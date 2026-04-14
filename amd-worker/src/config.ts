export interface Config {
    websocketPort: number;
    httpPort: number;
    modelPath: string;
    maxConcurrentStreams: number;
    defaultTimeoutSeconds: number;
    logLevel: string;
    detectors: string[];
    redisHost: string;
    redisPort: number;
    redisPassword: string | undefined;
}

export const config: Config = {
    websocketPort: parseInt(process.env.AMD_WEBSOCKET_PORT ?? '8082', 10),
    httpPort: parseInt(process.env.AMD_HTTP_PORT ?? '8083', 10),
    modelPath: process.env.AMD_MODEL_PATH ?? './models/beep_detector.onnx',
    maxConcurrentStreams: parseInt(process.env.AMD_MAX_CONCURRENT_STREAMS ?? '100', 10),
    defaultTimeoutSeconds: 45, // Hardcoded per requirements
    logLevel: process.env.AMD_LOG_LEVEL ?? 'info',
    detectors: (process.env.AMD_DETECTORS ?? 'beep_ml,tone_energy').split(',').map(s => s.trim()),
    redisHost: process.env.REDIS_HOST ?? 'redis',
    redisPort: parseInt(process.env.REDIS_PORT ?? '6379', 10),
    redisPassword: process.env.REDIS_PASSWORD,
};
