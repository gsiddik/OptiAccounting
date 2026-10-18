# Entry SSO verified-email follow-up

Date: 2026-10-18. Branch: `codex/entry-verified-email-20261018`.
Base: `8f10478a45815cb364d5de587129f18771583952` (existing Entry PR #10 head).
Owner delegated the preceding follow-up prompt to Codex on a separate branch; decision 11 is approved for this scope.
Status: IMPLEMENTED, supplemental checks PASS; native PostgreSQL 16 / full CI / live validation remain separate gates.
This is an AI-assisted implementation/self-review, not an independent approval. No merge, deploy or operational DB changes.

## Change and boundary
- `AccountLinker::link` requires signed ID-token `email_verified === true` on **every** SSO sign-in, before projection/linking/writes/tickets.
- Missing/null/false/string/numeric/array values deny with stable `email_not_verified`; local profiles and callback parameters cannot override it.
- Existing OIDC signature/issuer/audience/nonce and userinfo subject/tenant checks remain in place. Stable subject remains the identity key.
- Nexus already emits a boolean for the requested email scope: audited manual verification and invitation acceptance both qualify.
- EN/ID refusal explains invitation verification or contacting an ecosystem operator. No unimplemented endpoint or tenant portal access is offered.
- This is a next-login gate. Existing sessions, revocation semantics, standalone login and legacy linking rules are not redesigned.
- No migration, manifest, entitlement, financial treatment, backfill or provider-code change is required.

## Executed checks on this patch
- Frontend: SSO + i18n + product switcher, **48 PASS**; lint and production build PASS (pre-existing large-bundle warning).
- Browser: Chromium, **6 PASS** (EN/ID x 1440/820/390); mocked status API, no overflow/render error/session. Not live SSO.
- PHP 8.3.6 syntax and Pint on changed PHP: PASS.
- Actual linker negative smoke without framework/DB: 7/7 PASS (not a feature or live test).
- Supplemental DB: PGlite 0.5.10, PostgreSQL 18.6 WebAssembly, single socket in a disposable process; NOT native PostgreSQL 16.
- SSO/login/logout/hint/product-context focused feature tests: **100 PASS, 814 assertions** on PGlite, including the new 12-case class.
- Wider Optinexus directory: **270/272 PASS**, 4,131 assertions. Two timing tests failed: stale projection and outbox backoff.
- Those same two failures reproduce with baseline `8f10478` linker on the same PGlite harness (2/2 fail); no unrelated timing change made.
- Regression proof: the new class fails **11/12** with the baseline linker and passes with the gate restored.
- Native-DB initial attempt failed before assertions because no server listened on 127.0.0.1:5432; not recorded as product test failures.
- Native PostgreSQL 16/full backend regression: NOT_RUN locally; require the existing PR CI on the new commit.
- Live Nexus + Entry verification transition, operator UI, real email transport, Windows Podman and Alpine builds: NOT_RUN here.

Re-run native focused checks: `php artisan test tests/Feature/Optinexus/SsoSignInTest.php tests/Feature/Optinexus/SsoEmailVerificationTest.php`.
The new tests use signed fake Nexus responses; they do not claim to execute Nexus invitation or operator-verification internals.

## Merge/deployment evidence and checkpoint
- Entry root `.github/workflows/ci.yml` is identical on main and this PR base: pull_request and push(main), test/lint/build only.
- Fleet root `.github/workflows/regression.yml` is identical on main and its PR head `8781ecd`: pull_request(main) / workflow_dispatch, tests only.
- Nexus main and PR head `c75a3e1` have no root `.github/workflows`; nested backend/.github files are not root Actions workflows.
- No deployment/hosting descriptor was found in the inspected roots. External webhooks/hosting/CI settings were NOT accessible/verified.
- Owner confirmation of external auto-deploy remains required before an actual merge is authorised; absence in source is not proof of absence.
- Merge/deploy never authorises historical backfill or new financial posting; Entry-first is a compatibility condition only.
- PR should target the existing Claude feature branch so reviewers see only this delta; original PR #10 stays unchanged.
- Do not run backfill, change deployment settings or merge as part of this follow-up.
- Next: native PG16 CI, optional scoped live check in a suitable sandbox, then owner decision on merge. Ecosystem PARTIALLY_COMPLETE.
