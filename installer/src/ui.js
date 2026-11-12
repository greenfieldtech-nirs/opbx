import * as p from '@clack/prompts';

// Thin wrapper over @clack/prompts: every prompt unwraps a Ctrl-C into a clean exit (130).
export const state = { configWritten: false, resetStarted: false, resetDone: false };

export const cancelMessage = () => (state.resetStarted
  ? 'Reset was interrupted part-way; run ./install.sh reset again.'
  : state.configWritten
  ? 'Installation cancelled. Your answers are kept: run ./install.sh again to resume.'
  : state.resetDone
  ? 'OPBX was reset and all its data deleted. Run ./install.sh to install again.'
  : 'Installation cancelled. Nothing was changed.');

export function cancelled(value) {
  if (!p.isCancel(value)) return value;
  p.outro(cancelMessage());
  process.exit(130);
}

// Ctrl-C outside a prompt (long builds): Node is PID 1 in the container and ignores SIGINT by default.
export function handleSigint({ exit = process.exit, out = (m) => p.outro(m) } = {}) {
  out(cancelMessage());
  exit(130);
}

const wrap = (fn) => async (opts) => cancelled(await fn(opts));

export const ui = {
  text: wrap(p.text),
  password: wrap(p.password),
  select: wrap(p.select),
  confirm: wrap(p.confirm),
  multiselect: wrap(p.multiselect),
  note: (title, body) => p.note(body, title),
  spinner: () => p.spinner(),
  cancelled,
};
