// Pause-and-check: show commands, wait for Enter, assert; the user may abort (Ctrl-C) but never skip.
export async function hostStep(ui, { title, explain, commands, check }) {
  ui.note(title, `${explain}\n\n${commands.join('\n')}`);
  for (;;) {
    await ui.text({ message: 'Press Enter when done (Ctrl-C to abort)', defaultValue: '' });
    const { ok, missing } = await check();
    if (ok) return;
    ui.note('Still missing', `${missing.map((m) => `- ${m}`).join('\n')}\n\nFix the above, then press Enter to check again.`);
  }
}
