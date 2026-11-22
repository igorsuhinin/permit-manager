#!/usr/bin/env bash
set -euo pipefail

# Consume job metadata as environment values, never as interpolated shell code.
# Skipped, cancelled, failed, missing, and unknown results all block acceptance.
for ci_job in quality mutation release; do
    ci_result_variable="CI_${ci_job^^}_RESULT"
    if [[ "${!ci_result_variable:-missing}" != success ]]; then
        printf '::error::Required job %s did not succeed.\n' "$ci_job" >&2
        exit 1
    fi
done
echo 'All required checks passed for this commit.'
