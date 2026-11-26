# Automated security scanning — design

**Status:** proposed · **Size:** small change · **Repo:** ngo-cms (reaches lafetki/other NGOs via release; ported to oddminds by hand)

## Why

AI tools make it cheap for attackers to sweep open-source code and its dependencies for known holes.
ngo-cms is public, so anything findable will be found. We want known library vulnerabilities and
common code mistakes in our own PHP caught automatically, without someone remembering to look.

## Goals

1. **Libraries (known CVEs):** detect every published advisory in our Composer/npm dependencies,
   and get a ready-to-merge fix proposed automatically.
2. **Our own code:** scan PHP for injection-style mistakes (user input reaching SQL, HTML output,
   shell, file paths, redirects) on every push and weekly.
3. Findings land somewhere visible, with an email, not in a log nobody reads.

## Non-goals

- AI-driven review (approach B) — declined for now; can be added later on top of this.
- Checking live sites' own server setup (PHP version, file permissions) — separate project.
- Auto-merging or auto-releasing fixes. A person merges; a release ships it, as today.

## Anti-goals

- A noisy scanner people learn to ignore. Known false positives get suppressed with a reason,
  so the alert list stays at "everything here is real".

## Approaches considered

- **A. Free tools only (chosen):** Dependabot + `composer audit` + a PHP static scanner. No cost,
  no false alarms on the library side; misses logic holes only a reviewer would see.
- B. A + weekly Claude security review opening fix PRs — better coverage of logic bugs, costs API
  spend; declined for now.
- C. AI review only — unreliable for known CVEs; rejected.

## Design

**1. Fix what's already open.** `composer audit` today reports CVE-2026-45802 in `setasign/fpdi`
(<2.6.7, DoS, pulled in by mPDF). Update it (`composer update setasign/fpdi`), run tests, release.

**2. Dependabot (remediation for libraries).**
- Turn on Dependabot alerts + security updates on `linnah08/ngo-cms` (repo setting).
- Add `.github/dependabot.yml` for `composer`, `npm` and `github-actions`, **security updates only**
  (no routine version-bump PRs — noise). Dependabot opens a PR with the fixed lockfile; we merge it
  and tag a release. This is the one exception to "no PRs": it's GitHub's only mechanism.

**3. `security.yml` workflow** — runs on push to `main`, weekly (Monday morning), and manually:
- **`composer audit`** (and `npm audit --omit=dev`, which is empty today) — fails the run if any
  advisory is open, so a red ✗ shows on `main` and GitHub emails the pusher.
- **Semgrep** (open-source CLI, PHP rulesets for injection/XSS/SSRF/unsafe functions) with
  output uploaded as SARIF to GitHub's **Security → Code scanning** tab (free for public repos),
  which emails repo admins on new alerts and tracks each one until fixed or dismissed.
- Project-specific Semgrep rules in `.semgrep/` for our own conventions, e.g. `echo` of `$_GET`/
  `$_POST` without `h()`, `$pdo->query()` with string interpolation.

*Why Semgrep, not Psalm taint analysis:* our procedural code loads files via
`$_SERVER['DOCUMENT_ROOT']`, which Psalm can't follow without heavy setup; Semgrep works per-file
with no project config and makes custom rules easy. CodeQL doesn't support PHP.

**4. First run triage.** The first scan will likely flag existing code. Each finding is either fixed
(in batches, per CLAUDE.md's "fix all call sites" rule) or dismissed in GitHub with a reason, so the
list starts clean.

**5. Existing guards stay.** `tests/Security/` (login guards, CSRF, webroot guards) keeps running
in PHPUnit; Semgrep complements it, doesn't replace it.

## Constraints

- PHP 8.4; production is shared hosting — nothing new runs on the live sites, all scanning is in CI.
- lafetki gets fixes only via tagged release; oddminds via hand port (`Ported:` trailer).
- A failing security check on `main` does not block pushes (we push straight to main) — it's a
  visible red signal plus email, not a gate. The release workflow could later refuse to build with
  open advisories; left out for now.

## Left to the builder

- Exact Semgrep rulesets/versions (pin the Semgrep version), weekly cron time.
- Which custom rules are worth writing after seeing the first-run results.
- Whether to also add the same workflow to lafetki (probably not needed — same code).
