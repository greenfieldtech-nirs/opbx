import { spawn } from 'node:child_process';

function defaultExec(cmd, args, { input, stream, cwd, stdoutTo, onData } = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, { cwd, stdio: [input !== undefined ? 'pipe' : (stream ? 'inherit' : 'ignore'), stream ? 'inherit' : 'pipe', stream ? 'inherit' : 'pipe'] });
    if (!stdoutTo) child.stdout?.setEncoding('utf8');
    child.stderr?.setEncoding('utf8');
    child.stdin?.on('error', () => {}); // EPIPE when the child exits without reading input
    let stdout = '';
    let stderr = '';
    if (stdoutTo) child.stdout?.pipe(stdoutTo); // streamed, never buffered; stdoutTo is ended with the child
    else child.stdout?.on('data', (d) => { stdout += d; onData?.(d); });
    child.stderr?.on('data', (d) => { stderr += d; onData?.(d); });
    child.on('error', reject);
    child.on('close', (code) => resolve({ code: code ?? 1, stdout, stderr }));
    if (input !== undefined) child.stdin.end(input);
  });
}

// run(args, {input, stream, stdoutTo, onData}) -> {code, stdout, stderr}; compose() runs in OPBX_REPO.
export function createDocker({ exec = defaultExec, bin = 'docker' } = {}) {
  const run = (args, opts = {}) => exec(bin, args, opts);
  const compose = (args, opts = {}) =>
    run(['compose', ...args], { ...opts, cwd: process.env.OPBX_REPO || process.cwd() });
  return { run, compose };
}
