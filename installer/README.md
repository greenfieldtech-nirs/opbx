# OPBX installer

Guided first-time installer, reconfigure and reset for OPBX. Start it from the repository root with `./install.sh`.
User-facing description: the Installation section of the top-level [README](../README.md).

## Run

```bash
./install.sh               # install (or, on an existing install: Change settings / Start over / Exit; resumes an interrupted install)
./install.sh reconfigure   # public address; WAF off / monitor / block
./install.sh reset         # delete data and generated config
```

The host needs only Docker with the Compose plugin (or Podman with a Docker-compatible socket).

## How it works

`install.sh` checks that `docker`, `docker compose` and the daemon socket work, builds the image `opbx-installer:local`
from `installer/Dockerfile` (Node 26 on Alpine, with the docker CLI, Compose plugin, `openssl` and `bash`), and runs it with:

- the container daemon socket mounted (the Podman socket path is detected),
- the repository mounted at the same absolute path as on the host (`-v "$PWD:$PWD"`), so bind-mount paths in the compose
  files mean the same thing to the daemon and to the installer,
- `HOST_UID`, `HOST_GID`, `OPBX_REPO` and `OPBX_CLIENT_OS` in the environment, so files are owned by the invoking user.

Inside the container `src/main.js` detects the install state from `.env` (`none`, `incomplete`, `complete`), picks the
action, and calls the mode in `src/modes/`. The installer runs `docker` and `docker compose` commands against the host
daemon. It writes `.env`, `docker-compose.yml` and `docker/safeline/.env` atomically with mode 600 and never prints secrets
except the owner password, once, in the final summary (also saved to `opbx-install-summary.txt`).

Set `OPBX_DEBUG=1` to print stack traces on failure.

## Develop and test

Unit tests use `node --test` and need no daemon. Run them with the repository mounted:

```bash
docker run --rm -v "$PWD":/repo -w /repo/installer node:26-alpine sh -c 'npm ci && npm test'
```

`npm ci` writes `installer/node_modules/` on the host (it is not git-ignored; do not commit it). Tests use fakes for
Docker and the UI (see `test/fixtures/` for the SafeLine env template and a `reset-admin` sample output). To try
changes end to end, run `./install.sh` against a scratch checkout, never against a stack that holds data you need.

## Module map

| File | Purpose |
|---|---|
| `src/main.js` | Entry point: parses the mode, detects state, `chooseAction`, the Change settings / Start over / Exit menu |
| `src/modes/install.js` | Install and resume: questions, secrets, config files, SafeLine host steps, build, start, owner, checks, summary |
| `src/modes/reconfigure.js` | Change the public address and WAF settings; rewrites only owned keys, restarts affected services |
| `src/modes/reset.js` | List what is destroyed, optional DB backup, confirmation phrase, delete data and config, offer to reuse answers |
| `src/questions.js` | The interview (mode, address, tenancy, email, Auth0, reCAPTCHA, UserCheck, logs, WAF, advanced) and review |
| `src/validate.js` | Validators: public URL, email, port, CIDR list, safe absolute path |
| `src/derive.js` | Maps answers to `.env` values and back (`deriveEnv`, `answersFromEnv`); `OWNED_KEYS` for reconfigure |
| `src/envfile.js` | Parse and render `.env` from the template, atomic write with mode and ownership |
| `src/secrets.js` | Generates OPBX, SafeLine and owner-password secrets |
| `src/safeline.js` | SafeLine state-dir commands and checks, writes `docker/safeline/.env`, detector check |
| `src/hoststep.js` | Pause-and-check helper for steps the user must run on the host |
| `src/stack.js` | Build images, composer install, start the stack and wait for health, create the owner, reset the SafeLine admin |
| `src/verify.js` | Post-install checks against the running stack (service health, WebSocket upgrade, WAF) |
| `src/summary.js` | Proxy snippet, install summary text, writes `opbx-install-summary.txt` |
| `src/detect.js` | Install state: `none`, `incomplete` or `complete` |
| `src/platform.js` | Detects Docker or Podman, SELinux, OS and architecture from `docker info`; `isPortFree` |
| `src/docker.js` | Thin wrapper over the `docker` and `docker compose` CLIs (injectable for tests) |
| `src/ui.js` | `@clack/prompts` wrapper; Ctrl-C handling and state-aware cancel messages |
| `test/*.test.js`, `test/fixtures/` | Unit tests per module and their fixtures |
