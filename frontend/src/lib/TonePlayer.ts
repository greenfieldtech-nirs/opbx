import { DEFAULT_COUNTRY, getToneSequence, ToneElement, ToneName } from './tone-indications';

const CONTINUOUS_MS = 30_000; // How long to schedule a continuous (duration=0) tone.
const GAIN_RAMP_SECONDS = 0.01;

export class TonePlayer {
  private audioCtx: AudioContext | null = null;
  private nextPhraseTimeoutId: number | null = null;

  play(toneName: ToneName, country: string): void {
    this.stop();

    const sequence =
      getToneSequence(country, toneName) ?? getToneSequence(DEFAULT_COUNTRY, toneName);
    if (!sequence || sequence.length === 0) {
      return;
    }

    try {
      this.ensureAudioContext();
      if (!this.audioCtx) {
        return;
      }

      this.schedulePhrase(sequence, this.audioCtx.currentTime);
    } catch (error) {
      console.error('[TonePlayer] Failed to play tone:', error);
    }
  }

  stop(): void {
    if (this.nextPhraseTimeoutId !== null) {
      window.clearTimeout(this.nextPhraseTimeoutId);
      this.nextPhraseTimeoutId = null;
    }

    if (this.audioCtx && this.audioCtx.state !== 'closed') {
      try {
        this.audioCtx.close();
      } catch {
        // Context may already be closing; ignore.
      }
    }
    this.audioCtx = null;
  }

  private ensureAudioContext(): void {
    if (this.audioCtx) {
      return;
    }

    const AudioContextClass =
      (window as any).AudioContext || (window as any).webkitAudioContext;
    if (!AudioContextClass) {
      throw new Error('Web Audio API not supported in this browser');
    }

    this.audioCtx = new AudioContextClass();
  }

  private schedulePhrase(sequence: ToneElement[], phraseStartTime: number): void {
    if (!this.audioCtx) {
      return;
    }

    const normalizedSequence = sequence.map((element) => ({
      ...element,
      durationMs: element.durationMs === 0 ? CONTINUOUS_MS : element.durationMs,
    }));

    const phraseDurationMs = normalizedSequence.reduce(
      (sum, element) => sum + element.durationMs,
      0
    );
    if (phraseDurationMs === 0) {
      return;
    }

    let timeOffset = 0;
    for (const element of normalizedSequence) {
      const elementStart = phraseStartTime + timeOffset / 1000;
      const elementDuration = element.durationMs / 1000;

      if (element.freqs.length > 0) {
        const gain = this.audioCtx.createGain();
        gain.gain.setValueAtTime(0, elementStart);
        gain.gain.linearRampToValueAtTime(
          0.1,
          elementStart + GAIN_RAMP_SECONDS
        );
        gain.gain.setValueAtTime(
          0.1,
          elementStart + elementDuration - GAIN_RAMP_SECONDS
        );
        gain.gain.linearRampToValueAtTime(0, elementStart + elementDuration);
        gain.connect(this.audioCtx.destination);

        for (const freq of element.freqs) {
          const oscillator = this.audioCtx.createOscillator();
          oscillator.type = 'sine';
          oscillator.frequency.setValueAtTime(freq, elementStart);
          oscillator.connect(gain);
          oscillator.start(elementStart);
          oscillator.stop(elementStart + elementDuration);
        }
      }

      timeOffset += element.durationMs;
    }

    const allOnce = sequence.every((element) => element.once);
    if (!allOnce) {
      const nextPhraseStartTime = phraseStartTime + phraseDurationMs / 1000;
      const lookaheadMs = 25;
      const delayMs = Math.max(
        0,
        (nextPhraseStartTime - this.audioCtx.currentTime) * 1000 - lookaheadMs
      );
      this.nextPhraseTimeoutId = window.setTimeout(() => {
        this.schedulePhrase(sequence, nextPhraseStartTime);
      }, delayMs);
    }

    if (this.audioCtx.state === 'suspended') {
      this.audioCtx.resume().catch((error) => {
        console.warn('[TonePlayer] Could not resume AudioContext:', error);
      });
    }
  }
}
