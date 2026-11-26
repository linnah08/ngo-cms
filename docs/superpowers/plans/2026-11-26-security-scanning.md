# Automated Security Scanning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Known library vulnerabilities and injection-style mistakes in our PHP are found automatically, reported visibly, and library fixes are proposed automatically.

**Architecture:** Dependabot (security updates only) proposes library fixes as PRs. A new `.github/workflows/security.yml` runs `composer audit` (fails the run) and Semgrep (SARIF → GitHub code scanning) on push to `main`, weekly and on demand. A PHPUnit test guards the workflow wiring, the same way `tests/ReleaseGateWorkflowTest.php` guards the release workflow.

**Tech Stack:** GitHub Actions, Dependabot, Composer 2 `audit`, Semgrep OSS CLI (pinned), PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-11-26-security-scanning-design.md`

## Global Constraints

- Work in the worktree `../oddminds-oss-wt/security-scanning` (branch `security-scanning`); never in the main checkout.
- Dependabot: **security updates only** — `open-pull-requests-limit: 0` for version updates (Dependabot still opens security PRs with this setting).
- Ecosystems: `composer` (`/`), `npm` (`/`), `github-actions` (`/`).
- Workflow triggers: `push` to `main`, `schedule` weekly Monday 06:00 UTC (`0 6 * * 1`), `workflow_dispatch`.
- `composer audit` must fail the job on any advisory; no `continue-on-error` anywhere in `security.yml`.
- Semgrep version pinned (exact version in the workflow, not `latest`); results uploaded with `github/codeql-action/upload-sarif`, `permissions: security-events: write`.
- No pushes, no tags, no repo-setting changes without the user's go-ahead (CLAUDE.md: commit locally, wait for "push").

## Review Focus

1. **Semgrep finds things → job fails before SARIF upload**, so nothing reaches the Security tab. Expected: the scan step never fails the job on findings; upload always runs (`if: always()`). Test: `test_semgrep_results_are_uploaded_even_when_findings_exist`.
2. **Scan includes `vendor/` / `node_modules/` / `tests/`** → hundreds of third-party alerts that bury ours. Expected: `.semgrepignore` excludes them. Test: `test_third_party_code_is_not_scanned`.
3. **A later YAML edit adds `continue-on-error` to the audit** → red signal silently gone. Test: `test_composer_audit_fails_the_run`.
4. **Dependabot config drifts into routine version bumps** → PR noise people learn to ignore. Test: `test_dependabot_only_opens_security_updates`.
5. **`composer update` in the worktree writes through the `vendor` symlink** into the main checkout other sessions use. Expected: Task 1 replaces the symlink with a real install first.

---

### Task 1: Fix CVE-2026-45802 (setasign/fpdi < 2.6.7)

**Files:**
- Modify: `composer.lock` (only `setasign/fpdi`)

- [ ] **Step 1:** In the worktree, `rm vendor && composer install --no-interaction` (replace the symlink so the update doesn't touch the main checkout's `vendor/`).
- [ ] **Step 2:** Run `composer audit` — Expected: 1 advisory, `setasign/fpdi`, CVE-2026-45802.
- [ ] **Step 3:** `composer update setasign/fpdi --with-dependencies` — `composer.json` unchanged; `git diff --stat` shows only `composer.lock`.
- [ ] **Step 4:** `composer audit` — Expected: `No security vulnerability advisories found.`
- [ ] **Step 5:** `php vendor/bin/phpunit` — Expected: same pass count as before the update, 0 failures (PDF generators in `includes/documents/` covered by existing tests).
- [ ] **Step 6:** Commit `composer.lock`: `fix(deps): update setasign/fpdi to fix CVE-2026-45802 (DoS)`.

### Task 2: Dependabot + security workflow + wiring test

**Files:**
- Create: `.github/dependabot.yml`
- Create: `.github/workflows/security.yml`
- Create: `.semgrepignore`
- Create: `.semgrep/ngo-cms.yml` (project rules)
- Test: `tests/Security/SecurityScanningWorkflowTest.php` (in the existing `Security` testsuite)

**Interfaces:**
- Produces: workflow job ids `audit` and `semgrep`; Semgrep config `--config p/php --config p/security-audit --config .semgrep/ngo-cms.yml`, SARIF file `semgrep.sarif`.

- [ ] **Step 1: Write the failing test** `SecurityScanningWorkflowTest` (string assertions on file contents, like `ReleaseGateWorkflowTest`):
  - `test_runs_on_push_weekly_and_on_demand`: `security.yml` contains `branches: [main]`, `cron: '0 6 * * 1'`, `workflow_dispatch`.
  - `test_composer_audit_fails_the_run`: contains `composer audit`; does not contain `continue-on-error`.
  - `test_semgrep_is_pinned`: matches `/semgrep==\d+\.\d+\.\d+/` (or a pinned `semgrep/semgrep:<x.y.z>` image).
  - `test_semgrep_results_are_uploaded_even_when_findings_exist`: contains `upload-sarif`, `if: always()`, `security-events: write`; the semgrep command is not run with `--error`.
  - `test_third_party_code_is_not_scanned`: `.semgrepignore` lines include `vendor/`, `node_modules/`, `tests/`.
  - `test_dependabot_only_opens_security_updates`: `dependabot.yml` contains each `package-ecosystem: "composer"|"npm"|"github-actions"` and `open-pull-requests-limit: 0` exactly 3 times.
- [ ] **Step 2:** `php vendor/bin/phpunit tests/Security/SecurityScanningWorkflowTest.php` — Expected: FAIL (files missing).
- [ ] **Step 3:** Create the four files per Global Constraints. `security.yml`: job `audit` (setup-php 8.4, `composer install --no-interaction`, `composer audit`, `npm audit --omit=dev`); job `semgrep` (python + `pip install semgrep==<pinned>`, `semgrep scan --sarif --output semgrep.sarif <configs>`, upload). Top-level `permissions: contents: read`, `security-events: write` on the semgrep job. `.semgrep/ngo-cms.yml` starts with two rules: `echo`/`print` of `$_GET[...]`/`$_POST[...]`/`$_REQUEST[...]` not wrapped in `h(...)`; `$X->query("...$VAR...")` (interpolated SQL, should be `prepare`). A short comment at the top of each file says what it is for, in plain words.
- [ ] **Step 4:** Rerun the test — Expected: PASS. `ruby -ryaml -e 'YAML.load_file(ARGV[0])'` on both YAML files — no error.
- [ ] **Step 5:** Commit the five files: `ci(security): weekly + on-push composer audit and Semgrep scan; Dependabot security updates`.

### Task 3: First-run triage

**Files:** whichever source files Semgrep flags; `.semgrep/ngo-cms.yml` / inline `// nosemgrep: <rule-id> — <reason>` for confirmed false positives.

- [ ] **Step 1:** Install Semgrep locally (`brew install semgrep`, same version as pinned) and run the exact workflow command, plus `--json` for reading.
- [ ] **Step 2:** List findings grouped by rule. If more than 5 real sites need code changes, show the user the list before touching anything (CLAUDE.md rule).
- [ ] **Step 3:** For each real finding: fix all call sites of the same pattern in one pass; add or extend a PHPUnit test pinning the fix (e.g. in `tests/Security/`). Check the BG/EN page pair for public pages.
- [ ] **Step 4:** For each false positive: `// nosemgrep: <rule-id>` with the reason on the same line, or narrow the project rule.
- [ ] **Step 5:** Rerun Semgrep — Expected: 0 findings. `php vendor/bin/phpunit` — Expected: 0 failures.
- [ ] **Step 6:** Commit fixes separately from suppressions: `fix(security): <what>` / `chore(security): mark reviewed Semgrep false positives`.

### Task 4: Hand-off (user steps)

- [ ] **Step 1:** Report: commits, `git log --oneline origin/main..HEAD`, test results. Wait for "push".
- [ ] **Step 2 (after push, with user's OK):** turn on Dependabot alerts + security updates: `gh api -X PUT repos/linnah08/ngo-cms/vulnerability-alerts` and `gh api -X PUT repos/linnah08/ngo-cms/automated-security-fixes`; run the workflow once (`gh workflow run security.yml`) and confirm both jobs pass and the Security tab shows the Semgrep upload.
- [ ] **Step 3:** Tag a release (fpdi fix must reach sites); port to oddminds with `Ported: ngo-cms <sha>` trailers (`composer.lock` + workflow files where they apply). Remove the worktree and branch.
