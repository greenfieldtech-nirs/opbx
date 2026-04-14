import http from 'http';
import { WebSocketServer } from 'ws';
import { config } from './config';
import { StreamHandler } from './stream/handler';
import { OnnxModel } from './model/onnx';
import { BeepMlDetector } from './detector/beep-ml';
import * as metrics from './metrics/metrics';

async function main(): Promise<void> {
    console.log({ level: 'info', msg: 'AMD Worker starting', version: '1.0.0' });

    metrics.setMaxStreams(config.maxConcurrentStreams);

    // Load ONNX model
    const onnxModel = new OnnxModel(config.modelPath);
    let beepMlDetector: BeepMlDetector | null = null;
    try {
        await onnxModel.load();
        metrics.setModelLoaded(true);
        beepMlDetector = new BeepMlDetector(onnxModel);
        console.log({ level: 'info', msg: 'ONNX model loaded', path: config.modelPath });
    } catch (err) {
        metrics.setModelLoaded(false);
        console.error({
            level: 'error',
            msg: 'Failed to load ONNX model, ML detector disabled',
            path: config.modelPath,
            error: (err as Error).message,
        });
    }

    const streamHandler = new StreamHandler({
        maxConcurrentStreams: config.maxConcurrentStreams,
        defaultTimeoutMs: config.defaultTimeoutSeconds * 1000,
        detectors: config.detectors,
        beepMlDetector,
    });

    // WebSocket server for Cloudonix streams
    const wsServer = new WebSocketServer({
        port: config.websocketPort,
        path: '/ws/detect',
    });

    wsServer.on('connection', (ws) => {
        streamHandler.handleConnection(ws);
    });

    wsServer.on('error', (err) => {
        console.error({ level: 'error', msg: 'WebSocket server error', error: err.message });
        metrics.incrementErrors();
    });

    // HTTP server for health/metrics
    const httpServer = http.createServer((req, res) => {
        if (req.url === '/health' && req.method === 'GET') {
            const m = metrics.getMetrics();
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                status: 'healthy',
                model_loaded: m.modelLoaded,
                active_streams: m.activeStreams,
                max_streams: m.maxStreams,
                total_detections: m.totalDetections,
                detection_breakdown: m.detectionBreakdown,
                avg_detection_time_ms: (m as any).avgDetectionTimeMs ?? 0,
                uptime_seconds: (m as any).uptime_seconds ?? 0,
            }));
            return;
        }
        res.writeHead(404);
        res.end('Not Found');
    });

    httpServer.listen(config.httpPort, () => {
        console.log({
            level: 'info',
            msg: 'AMD Worker started',
            websocket_port: config.websocketPort,
            http_port: config.httpPort,
            max_streams: config.maxConcurrentStreams,
            timeout_seconds: config.defaultTimeoutSeconds,
            detectors: config.detectors,
        });
    });
}

main().catch((err) => {
    console.error({ level: 'fatal', msg: 'AMD Worker failed to start', error: err.message });
    process.exit(1);
});
