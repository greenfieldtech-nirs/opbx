/**
 * useAudioPlayer Hook
 *
 * Custom hook for managing audio playback state and controls.
 * Handles play/pause, cleanup, and error handling.
 */

import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';

interface UseAudioPlayerReturn {
  currentlyPlaying: number | null;
  audioElement: HTMLAudioElement | null;
  play: (recordingId: number, audioSrc: string) => Promise<void>;
  pause: () => void;
  isPlaying: (recordingId: number) => boolean;
}

/**
 * Stop an audio element on purpose. Handlers are detached first: clearing the
 * source makes the browser fire an 'error' event that is not a playback failure.
 */
function stopAudio(audio: HTMLAudioElement): void {
  audio.onended = null;
  audio.onerror = null;
  audio.pause();
  audio.removeAttribute('src');
  audio.load();
}

export function useAudioPlayer(): UseAudioPlayerReturn {
  const [currentlyPlaying, setCurrentlyPlaying] = useState<number | null>(null);
  const [audioElement, setAudioElement] = useState<HTMLAudioElement | null>(null);

  // Cleanup audio when component unmounts
  useEffect(() => {
    return () => {
      if (audioElement) {
        stopAudio(audioElement);
      }
    };
  }, [audioElement]);

  const pause = useCallback(() => {
    if (audioElement) {
      stopAudio(audioElement);
      setCurrentlyPlaying(null);
      setAudioElement(null);
    }
  }, [audioElement]);

  const play = useCallback(async (recordingId: number, audioSrc: string) => {
    try {
      // Stop any currently playing audio
      if (audioElement) {
        stopAudio(audioElement);
        setAudioElement(null);
      }

      const audio = new Audio(audioSrc);

      audio.onended = () => {
        setCurrentlyPlaying(null);
        setAudioElement(null);
      };

      audio.onerror = () => {
        toast.error('Failed to play recording');
        setCurrentlyPlaying(null);
        setAudioElement(null);
      };

      setAudioElement(audio);
      await audio.play();
      setCurrentlyPlaying(recordingId);
    } catch (error) {
      // A play() interrupted by pause/stop rejects with AbortError: not a failure.
      if (!(error instanceof DOMException && error.name === 'AbortError')) {
        toast.error('Failed to start playback');
      }
    }
  }, [audioElement]);

  const isPlaying = useCallback((recordingId: number) => {
    return currentlyPlaying === recordingId;
  }, [currentlyPlaying]);

  return {
    currentlyPlaying,
    audioElement,
    play,
    pause,
    isPlaying,
  };
}

export default useAudioPlayer;
