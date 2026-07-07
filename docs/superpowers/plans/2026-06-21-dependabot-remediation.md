# Dependabot Alert Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve all 59 open Dependabot alerts in `greenfieldtech-nirs/opbx` by upgrading the smallest set of dependencies, with verified tests and no breaking changes.

**Architecture:** Bulk-close alerts through targeted package upgrades across three ecosystems — Composer (`laravel/framework` + transitive Symfony/Guzzle), npm (`axios`/`vite`/`concurrently` in frontend and root), and Maven/npm in the AMD worker (merge existing PRs #39 and #40). Prioritize by actual runtime exploitability in the PBX context.

**Tech Stack:** Laravel 12 (PHP 8.4), React 18 + Vite, Java AMD worker (Vert.x 5), MySQL, Redis, Cloudonix CPaaS.

---

## Alert Inventory

| Severity | Count | Packages | Manifests |
|---|---|---|---|
| Critical | 1 | `shell-quote` | root `package-lock.json` |
| High | 24 | `axios` (×many), `laravel/framework`, `vite`, `symfony/mime` | `frontend/package-lock.json`, root `package-lock.json`, `composer.lock` |
| Moderate | 28 | `axios`, `symfony/*`, `react-router`, `guzzlehttp/psr7`, `launch-editor`, `io.vertx:vertx-core`, `ws`, `laravel/framework` | `frontend/package-lock.json`, `composer.lock`, `amd-worker/pom.xml`, `amd-worker/scripts/package-lock.json` |
| Low | 6 | `axios`, `symfony/polyfill-intl-idn`, `symfony/yaml` | `frontend/package-lock.json`, root `package-lock.json`, `composer.lock` |
| **Total** | **59** | | |

**Already-open Dependabot PRs:**
- PR #39 — `ws` in `amd-worker/scripts/package-lock.json`
- PR #40 — `io.vertx:vertx-core` in `amd-worker/pom.xml`

---

## Files to Change

- **Modify:** `composer.lock` (via `composer update`)
- **Modify:** `frontend/package-lock.json` (via `npm update`)
- **Modify:** root `package-lock.json` (via `npm update`)
- **Modify:** `amd-worker/pom.xml` (merge PR #40)
- **Modify:** `amd-worker/scripts/package-lock.json` (merge PR #39)
- **No `composer.json` changes required** — current constraints already permit patched versions.
- **No `frontend/package.json` changes required** unless `npm update` does not satisfy ranges.

---

## Task 1: P0 — Upgrade `laravel/framework` (CRLF injection, #91)

**Files:**
- Modify: `composer.lock`

**Risk context:** This is the only alert with plausible impact on the public webhook/API surface of the multi-tenant PBX. Fix first.

- [ ] **Step 1: Create a branch and back up the lockfile**

```bash
git checkout -b security/dependabot-remediation-2026-06
cp composer.lock composer.lock.bak
```

- [ ] **Step 2: Run the framework update**

```bash
composer update laravel/framework --with-all-dependencies
```

Expected: `laravel/framework` moves from `v12.58.0` to at least `v12.62.0`.

- [ ] **Step 3: Verify the update resolved the Laravel and transitive Symfony/Guzzle alerts**

```bash
composer audit
```

Expected: `laravel/framework` #91 and #92, plus `symfony/*`, `guzzlehttp/psr7`, and `symfony/polyfill-intl-idn` alerts are no longer reported.

- [ ] **Step 4: Run PHP tests**

```bash
./run-tests.sh
```

Expected: full suite passes.

- [ ] **Step 5: Commit**

```bash
git add composer.lock
git commit -m "security: upgrade laravel/framework to resolve Dependabot CRLF/signed-URL alerts"
rm composer.lock.bak
```

---

## Task 2: P1 — Close remaining Composer alerts (`symfony/yaml`, `jmespath`)

**Files:**
- Modify: `composer.lock`

**Risk context:** These are dev-only (`laravel/sail`) or S3-integration (`league/flysystem-aws-s3-v3`) transitive alerts. They do not sit on the public runtime surface.

- [ ] **Step 1: Update the remaining root requirements**

```bash
composer update laravel/sail league/flysystem-aws-s3-v3 --with-all-dependencies
```

Expected: `symfony/yaml` bumps to `v8.1.0` and `mtdowling/jmespath.php` to `v2.9.1`.

- [ ] **Step 2: Verify zero Composer advisories**

```bash
composer audit
```

Expected: `Found 0 security vulnerability advisories affecting installed composer packages`.

- [ ] **Step 3: Run PHP tests again**

```bash
./run-tests.sh
```

Expected: full suite passes.

- [ ] **Step 4: Commit**

```bash
git add composer.lock
git commit -m "security: update sail and aws-s3-v3 to close remaining composer dependabot alerts"
```

---

## Task 3: P1 — Bulk-upgrade `axios` in the React frontend

**Files:**
- Modify: `frontend/package-lock.json`

**Risk context:** `axios` ships in the browser bundle for every authenticated API call. SSRF/CRLF issues are low-impact because the app uses fixed base URLs, but the DoS/auth-bypass issues justify patching.

- [ ] **Step 1: Update axios and related frontend packages**

```bash
cd frontend
npm update axios react-router-dom vite
```

Expected: `axios` moves from `1.15.0` to at least `1.16.0`; `react-router-dom` and `vite` stay on latest compatible patches.

- [ ] **Step 2: Run npm audit**

```bash
npm audit --audit-level=low
```

Expected: no `axios`, `vite`, `react-router-dom`, or `launch-editor` findings remain.

- [ ] **Step 3: Static checks and build**

```bash
npm run type-check
npm run lint
npm run build
```

Expected: type-check and lint pass; production build succeeds.

- [ ] **Step 4: Commit**

```bash
cd ..
git add frontend/package-lock.json
git commit -m "security: upgrade axios/vite/react-router in frontend to close dependabot alerts"
```

---

## Task 4: P2 — Patch root dev tooling (`axios`, `vite`, `concurrently` → `shell-quote`)

**Files:**
- Modify: root `package-lock.json`

**Risk context:** Root dependencies are build/dev tooling only; no production runtime exposure. `shell-quote` is flagged Critical but is transitive via `concurrently`.

- [ ] **Step 1: Update root packages**

```bash
npm update axios vite concurrently
```

Expected: `shell-quote`, `axios`, and `vite` transitive versions move to latest compatible patches.

- [ ] **Step 2: Run root npm audit**

```bash
npm audit --audit-level=low
```

Expected: no findings remain.

- [ ] **Step 3: Commit**

```bash
git add package-lock.json
git commit -m "security: update root dev dependencies to close shell-quote/axios/vite alerts"
```

---

## Task 5: P1 — Merge AMD worker PRs #39 and #40

**Files:**
- Modify: `amd-worker/pom.xml` (PR #40)
- Modify: `amd-worker/scripts/package-lock.json` (PR #39)

**Risk context:** `vertx-core` is network-facing (WebSocket/HTTP listener for Cloudonix audio streams). `ws` is a test-only script but should still be patched.

- [ ] **Step 1: Review PR #40 diff**

Verify:
- Only `<vertx.version>` (or `vertx-core` directly) is changed.
- New version is `5.0.12` or later.
- No unrelated dependency drift.

- [ ] **Step 2: Validate Vert.x upgrade**

```bash
cd amd-worker
mvn compile
mvn package -DskipTests -B
mvn dependency:tree -Dincludes=io.vertx:vertx-core
mvn test
```

Expected: build and tests pass; `vertx-core:5.0.12` is resolved.

- [ ] **Step 3: Review PR #39 diff**

Verify:
- `ws` resolves to `8.20.1` or later in `package-lock.json`.
- No unrelated dependency drift.

- [ ] **Step 4: Validate `ws` upgrade**

```bash
cd amd-worker/scripts
npm audit
npm ls ws
npx tsc --noEmit
```

Expected: `npm audit` shows no `ws` finding; `npm ls ws` shows `>= 8.20.1`; TypeScript compiles.

- [ ] **Step 5: Merge both PRs and confirm Dependabot alerts close**

Use GitHub UI or `gh` if available. After merge, re-check the Dependabot page — alerts #61 and #62 should close automatically.

- [ ] **Step 6: Commit reference**

If merging manually in this branch, cherry-pick or apply the changes, then:

```bash
git add amd-worker/pom.xml amd-worker/scripts/package-lock.json
git commit -m "security: merge dependabot updates for vertx-core and ws"
```

---

## Task 6: Verification — Zero open advisories

- [ ] **Step 1: Composer final audit**

```bash
composer audit
```

Expected: 0 advisories.

- [ ] **Step 2: npm final audits**

```bash
npm audit --audit-level=low
cd frontend && npm audit --audit-level=low
```

Expected: 0 vulnerabilities in both.

- [ ] **Step 3: Re-check GitHub Dependabot page**

Navigate to `https://github.com/greenfieldtech-nirs/opbx/security/dependabot`.

Expected: **0 open alerts** (or only alerts already determined to be unfixable/accepted).

---

## Task 7: Regression Testing

- [ ] **Step 1: Full PHP test suite**

```bash
./run-tests.sh
```

- [ ] **Step 2: PHP lint**

```bash
vendor/bin/pint
```

- [ ] **Step 3: Frontend build and lint**

```bash
cd frontend
npm run lint
npm run type-check
npm run build
```

- [ ] **Step 4: AMD worker build**

```bash
cd amd-worker
mvn package -DskipTests -B
```

- [ ] **Step 5: Smoke-test PBX-specific flows**

| Flow | How to test |
|---|---|
| Cloudonix voice webhooks | Send a test call event to `/api/voice/route` and verify CXML response. |
| Laravel API auth | Log in via React SPA; confirm token issuance and refresh. |
| Axios API calls | Dashboard loads data from `/api/*` endpoints without CORS/auth errors. |
| React Router navigation | Refresh a deep link (e.g., `/ui/ring-groups`) and confirm route resolves. |
| AMD worker health | `curl http://localhost:8080/health` returns healthy after JAR starts. |

---

## Rollback Plan

If any step introduces failures:

1. **Composer:** restore the backup lockfile and run `composer install`.
   ```bash
   cp composer.lock.bak composer.lock
   composer install
   ```
2. **npm:** revert `package-lock.json` and run `npm install`.
3. **AMD worker:** revert the PR merges or pom.xml/package-lock.json changes.

---

## Self-Review

1. **Spec coverage:** Every open alert from the inventory is addressed by one of Tasks 1–5. `laravel/framework` and transitive Symfony/Guzzle are covered by Tasks 1–2; frontend `axios`/`vite`/`react-router` are covered by Task 3; root `shell-quote`/`axios`/`vite` are covered by Task 4; AMD worker `vertx-core`/`ws` are covered by Task 5.
2. **Placeholder scan:** No TBD/TODO placeholders. Every step includes exact commands and expected outputs.
3. **Type consistency:** Package names and alert IDs match the inventory throughout.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-21-dependabot-remediation.md`.**

Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch fresh subagents per task, review between tasks, fast iteration.
2. **Inline Execution** — Execute tasks in this session using `executing-plans`, batch execution with checkpoints.

**Which approach?**
