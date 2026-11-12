import * as p from '@clack/prompts';

// Thin wrapper over @clack/prompts: every prompt unwraps a Ctrl-C into a clean exit (130).
export const state = { configWritten: false };

export function cancelled(value) {
  if (!p.isCancel(value)) return value;
  p.outro(state.configWritten
    ? 'Installation cancelled. Your answers are kept: run ./install.sh again to resume.'
    : 'Installation cancelled. Nothing was changed.');
  process.exit(130);
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
