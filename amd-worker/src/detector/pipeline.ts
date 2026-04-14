import { Detector, DetectionResult, AudioSegment, ResultType } from './types';
import { BeepMlDetector } from './beep-ml';
import { ToneEnergyDetector } from './tone-energy';

export type DetectorResultHandler = (result: DetectionResult) => void | Promise<void>;

export class DetectionPipeline {
    private detectors: Detector[] = [];
    private beepMlDetector: BeepMlDetector | null = null;
    private resolved = false;
    private timeoutMs: number;
    private onResult: DetectorResultHandler;

    constructor(
        detectorNames: string[],
        timeoutMs: number,
        onResult: DetectorResultHandler,
        beepMl?: BeepMlDetector
    ) {
        this.timeoutMs = timeoutMs;
        this.onResult = onResult;
        if (beepMl && detectorNames.includes('beep_ml')) {
            this.beepMlDetector = beepMl;
            this.detectors.push(beepMl);
        }
        if (detectorNames.includes('tone_energy')) {
            this.detectors.push(new ToneEnergyDetector());
        }
    }

    reset(): void {
        this.resolved = false;
        for (const detector of this.detectors) {
            detector.reset();
        }
    }

    getTimeoutMs(): number {
        return this.timeoutMs;
    }

    async processSegment(segment: AudioSegment): Promise<void> {
        if (this.resolved) {
            return;
        }

        for (const detector of this.detectors) {
            if (this.resolved) {
                break;
            }

            let result: DetectionResult | null = null;
            if (detector instanceof BeepMlDetector) {
                result = await detector.processAsync(segment);
            } else {
                result = detector.process(segment);
            }

            if (result && result.result !== ResultType.UNKNOWN) {
                this.resolved = true;
                await this.onResult(result);
                break;
            }
        }
    }

    isResolved(): boolean {
        return this.resolved;
    }
}
