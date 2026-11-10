# Dacha cooperative — a Solution for running a garden cooperative

Depth: normal — the build is ordinary Solution work, but the accounting model (what a charge,
a payment and a debt are) is costly to change once real members' balances live in it, so each
of those decisions gets its own question.

## Idea

The owner, 2026-11-10 (translated from Russian): "I want a new Solution; the main idea is
managing a dacha cooperative. There is a ready-made product that I find really good
(miydimonline.com.ua). I want to get closer to it, but start with simple things. Assess what we
can do first to get a working solution — I want to introduce it at my own dacha."

Reference: Мій Дім Online (miydimonline.com.ua/wiki/mozhlyvosti-systemy) — about 50 modules
grouped by role (manager, accountant, audit commission, technician, resident), built for
apartment-building associations (ОСББ) with a separate page for garden cooperatives
(/mistechka): electricity and water by meters, an online "subscriber book", online payment,
debt reminders through SMS, email and a Telegram bot.

First phase as agreed in the same conversation: a registry of plots and members, charges
(fees and electricity), meter readings through the bot, recording payments, debts and
reminders.

## Goal and problem

- Who is worse off without this, and how:
  - The cooperative's board and treasurer: readings, charges and payments live in a notebook or
    a spreadsheet; working out who owes what, and chasing them, is manual work every month.
  - Members: they do not know their balance without asking the treasurer, and submitting a
    meter reading means a call or a message nobody records.
  - The platform: a second real Solution after Feedback, used by real people outside the team,
    tests the extension contracts against a domain that is not about communication.
- What is true when the work is done (phase 1): at the owner's own cooperative, every plot and
  its owners are in the system; members submit readings to the bot and see their balance in it;
  charges for fees and electricity are computed from tariffs and readings; the treasurer records
  payments; the board sees the debtors and the bot reminds them.

## Stress test

- Hidden assumptions — "this holds only if …":
  - …a Solution can own tenant tables, a schedule and routes. Today it cannot:
    `SolutionCoreRegistrar` (`app/Infrastructure/Solution`) refuses `registerMigrations`,
    `registerSchedule` and `registerRoutes` as unsupported, and `registerNodeHandler` is limited.
    Without migrations there is no registry, without a schedule no monthly reminder. These are the
    first contract gaps, and they are Core and Foundation work, not Solution work.
  - …the bot dialogue can be built as a flow calling the Solution's actions ("submit reading",
    "show balance", "link plot"). Holds if action handlers can read the calling contact and return
    data the flow can show; to be checked on the first action.
  - …the board will keep the registry current. Owners sell plots and change phones; a stale
    registry sends a debt reminder to the previous owner. The ownership change has to be a
    first-class operation with a date, not an edit of a name.
  - …the phone in the registry is the phone the member uses in Telegram. Many will differ; the
    request-and-approve fallback carries that load, so its queue must be usable on day one.
- The main trade-off: a real Solution on unfinished contracts. It costs time before the first
  member sees anything (phase 0 work in Core and Foundation first), and pays back as contracts
  shaped by a live customer instead of a stub.
- The weakest point: money. A wrong balance shown to 400 neighbours in the bot does more damage
  than no bot. Charges and allocation must be reproducible (a charge run is recorded and can be
  reversed as a whole), and the first months run in parallel with the treasurer's current notebook
  or spreadsheet until the numbers match.
- Failure modes — cause, what breaks, the signal that shows it:
  - A tariff changes mid-period → charges computed at the wrong rate → members dispute the bill.
    Tariffs carry a validity date, a charge records the tariff it used.
  - A reading typo (an extra digit) → a huge electricity charge → a flagged jump; a charge from a
    flagged reading waits for the board.
  - Payment entered twice → a false overpayment → reconciliation of the cash book and the bank
    statement against recorded payments; payments are reversed, never deleted.
  - Losses shared by a rule nobody agreed → conflict at the general meeting. The losses rule is a
    board setting, shown in each member's charge breakdown.
  - Personal data of 400 people (names, phones, debts) in a SaaS tenant → a leak is a legal and a
    neighbourly problem. Debts are visible only to the plot's linked owners and the board; the
    audit commission gets read-only access.
- Other shapes considered, and why this one:
  - A spreadsheet plus a bot that only reads it (Google Sheets as the database). Fastest to a
    first member, but every rule above (allocation, reversals, tariff dates) would live in
    formulas nobody can test, and it does not become a product. Rejected.
  - Building it inside Core under `app/Features`. Fast, but breaks the rule that Solutions reach
    Core only through contracts, and Core is public while this Solution is private. Rejected.
  - Bot only, no board screens, Excel import and export for the treasurer (offered as an
    option). Rejected by the owner in favour of leading phase 0, so the board gets real screens.

## Scope and non-goals

- In scope (phase 1): the plot and owner registry with spreadsheet import; ownership changes;
  linking Telegram users to plots; meters with zones; readings from members and the electrician;
  configurable charge items and tariffs; charge runs for all plots; manual payments (cash and
  bank) with allocation; balances and debtors; the member's balance and charge history in the
  bot; reading and debt reminders.
- Later phases: bank statement import and automatic matching; losses between the common meter
  and the plots; polls and general-meeting votes; announcements to all members or a street;
  requests to the board; audit-commission reports; the bank statement fetched from the bank's
  API.
- Not doing: penalties (пеня), subsidies and benefits, BAS and 1C exchange, acts and invoices for
  legal entities, card acquiring (it brings the PRRO obligation), automatic meter reading by
  modem, a mobile app, an organisation website, Viber.

## Facts about the first cooperative

The owner's answers, 2026-11-10:

- About 400 plots.
- Electricity: every plot has its own meter, and the cooperative has a common meter at the
  intake; the difference (losses) is shared between plots.
- Payments: cash to the treasurer, and transfers to the cooperative's bank account.
- Charges: a fixed annual fee, a rate per sotka (100 m²), one-off targeted collections, water
  and waste collection — and "we must allow for adding more items".
- Water: some plots have their own water meter; some draw water through a shared meter, where
  each draw is recorded against the plot; some use the common water with no metering at all.
- The cooperative has its own intake meters for both electricity and water; the supplier bills
  the cooperative by them (owner, 2026-11-10: "the organisation's common meter must be accounted
  for, for both electricity and water").
- Bank accounts: one at Sense Bank; a second whose bank the owner is confirming (2026-11-10).
- Channel: Telegram. Viber was rejected by the owner as too expensive.

## Decisions

- Members reach the Solution through Core's Telegram channel only — rejected: Viber, as too
  expensive for the cooperative (owner, 2026-11-10).
- Charge kinds are configuration, not code: the board defines charge items, each with a
  calculation method (fixed per plot, per sotka, per metered unit, one-off), so a new item needs
  no release — rejected: a fixed set of fee types, because the owner asked for room to add items.
- The account is the plot: charges, payments, balance and debt belong to a plot; a plot has one
  or more owners and a person may own several plots, all reachable from one Telegram chat —
  rejected: a balance per person, because it breaks when one of several plots is sold or a plot
  is jointly owned. The plot's meters and history stay with the plot when owners change.
- Payments are allocated to charges by item, oldest first, unless the treasurer names the item —
  rejected: a single running balance, because it cannot answer how much a targeted collection
  raised or what is owed for electricity, which the audit commission asks.
- A Telegram user is linked to plots by the phone number Telegram confirms (contact sharing)
  matched against the registry; with no match, the person names the plot and the board approves
  the request — rejected: invitation codes (400 slips to hand out) and approval of every link
  (400 manual approvals).
- Readings come from two sources, both first-class: the member through the bot, accepted at
  once (a value below the previous one is refused, an unusual jump is flagged for the board,
  a photo is optional), and the electrician on a round, who records readings for plots that
  submitted none or checks submitted ones; each reading keeps its source — rejected: mandatory
  photo with board approval (400 checks a month).
- Metering is generic over resources: electricity and water use the same meters, readings and
  per-unit charge items. A plot is supplied with a resource in one of three ways — its own meter;
  a shared meter whose draws are recorded against the plot (the draw, not a reading, is what is
  charged); or unmetered, charged by a fixed item — and the way can change with a date. Rejected:
  water as a fixed fee only, because plots with their own meters and recorded draws would pay for
  water they did not use; and a water model separate from electricity, because the two differ
  only in the resource.
- The cooperative's intake meters (electricity and water) are meters of the cooperative, not of a
  plot, read monthly like any other. Each period the Solution shows the balance per resource: the
  intake against the sum of plot meters and recorded draws, with the difference — losses, common
  use and unmetered plots — as one visible figure. Showing the balance comes in phase 2; turning
  the difference into charges is the later losses item. Rejected: treating the intake meter only
  as an input to the losses item, because the board needs the balance from the first month to
  see theft, broken meters and typos before anyone is charged for them.
- A shared water meter through which plots draw water is distinct from the intake meter: its
  draws are charged to plots, while the intake meter is only balanced against.
- Members pay only by direct transfer to the cooperative's bank account; there is no card
  acquiring (owner, 2026-11-10, reversing the same day's request for online payment: "a direct
  payment to the account is needed; it is related to the PRRO"). Card payments taken through an
  acquiring provider are settlement operations that need a fiscal receipt from a software cash
  register (ПРРО); a transfer from the member's own account to the cooperative's IBAN does not.
  Rejected: card acquiring through monobank, WayForPay or LiqPay behind a provider port with a
  commission setting (planned and dropped the same day), because of the PRRO obligation it brings.
  The commission setting is dropped with it: a transfer's fee, if any, is set by the member's own
  bank.
- The bot gives the member the amount due and the cooperative's bank details as an NBU payment QR
  code with the plot in the payment purpose; the member pays from their own banking app, and the
  bank statement import matches the payment to the plot. This is the online payment.
- A cooperative has one or more bank accounts; each charge item names the account it is paid to
  (the default account unless set), the QR code carries that account, and a statement is
  imported per account — rejected: a single account per tenant, because the owner's cooperative
  already has two, and the reference product supports several for the same reason.
- A meter has one or more zones (day/night), each with its own tariff, and a reading carries a
  value per zone — rejected: single-zone only, because both kinds exist on the plots and adding
  zones later rewrites readings and the calculation.
- The Solution lives in its own private repository (`fapost-lab/solution-dacha`, working name),
  reaching Core only through Foundation contracts and the UI extension contract — rejected: a
  public Apache-2.0 repository, because the owner keeps the option of a product for other
  cooperatives; opening it later stays possible, closing a published one does not.
- The first cooperative is a tenant on the shared SaaS installation with the Solution activated —
  rejected: a self-hosted installation of its own, because the path to further cooperatives is
  the same tenant path and nobody has to run a separate server.
- This Solution leads `first-solution` phase 0 (prebuilt Solution UI, the derived image, the
  development loop) and is the first real Solution built on it; Feedback follows or runs
  alongside — rejected: waiting for Feedback's phases 0–1, because a live customer closes the
  contract gaps against real needs and the board needs its own screens.
- At 400 plots, importing the registry from a spreadsheet and running charges for every plot at
  once are phase 1 needs, not conveniences.

## Open questions

- How the private Solution gets into the SaaS image: the derived-image recipe of
  `first-solution` phase 0 assumes public packages; a private Composer repository needs an
  auth token at build time — decides the SaaS build, not the Solution.
- Where Solution tables live: in each tenant's schema (as Solution activation does,
  `adr-20261014-solution-activation-in-tenant-schema`) is the natural answer; to be settled in
  the `registerMigrations` work, together with what deactivation does to the data.
- How reminders reach members: a Solution sending a message to a contact through
  `MessageSenderInterface`, or starting a tenant flow, or a broadcast — which contract a Solution
  is allowed to use is open; decides the reminders item.
- Whether a flow action can see the calling contact and return structured data for the reply —
  decides whether the bot dialogue is a flow or the Solution's own handler.
- The losses rule, per resource: shared equally, by consumption, by area, or by a fixed
  percentage; and for water, whether unmetered plots carry the difference through their fixed fee
  or share it — the owner's cooperative decides; needed only for the losses item.

- The second account's bank, and whether either bank gives the statement through an API. For
  Sense Bank no public business API for statements was found (2026-11-10 search: Sense Business
  Online and Sense SuperApp for legal entities, and a mention of joining open banking); to be
  asked of the bank's business support. Decides the API item; until then the statement is a file
  exported from Sense Business Online, and which export formats it offers decides the import.
- Which charge items go to which account — the owner's cooperative decides; needed for the
  QR code and the statement matching.
- Whether every income the cooperative takes by transfer (fees, electricity, water) stays outside
  the PRRO obligation for this cooperative's legal form — to be confirmed with its accountant;
  the decision against acquiring rests on it.

## Assumptions left untested

- Members will submit readings through the bot without a push from the board — taken at
  normal; the first month's share of plots with a member reading tests it.
- The treasurer will enter payments in the system rather than in the notebook first — taken at
  normal; a month with both, compared, tests it.
