# Publication review

[Project overview](../README.md)

## Current condition

The project-authored code uses 0BSD. **Redistribution of the complete bundle is
not cleared:** the legacy DIN webfonts and two status icons have unresolved
source/license provenance. The owner has no original records for them. The
inventory below records the exact files and evidence.
Their appearance and bytes are preserved; adding an integrity hash does not
create redistribution permission. Obtain verifiable permission or approve
reviewed replacements before publishing those assets as freely reusable.

## License and provenance inventory

`composer.lock` records the package versions, sources, and declared licenses.
The reviewed lock contains 45 runtime packages under MIT, BSD-2-Clause, or
BSD-3-Clause terms; the 79 development packages also include Apache-2.0 terms.
All 45 installed runtime packages have upstream license files. Keep those files
and their copyright notices when redistributing installed dependencies. Inspect
installed declarations with `make composer ARGS='licenses --format=json'`;
metadata does not replace reviewing the artifact's actual license files.

| Vendored component | License | Included upstream license |
| --- | --- | --- |
| Bootstrap 5.3.8 CSS | MIT | `public/vendor/bootstrap/5.3.8/LICENSE` |
| jQuery 4.0.0 | MIT | `public/vendor/jquery/4.0.0/LICENSE.txt` |
| jQuery UI 1.14.2 and theme images | MIT | `public/vendor/jquery-ui/1.14.2/LICENSE.txt` |
| Audiowide | OFL-1.1 | `public/vendor/fonts/audiowide-OFL.txt` |
| Faster One | OFL-1.1 | `public/vendor/fonts/fasterone-OFL.txt` |
| Fugaz One | OFL-1.1 | `public/vendor/fonts/fugazone-OFL.txt` |
| Raleway Dots | OFL-1.1 | `public/vendor/fonts/ralewaydots-OFL.txt` |

`assets.lock.json` records upstream URLs and hashes for these exact files. Font
binaries are unchanged; their stylesheet localizes upstream URLs. Retain the
font copyright/OFL texts and reserved-name terms. The
[official OFL text](https://openfontlicense.org/open-font-license-official-text/)
sets conditions for bundling and modified versions; these fonts are not 0BSD.

The npm lockfile under `tests/Browser/` records development-tool terms, including
Apache-2.0/MIT, Biome's MIT OR Apache-2.0, and axe's MPL-2.0. Those tools stay out
of runtime archives. Redistributing installed tools or modified third-party
sources requires retaining their licenses and satisfying their terms. Referenced
container images and their operating-system/browser packages have separate terms.

| Unresolved legacy files | Evidence |
| --- | --- |
| `public/permit/assets/fonts/AlteDIN1451Mittelschriftgepraegt.woff` and `.woff2` | No accompanying license or documented conversion chain. The WOFF name table says `expired`, names Ludwig Goller, and contains no license grant. Those fields do not prove redistribution rights. |
| `public/permit/assets/fonts/stylesheet.css` | Legacy font wrapper with undocumented original source/conversion provenance. |
| `public/permit/assets/icons/valid.svg` and `invalid.svg` | No accompanying license, attribution, or verified upstream source. |

The [font author's current DIN page](https://www.peter-wiegel.de/alteDin1451.html)
is a research lead, not proof that its terms cover these exact webfont binaries.
Similar names, download-site labels, embedding flags, and an `expired` copyright
field are insufficient evidence. No license has been invented or retroactively
attached to those files. Their manifest entries use `NOASSERTION` and no asserted
source URL. Hashes establish byte integrity, not authorship or permission.

## Local review recorded on 11 November 2026

- Reviewed the current tracked source, configuration examples, migrations,
  documentation, six illustrative screenshots, and bundled asset metadata.
  Targeted checks found no known production-domain or supplied Sentry identifiers.
- Reviewed 20 locally reachable commits, current branch/remote-tracking references,
  commit metadata, and reachable blobs. No tags were present. Targeted checks
  found none of the known production-domain or supplied Sentry identifiers.
  Gitleaks' default secret rules found no credentials in that reachable history.
- The repository has an author identity in commit metadata. A source snapshot
  omits Git metadata, remotes, and commit messages. Publishing existing history
  still exposes its author information; attribution within third-party licenses
  is retained in either distribution.
- Reviewed the Composer lockfile's runtime/development license declarations and
  the browser lockfile, existing MIT/OFL notices, and the legacy webfont name
  table. Runtime archives retain installed packages' own license files, the
  project license and installed packages' upstream license files.
- Prepared a separate source review candidate and scanned its extracted contents.
  Production identity checks and secret scans have different purposes: neither
  a zero-match scan nor neutral wording proves anonymity or complete security.

This is a point-in-time local review, not verification of a hosted CI run or
every historical distribution. Remote objects not fetched locally, deleted refs,
unreachable objects, reflogs, forks, external caches, existing downloaded archives,
and private operational data are outside its history scope. No history was
rewritten, remote fetched, release published, repository setting changed, or
message sent to another person.

## Repeating the checks

Run tools through the project Docker environment:

```sh
make assets-check
make source-review
make secrets-source
make secrets-history
```

`make source-review` creates `dist/permit-manager-source-review.tar.gz` and its
SHA-256 file from the current working tree. It selects tracked and unignored
files within the explicit source directories and root-file allowlist in
`bin/build-source-review.sh`. New documentation/source files can be reviewed before
committing them. Ignored tracked source files are still tracked inputs; inspect
the candidate rather than relying on ignore rules as a privacy boundary.
Make reads the file list with host Git and passes it to the Docker build script;
the PHP runtime does not need a Git client. Archive creation runs in Docker.

The candidate omits `.git`, local `.env`, private `protected/` overrides, installed
Composer/npm dependencies, caches, logs, build outputs, IDE/agent metadata, and
temporary root plans. Source-directory symlinks and selected operational file
types are rejected. Custom private files in otherwise allowed source directories
still need exclusion or relocation before exporting. Compare archive paths and
contents with the intended publication set.

`make secrets-source` rebuilds the candidate, extracts it in an isolated temporary
container, and scans the actual extracted files. `make secrets-history` inspects
locally reachable history using `--all`; it neither fetches remote refs nor scans
ignored working-tree credentials. Both use the pinned Gitleaks image from
`docker/security.Dockerfile`, read-only inputs, disabled container networking,
and full secret redaction. Private JSON reports go to
`.phpunit.cache/publication/`. They are not published or uploaded automatically;
even redacted reports may reveal file names, commit IDs, and author information.
The Docker daemon may download the pinned public image before an offline scan.

No blanket test/fixture allowlist or secret baseline is supplied. Review findings
privately and fix their cause; do not silence them just to obtain a passing run.
Gitleaks detects configured secret patterns. It does not recognize every real
address, apartment ID, custom signing secret, image annotation, or QR payload.
Review binary metadata and screenshots visually, and decode any QR examples.
Never paste private search terms or raw findings into committed documentation.

See the pinned [Gitleaks usage documentation](https://github.com/gitleaks/gitleaks/blob/v8.30.1/README.md)
for rule coverage and scan limitations. These are manual publication checks;
they do not replace the existing CI quality/mutation/release requirements.

## Owner checks before publication

- [ ] Clear the listed legacy asset rights or approve and verify replacements.
- [ ] Review the exact source candidate and intended existing history, if any,
  including author identity, filenames, metadata, examples, screenshots, and QR
  payloads. Re-run scans after the final intended changes.
- [ ] Verify the full hosted quality workflow and repository controls described
  in [dependency maintenance](dependencies.md#repository-owner-setup).
- [ ] Explicitly choose the destination, repository visibility, author metadata,
  and whether to publish existing history or initialize a fresh repository from
  the reviewed source. A source archive is a review artifact, not a deployment
  or publishing operation.

Keep the review candidate private while licensing or identity questions remain.
For an existing public leak, removing a value from the current tree is not
revocation or history cleanup. Rotate affected credentials through their own
systems and coordinate any destructive history rewrite separately.
