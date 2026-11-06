# Architecture Decision Records

This project records its lasting architecture decisions in `docs/adr/`. They are part of the code: keep them true.

## Before you plan or change code

- Read `docs/adr/README.md` (the index) and every ADR whose topic or Evidence paths touch the files you will change.
- Do not contradict an Accepted ADR silently. If your change needs a different decision, say so before you write code.

## In the same change as the code

- **Decision changes:** write a new ADR with the next number using the template in `docs/adr/README.md`, set the old ADR's status to `Superseded by NNNN`, and add the new row to the index.
- **Facts change, decision stays:** when your code changes a name, number, port, timeout, path or behaviour that an ADR states, update that ADR's text to match.
- **Files move:** when you move, rename or delete a file listed in an ADR's Evidence, update the path.
- **New lasting decision:** add a new ADR. Details of a single change belong in its pull request, not in an ADR.
- Mention every ADR you added or updated in your summary.
