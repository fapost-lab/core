# Microsoft Teams connector — corporate assistants

Depth: easy — captured on 2026-10-26 so it is not lost; not stress-tested yet. The next session resumes it
with `jig-idea` at normal depth, starting from the open questions.

## Idea

Owner, 2026-10-26, while choosing the outside channel that proves the `channel-connectors` contract: "Slack
to check the contract, Teams as a separate spec." And the reason Teams came up at all: "the thought is to
go to the corporate market in Ukraine first, that is why Teams surfaced."

Viber, discussed in the same conversation, may be needed too, but only on a customer's request; it is a
fog item of `channel-connectors`, not part of this spec.

## Goal and problem

- Who is worse off without this, and how: a company that runs on Microsoft 365 and wants an internal
  assistant (HR questions, IT helpdesk, approvals, onboarding of employees) where its people already work.
  FaPost reaches people only through Telegram today, and a corporate IT department will not put internal
  processes into a consumer messenger.
- What is true when the work is done: a company's Teams users talk to a FaPost assistant in Teams — in a
  personal chat, and possibly in channels — through an optional `fapost/connector-teams` package built on
  the connector contract; the company's admin installs the app in their tenant without help from us.

## What Teams needs that the contract does not have (paper check, 2026-10-26, from memory)

To be confirmed against Microsoft's documentation in the first session.

- Inbound authentication is not a shared secret or an HMAC: the Bot Framework sends a bearer JWT, signed
  by Microsoft, to be checked against keys from its OpenID metadata (issuer, audience = the bot's app id,
  the `serviceUrl` claim matching the activity's). `IngressSpec` would need a JWT scheme with cached key
  sets, in Foundation, PHP and the Go gateway — or Teams runs without a spec and the gateway proxies it to
  Laravel.
- Sending needs an OAuth access token from client credentials (app id and secret), valid about an hour and
  cached; the reply goes to the `serviceUrl` the inbound activity carried, which has to be stored per
  conversation. Proactive messages (a bot writing first) need a stored conversation reference.
- Bot registration: Azure Bot resource (the Teams channel is free). New bots are, as far as remembered,
  single-tenant or managed-identity only — which decides whether one FaPost app can serve many customer
  tenants or every customer registers their own.
- Distribution: a Teams app package (manifest + icons, zip) uploaded by the customer's admin, or
  published to the store.
- Buttons are Adaptive Cards (submit actions); messages can be updated; typing activity exists; images by
  URL in cards and attachments; files in a personal chat go through a consent flow. No request for a phone
  number or location.
- No subscribers to broadcast to in the consumer sense; proactive messages to the users of a tenant are
  the analogue.

## Stress test

Not run yet.

## Scope and non-goals

- In scope (proposed):
  - A Teams connector package on the `channel-connectors` contract, optional, installed through the
    overlay.
  - What it needs in Core and Foundation: a JWT ingress scheme (or a decision to proxy), an OAuth-token
    sender, per-conversation reference storage, proactive messaging.
  - The admin path: how a customer's M365 admin installs the app.
- Not doing (proposed):
  - Slack — it is the contract's proof inside `channel-connectors`.
  - Microsoft Graph integrations (calendars, files, directory) — integrations, not a channel.
  - Viber and Meta channels — `channel-connectors` fog items, each on a customer's request.

## Decisions

- Teams is its own spec, not the contract's proof (owner, 2026-10-26) — rejected: proving the contract
  with Teams, because its ingress authentication and token-based sender make it the most expensive channel
  to start with; Slack proves the contract first.

## Open questions

- The market: which Ukrainian companies, what they would use an assistant for, and whether they are on
  Teams or on something else (Slack, Viber, Telegram for internal use). Decides whether this is the next
  channel at all.
- One multi-tenant FaPost app versus an app registered by each customer — depends on Azure's current rules
  for bot registration and on how customers' admins prefer to install. It is the same routing question as a
  Slack app distributed across workspaces (`channel-connectors`).
- How SaaS and self-hosted differ: in SaaS the operator may own the Azure registration; self-hosted, the
  installation's owner does.
- A test tenant: the M365 Developer Program sandbox is restricted now — whether a paid M365 tenant is needed
  for development and the end-to-end check.
- Which parts of Flow make sense in a corporate assistant (identity of the employee from Entra ID instead of
  a contact, no contact or location requests, approvals as cards) — may be a Solution's job rather than the
  connector's.
- Must `channel-connectors` close first, or can this start on its epic — depends on when the contract is
  proven by Slack.

## Assumptions left untested

- Everything in the paper check above — taken from memory; Microsoft's documentation and a test tenant test
  it.
- That corporate customers in Ukraine are reachable through Teams — the owner's thought, not yet tested by a
  conversation with a customer.
