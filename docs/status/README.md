# Status files

Naming: the product is OptiEntry (formerly OptiAccounting). Status files of phases up to OA3 and `docs/specs/` keep the name used when
they were written; the rename is recorded in `RENAME_OPTIENTRY.md`.

One file per phase: `MASTER_STATUS.md`, `OA0_STATUS.md`, `OA0-N_STATUS.md`, `OA1_STATUS.md` … `OA7_STATUS.md`,
`PROD_STATUS.md`. Created when the phase starts, never pre-filled.
Each file stays ≤ 100 lines with these sections:

Status · Completed batches · Important implementation decisions · Migrations ·
Security/invariants · Tests (PASS / FAIL / NOT RUN, only what was executed) ·
Known issues · Remaining · Next phase dependencies.
