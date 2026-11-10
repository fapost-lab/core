# Roadmap — Dacha cooperative

Destination: the owner's cooperative of about 400 plots runs its accounting in the Solution —
members link their plots in Telegram, submit readings and see their balance there, the board
charges fees and electricity, records payments and chases debtors from its own screens — and
the same Solution can be activated for another cooperative's tenant without code changes.

## Phase 0 — A Solution can own data, a schedule and pages

Goal: the contracts a real accounting Solution needs exist in Foundation and Core. Done when: a
fixture Solution creates its own tables in a tenant schema, runs a scheduled job per tenant,
serves a console page from a prebuilt bundle and answers a flow action with data about the
calling contact — installed into the SaaS image from a private package.

- [ ] Solution migrations in the tenant schema — `registerMigrations` implemented: a Solution's
      tables are created in each tenant schema where it is active, and what deactivation does to
      them is decided
- [ ] Solution schedule — `registerSchedule` implemented, running per tenant where the Solution
      is active, under the worker-safety rules
- [ ] Solution routes — `registerRoutes` implemented for the console pages and endpoints a
      Solution serves (after: first-solution's prebuilt Solution UI — the pages these routes serve)
- [ ] A flow action that knows its contact — an action handler reads the calling contact and
      returns structured data the flow shows in the reply; the gap is closed in Foundation if it
      exists
- [ ] A private Solution in the SaaS image — the derived-image recipe of `first-solution`
      phase 0 extended to a private Composer package with a build-time token (after:
      first-solution's derived-image recipe — this extends it)

## Phase 1 — The registry and the link

Goal: every plot and owner is in the system and members find their plots in the bot. Done when:
the owner's cooperative's registry is imported from its spreadsheet, and a member who shares
their phone in the bot sees their plots.

- [ ] The Solution package — private repository, manifest, activation for a tenant, its first
      console page (after: Phase 0 — nothing to install into before it)
- [ ] Plots and owners — the registry: plot number, area, street, owners with phones; owner
      changes recorded with a date; the board's screens (after: the Solution package)
- [ ] Registry import — a spreadsheet of plots and owners imported with a preview and per-row
      errors (after: plots and owners — the tables it fills)
- [ ] Linking in Telegram — phone sharing matched against the registry, the "name your plot"
      request and the board's approval queue, several plots per chat (after: plots and owners;
      after: a flow action that knows its contact)

## Phase 2 — Readings and charges

Goal: the cooperative charges fees and electricity from the system. Done when: a monthly charge
run produces every plot's charges from tariffs and readings, and a member sees the breakdown in
the bot.

- [ ] Meters and readings — electricity and water meters, with zones, per plot and shared
      (the common electricity meter, shared water meters); readings from the bot (refused below
      the previous, a jump flagged) and from the electrician, each with its source; how each plot
      is supplied with water (own meter, recorded draws through a shared meter, unmetered) with a
      date (after: linking in Telegram — members submit as linked owners)
- [ ] Recorded water draws — a draw through a shared water meter recorded against a plot, by
      the person on duty or the member in the bot, and charged per unit (after: meters and
      readings — draws belong to a shared meter)
- [ ] Charge items and tariffs — the cooperative's bank accounts, and configurable items (fixed
      per plot, per sotka, per metered unit, one-off), each paid to an account, with tariffs valid
      from a date (after: plots and owners)
- [ ] Charge runs — a run for all plots or a selection, recorded and reversible as a whole;
      charges from flagged readings held for the board (after: meters and readings; after:
      recorded water draws; after: charge items and tariffs)
- [ ] The cooperative's balance — the intake meters for electricity and water read monthly, and
      per period and resource the intake against the plots' meters and recorded draws, with the
      unaccounted difference shown to the board (after: meters and readings; after: recorded
      water draws — both are summed against the intake)
- [ ] Balance in the bot — the member's balance per plot and item, and the charge history
      (after: charge runs)

## Phase 3 — Payments and debts

Goal: the treasurer records every payment and the board knows who owes. Done when: a month at
the owner's cooperative runs in the system in parallel with the treasurer's book and the balances
match.

- [ ] Payments — cash and bank payments entered by the treasurer, allocated oldest first or to a
      named item, reversed rather than deleted (after: charge runs — payments close charges)
- [ ] Debtors — the list of debtors by plot and item, with a threshold, and its export (after:
      payments)
- [ ] Bank statement import — a statement file from each of the cooperative's accounts (Sense
      Business Online's export first) imported and its payments matched to plots by the payment
      purpose, with a queue for the unmatched (after: payments)
- [ ] Payment by bank details — the bot gives the member the amount due and the cooperative's
      details as an NBU payment QR code with the plot in the purpose (after: balance in the bot —
      the amount it shows)
- [ ] Reminders — a monthly reminder to submit readings and a debt reminder to linked owners,
      sent by the Solution's schedule (after: Solution schedule; after: debtors)
- [ ] The pilot month — the owner's cooperative runs one month in parallel with its current
      book, and every difference is explained (after: reminders — the pilot covers the whole
      cycle)

## Phase 4 — The cooperative beyond accounting

Goal: the board runs the cooperative's life, not only its money, in the same place. Done when:
the items below are in use at the owner's cooperative.

- [ ] The statement from the bank's API — the cooperative's bank's business API delivers the
      statement every day, so payments are matched without a file (after: bank statement import —
      the matching it feeds)
- [ ] Losses — the difference from the cooperative's balance, for electricity and water, shared
      by the board's rule per resource and shown in each member's breakdown (after: the
      cooperative's balance — the difference it shares; after: charge runs — it becomes charges)
- [ ] Announcements — a message to all members, a street or the debtors (after: linking in
      Telegram)
- [ ] Requests to the board — a member files a request in the bot and the board answers it
      (after: linking in Telegram)
- [ ] Audit-commission access — read-only access and the income and expense report (after:
      payments)
- [ ] fog: polls and general-meeting votes — quorum, one vote per plot or per owner, and the legal
      weight of an online vote are not known yet

## Waves

1. Solution migrations in the tenant schema; Solution schedule; A flow action that knows its contact; A private Solution in the SaaS image
2. Solution routes
3. The Solution package
4. Plots and owners
5. Registry import; Linking in Telegram; Charge items and tariffs
6. Meters and readings
7. Recorded water draws
8. Charge runs; The cooperative's balance
9. Balance in the bot; Payments
10. Payment by bank details
11. Debtors; Bank statement import; Losses; Audit-commission access
12. Reminders; Announcements; Requests to the board; The statement from the bank's API
13. The pilot month

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
