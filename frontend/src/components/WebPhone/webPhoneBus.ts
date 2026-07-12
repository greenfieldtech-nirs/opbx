// Minimal pub/sub so the Live Calls page can command the app-wide Web Phone
// (they are mounted in different React trees). Payload is the sentinel
// destination string the Web Phone should auto-dial.

type CoachHandler = (destination: string) => void;

const handlers = new Set<CoachHandler>();

export function startCoach(destination: string): void {
  handlers.forEach((handler) => handler(destination));
}

export function subscribeCoach(handler: CoachHandler): () => void {
  handlers.add(handler);
  return () => {
    handlers.delete(handler);
  };
}
