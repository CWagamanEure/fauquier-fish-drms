# Responses to round 1

Round 1: all four auditors approved, no critical or high findings. The branch is front end only (no data, no
handler), so the medium and low findings below are carried into the backend ticket rather than fixed here.

- data (medium): status `follow_up` and the follow-up date have no column in `communications`: accepted for this
  branch; the backend ticket needs a new additive migration (widen the status ENUM, add `follow_up_on`) or must
  map the form values onto the existing ENUM.
- data / correctness (low): campaign field posted as `campaign_id_one` and `campaign_id`: accepted; the handler
  will branch on `kind` before reading fields. Noted for the backend ticket.
- data (low): donor not marked required; anonymous donor 999 not addressed: accepted for the backend ticket
  (mark required, exclude 999 from one-on-one).
- security (low): outreach link printed into href without a scheme check: accepted; the value is always empty on
  this branch. The backend must allow only http/https when saving and printing.
- security (low): no `fish_can('communications', ...)` check yet: accepted as stated in the ticket; nothing is
  read or written. The backend ticket adds view/edit checks and stores uploads behind download.php.
- correctness (medium): filter option values do not match the add form's codes: accepted for the backend
  ticket, which will give the filters values from one shared list.
- correctness / verification (medium): the one-on-one example row links to the outreach-only view page:
  accepted; the one-on-one detail view (or linking to the donor's Communications tab) is follow-up work.
- correctness (low): filter forms have no Apply button and do not keep selections: accepted for the backend
  ticket, which wires the filters.
- correctness (low): small escaping and placeholder inconsistencies on constant values: accepted, no effect.
- verification (medium): no automated test of the page variants: accepted; the variants were checked by loading
  each page signed in. A signed-in variant test belongs with the backend ticket, when the pages show data.
- verification (low): Save silently does nothing: accepted; the ticket lists it under Risks and the PR says so.
- verification (low): `:has()` browser requirement: accepted; stated in the ticket and to be stated in the PR.
- verification (low): no README or migration changes needed: no action.

What the auditors did not cover: the six direct-file-URL failures in the local `make test-auth` run come from
PHP's built-in server ignoring the Apache rules (Docker images could not be pulled here); CI runs the real check.
