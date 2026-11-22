# Dependency maintenance and CI acceptance

[Project overview](../README.md)

## Scheduled update proposals

[Dependabot configuration](../.github/dependabot.yml) proposes updates on Mondays
using UTC schedules. It takes effect after reaching the repository's default
branch and GitHub accepting/enabling its update jobs.

| Scope | Time | Grouping |
| --- | --- | --- |
| Composer in `/` | 06:00 | Separate runtime and development minor/patch groups; majors remain individual |
| GitHub Actions | 07:00 | Minor/patch actions together; majors remain individual |
| Docker in `/` and `/docker` | 07:00 | PHP, Composer, MySQL, and actionlint minor/patch updates; majors remain individual |
| npm and Docker in `/tests/Browser` | 08:00 | One `browser-tools` group for packages, lockfile, and Playwright image |

These are reviewable proposals with no automatic merge or deployment. Version
scheduling does not enable security alerts or security-update PRs. An owner should
enable the dependency graph, Dependabot alerts, and security updates where
available. Advisory-triggered PRs follow GitHub's security-update rules; the weekly
version schedule does not establish a security-response deadline.

PHP, Composer, MySQL, Playwright, and the actionlint/ShellCheck image have SHA-256
digest pins. `docker/tools.Dockerfile` holds the CI-tool image reference, read
directly by Make. The browser Dockerfile holds the sole Playwright image reference,
also used by `make browser-lock`. Review related references together. Pins fix
image inputs; apt and PECL installations still use upstream repositories at build
time. They do not establish a fully reproducible or vulnerability-free image.

## Reviewing updates

Review manifest/lockfile diffs, upstream changelogs, advisories, licenses, and
supported PHP/browser versions. Keep runtime PHP compatible with the documented
shared-hosting version and verify the minimum supported version when updating
the PHP tool image. Avoid unrelated lockfile refreshes.

Run [the standard checks](development.md#continuous-integration), including
`make browser-check`, plus the production archive and browser verification.
`make browser-check` builds the locked tool image and runs version checks,
toolchain tests, Biome, and npm audit without starting a database. The full
`make test-browser` suite additionally checks browser executables, workflows,
independent QR decoding, and public/print snapshots.

The Playwright package, image tag, and locked/installed `playwright` and
`playwright-core` packages must use the same exact version. Every direct browser
tool must match its exact declared, locked, and installed version. The image needs
an explicit SHA-256 digest. Validation runs during image construction and before
browser tests. Docker verifies the referenced digest on pull; browser launch/tests
check compatibility with its contents.

Grouping does not guarantee simultaneous upstream releases or compatibility.
When a grouped or security PR fails this check, select a compatible published
package/image pair, review its digest, run `make browser-lock`, then rebuild with
`make browser-check` and run the browser suite. All Node/npm commands run in Docker.
Review intentional image changes using the
[baseline procedure](demo.md#updating-tools-and-visual-evidence).

Runtime files under `public/vendor/` and legacy permit assets are maintained
separately from npm test tools. Dependabot does not update their contents or
establish licensing/provenance. Follow the
[vendored asset procedure](development.md#frontend-dependencies): review an explicit
upstream release, retain required notices, update manifest hashes/template URLs,
and run integrity, public/print, and release checks. Review the legacy plate font
and other assets separately before redistribution. The
[publication review](publication.md) records the license inventory, unresolved
legacy rights, current review condition, and local scanning commands.

## CI and release artifacts

The [workflow](../.github/workflows/quality.yaml) handles pushes, pull requests,
merge-queue checks, manual dispatches, and weekly runs. All events have the same
quality, mutation, and release requirements. There are no path filters or optional
mutation runs that can turn partial verification into acceptance.

The release job requires successful `quality` and `mutation` jobs. It builds the
archive and checks hosting layouts, recovery/rollback, browsers, QR codes, and
print output before uploading `permit-manager-release`. Failed, cancelled, or
skipped prerequisites block release. The final **`Required checks`** job runs
after all three jobs, including failure/skip paths, and accepts only three
`success` results. Missing/unknown results fail. Workflow cancellation may also
stop that final job; it must never count as successful acceptance.

Use archives only from a fully successful workflow for the intended commit.
Match the run/commit and verify its checksum. Artifacts from older, failed,
cancelled, or incomplete runs are not an accepted release. Archives and separate
quality, mutation, and synthetic browser reports retain their 14-day lifetime.
Available reports upload after failures too; private config and application logs
remain excluded. Pushing a `v*` tag also publishes the verified archive and checksum
on the repository's Releases page after `Required checks` succeeds. That job uses
only the current run's artifact and has the workflow's sole `contents: write`
permission. Release assets do not share the workflow artifacts' 14-day expiry;
see [versioned downloads](deployment.md#downloading-builds-and-publishing-versions).
The workflow does not deploy to a production server.

## Repository-owner setup

After pushing the configuration and observing a successful hosted run:

1. Check Dependabot's jobs for every configured ecosystem and `browser-tools`;
   resolve any configuration or registry-access errors.
2. Require **`Required checks`** from GitHub Actions in the target branch's
   ruleset. Select it from a real run and keep the check name unique.
3. Require checks for the latest merge candidate, using up-to-date branches or
   GitHub's merge queue. The workflow supports `merge_group`. Choose review and
   bypass permissions appropriate to the repository's owners.
4. Enable the desired Dependabot security features and confirm fork/bot runs
   work without secrets. Apply the same review/acceptance policy to bot PRs.

These owner settings are not changed by committing YAML. Local checks do not
certify hosted scheduling, ruleset enforcement, registry access, or a first
Dependabot run. Tests parse the actual workflow/update configuration, check
prerequisite and upload ordering, and execute the real acceptance script against
all success/failure/cancelled/skipped combinations, missing, and unknown results.
Actionlint validates workflow syntax/expressions. A hosted run remains required
to verify GitHub's execution and repository settings.

See GitHub's [Dependabot options](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
and [job dependencies](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-jobs),
and Playwright's [Docker guidance](https://playwright.dev/docs/docker).
