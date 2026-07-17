# Implementation Summary

## Change

- Added \`Website::latestScheduledNonFailureLogHistory()\` as a constrained one-of-many relationship.
- Added a bulk \`ScheduledFailureStreak::websitePayloads()\` aggregate that uses eager-loaded boundaries and one grouped history query.
- Updated \`listChecks()\` and \`currentWebsiteIssues()\` to eager-load the constrained boundary and pass precomputed streak payloads into website serializers.
- Preserved the existing latest-result, latest-scheduled-result, diagnostic-result, drift, and single-website fallback behavior.

## Regression coverage

\`tests/Feature/Api/V1/CheckybotControlApiTest.php\` now covers:

- scheduled versus on-demand history rows;
- warning/danger and HTTP-code boundary exclusions;
- nullable status/HTTP values;
- created-at and ID tie-breaking;
- streak count and first-failure response payloads for \`list_checks\` and \`current_issues\`;
- constant website-history query counts as the number of websites grows;
- no per-website \`limit 1\` history query;
- lazy-loading prevention around both MCP reads.

## Verification

- \`php artisan test tests/Feature/Api/V1/CheckybotControlApiTest.php\`
  - 111 tests passed
  - 1,061 assertions passed
- \`vendor/bin/pint --dirty\` passed
- PHP syntax checks passed for all four touched PHP files.

## Delivery

Implemented on branch \`fix/checkybot-21370-website-history-n-plus-one\`. No PR was created or pushed from this implementation stage.

Review document: https://mimir4.marin.sh/workbench/review-documents/4fac6d48-0994-4d8e-b554-e9d3c5113f26
