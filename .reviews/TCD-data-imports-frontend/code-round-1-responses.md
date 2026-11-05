# Responses to round 1

Round 1 approved: four auditors reported, no critical or high findings. Five lows, all accepted for this
front-end-only branch and left for the backend work:

- LYBUNT/SYBUNT export description does not match donor_summary.status windows: accepted; the backend should filter on donor_summary.status IN ('lybunt','sybunt') and the wording can be tightened then.
- Tax receipt export does not say cash-only: accepted; backend should total cash gifts by YEAR(donation_date) and exclude donor 999.
- Fix / Merge / Skip buttons in the example row stack vertically (donor.css td.actions rule): accepted; restyle when the review table gets real rows.
- Date-range exports accept From later than To: accepted; the export handler must validate the order.
- Only the login redirect is covered by automated tests: accepted; manual check (each type chip, all eight exports, empty states) done and listed in the PR.
