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

export function useAudioPlayer(): UseAudioPlayerReturn {
  const [currentlyPlaying, setCurrentlyPlaying] = useState<number | null>(null);
  const [audioElement, setAudioElement] = useState<HTMLAudioElement | null>(null);

  // Cleanup audio when component unmounts
  useEffect(() => {
    return () => {
      if (audioElement) {
        audioElement.pause();
        audioElement.src = '';
        setAudioElement(null);
      }
    };
  }, [audioElement]);

  const pause = useCallback(() => {
    if (audioElement) {
      audioElement.pause();
      setCurrentlyPlaying(null);
      setAudioElement(null);
    }
  }, [audioElement]);

  const play = useCallback(async (recordingId: number, audioSrc: string) => {
    try {
      // Stop any currently playing audio
      if (audioElement) {
        audioElement.pause();
        audioElement.src = '';
        setAudioElement(null);
      }

      const audio = new Audio(audioSrc);

      audio.addEventListener('ended', () => {
        setCurrentlyPlaying(null);
        setAudioElement(null);
      });

      audio.addEventListener('error', () => {
        toast.error('Failed to play recording');
        setCurrentlyPlaying(null);
        setAudioElement(null);
      });

      setAudioElement(audio);
      await audio.play();
      setCurrentlyPlaying(recordingId);
    } catch (error) {
      toast.error('Failed to start playback');
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
