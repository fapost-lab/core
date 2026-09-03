<!--
Title: `type(scope): subject` — it becomes the commit on main and a release-notes line.
  feat(flow): …   fix(webhook): …   chore(deps): …   docs: …   feat(flow)!: … for a breaking change
Guide: https://docs.fapost.in/contributing/commits
-->

## What

<!-- One or two sentences. What is different after this merges? Not a list of files. -->

## Why

<!-- The bug, the missing behaviour, the request. Link the issue: -->

Closes #

## How to check

<!-- The test that covers it, or the manual steps if the change is visual or operational. -->

## Operator or extension impact

<!--
Delete this section if there is none. Otherwise this is what ends up in the release notes:
- a migration to run, a new environment variable, a changed restart order
- a contract change on the extension surface (add `!` to the title and a BREAKING CHANGE: line here)
-->

## Checklist

- [ ] `vendor/bin/pint --dirty`, `composer test` and `composer run test:arch` pass
- [ ] The change is covered by a relevant test
- [ ] Documentation in `docs/site` is updated if a public contract changed
- [ ] One concern in this pull request; anything unrelated is in its own

<!--
First contribution? Add the CLA line (see https://docs.fapost.in/contributing/legal):
I have read the FaPost Contributor License Agreement (CLA.md) and I accept it.
Signed-off-by: Full Name <email@example.com>
-->
