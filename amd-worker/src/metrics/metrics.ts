export interface Metrics {
    activeStreams: number;
    maxStreams: number;
    totalDetections: number;
    detectionBreakdown: {
        voicemail: number;
        human: number;
        unknown: number;
    };
    totalDetectionTimeMs: number;
    modelLoaded: boolean;
    startTime: number;
    errorsTotal: number;
    avgDetectionTimeMs?: number;
    uptime_seconds?: number;
}

const metrics: Metrics = {
    activeStreams: 0,
    maxStreams: 0,
    totalDetections: 0,
    detectionBreakdown: {
        voicemail: 0,
        human: 0,
        unknown: 0,
    },
    totalDetectionTimeMs: 0,
    modelLoaded: false,
    startTime: Date.now(),
    errorsTotal: 0,
};

export function setMaxStreams(max: number): void {
    metrics.maxStreams = max;
}

export function setModelLoaded(loaded: boolean): void {
    metrics.modelLoaded = loaded;
}

export function incrementActiveStreams(): void {
    metrics.activeStreams++;
}

export function decrementActiveStreams(): void {
    metrics.activeStreams = Math.max(0, metrics.activeStreams - 1);
}

export function incrementErrors(): void {
    metrics.errorsTotal++;
}

export function recordDetection(result: string, detectionTimeMs: number): void {
    metrics.totalDetections++;
    metrics.totalDetectionTimeMs += detectionTimeMs;
    if (result === 'voicemail') {
        metrics.detectionBreakdown.voicemail++;
    } else if (result === 'human') {
        metrics.detectionBreakdown.human++;
    } else {
        metrics.detectionBreakdown.unknown++;
    }
}

export function getMetrics(): Metrics {
    const avgDetectionTimeMs = metrics.totalDetections > 0
        ? Math.round(metrics.totalDetectionTimeMs / metrics.totalDetections)
        : 0;

    return {
        ...metrics,
        avgDetectionTimeMs,
        uptime_seconds: Math.floor((Date.now() - metrics.startTime) / 1000),
    };
}
