# Roadmap — First solution: Feedback, and the Solution scaffold

Destination: the Feedback Solution from its own repository is installed, activated and in use —
the tenant's staff and customers file and vote on feedback, the FaPost team runs the shared
platform board — with every contract gap it hit recorded and closed outside Core, and a command
in Core that generates a new Solution which installs and passes its tests.

## Phase 1 — Feedback inside a tenant

Goal: a Solution an outsider could have written, used by a tenant's own team. Done when: on an
installation where Feedback was activated, staff file bug reports and suggestions from the button
on any console screen, vote on them, triage them in Feedback's own app, and a flow notifies the
team — with no `App\…` import and no change made to Core for its benefit.

- [ ] The package in its own repository against Foundation, Support and `@fapost/ui`, with a
      manifest the platform accepts and its own tables, permissions and pages as an application
      in the console's switcher (after: the activation lifecycle spec — nothing to declare a
      manifest to before it; after: ui-foundation phase 4 — the extension contract it registers
      through)
- [ ] The widget for staff — the button in the shell's slot on every console screen, the modal
      with type, text and similar items to vote on instead of filing a duplicate (after: the
      package — the widget writes into its tables)
- [ ] Triage and voting — the board, statuses, likes and dislikes, the contextual "feedback from
      this customer" action on Core's contact view (after: the package)
- [ ] Notification through a flow — each new item raises an event that starts the tenant's flow,
      and the flow messages whoever the tenant configured (after: the package — the event is
      raised by its writes)
- [ ] fog: customers reaching the widget — a mini-app extension point, a bot command, or both; the
      UI extension contract has no mini-app point yet

## Phase 2 — The FaPost board

Goal: platform feedback from every installation reaches the FaPost team, and everyone sees and
votes on the same board. Done when: a suggestion filed in the SaaS and one from a self-hosted
installation with the section switched on appear on the FaPost instance's board, both
installations show it and count each other's votes, and the FaPost team got a Telegram message
for each.

- [ ] Receiver mode — the Solution on the FaPost team's instance accepts items and votes from
      registered installations through its API, authenticates them, limits their rate, and sends
      the FaPost team a direct Telegram message per new item (after: phase 1 — the same tables
      and triage pages serve the receiver)
- [ ] The platform tab — the widget reads the shared board, files to it and votes on it; on in the
      SaaS, opt-in with a plain statement of what leaves the installation for self-hosters (after:
      receiver mode — the tab talks to it)
- [ ] fog: voter identity across installations and moderation of the public board — what
      identifies a voter, whether customers may vote, what is public at once and what is stripped

## Phase 3 — The gaps become contract work

Goal: what the build hit is fixed where it belongs. Done when: each gap is either a merged
Foundation or UI-contract change or a written-down decision not to close it.

- [ ] The gap record: every missing contract, workaround and consulted Core source file, in one
      place that step 7 can be written from (after: phases 1 and 2 — a gap list assembled before
      the build is a guess)
- [ ] The contract additions the build needed, made in the Foundation repository and the UI
      extension contract, consumed by the Solution from the published packages rather than the
      development symlinks

## Phase 4 — The scaffold

Goal: a developer starts a Solution in one command, on a template as current as Core. Done when:
the command generates a package that installs into Core and passes its own tests, and Core's CI
proves it on every run.

- [ ] The generator command — a Solution package with one example of each extension point Feedback
      proved (application with pages, shell slot, contextual action, event into a flow, migration,
      permission), tests and CI (after: phase 3 — it generates the contracts as they came out of the
      build)
- [ ] Core's CI generates, installs and tests a fresh package on every run (after: the generator
      command)

## Waves

1. The package
2. The staff widget; triage and voting; notification through a flow
3. Receiver mode
4. The platform tab
5. The gap record; the contract additions
6. The generator command
7. The scaffold in Core's CI

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
-->
