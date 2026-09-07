---
status: Draft
owner: "Alexander Zinchenko"
updated_at: "2026-09-07"
depth: "medium"
---

# Idea brief — fapost-core

## 1. Raw idea

In the owner's own words:

> "It is a core platform for building flows for Telegram."

> "I am planning an open-source solution: a platform that can be extended to the needs of a business (customisation) plus ready-made solutions. On one side there will be a SaaS, ready to work for the end business; on the other side it is for integrators, so they can adjust the system to a business."

> "The first thing is a platform ready for extension — essentially an open-source, self-hosted product for integrators. The SaaS is strictly my own product and will not be sold as an extension. All other solutions and plugins may be distributed either free or for money."

> "This project was not born from scratch. It is a replacement for our current project, which we were rolling out to our clients while spending a lot of time, because the system did not scale. My main goal is the core, a framework along the lines of Filament — that is my reference point. I am not trying to earn money right now; I want a product that will help others, and do it easily."

Original wording, preserved verbatim:

> «это платформа ядро для создания флоу для телеграмм»

> «я планирую опенсорс решение, платформу которую можно расширять под потребности бизнеса (кастомизация) + готовые решение. С одной стороны планируется СааС, который будет готов к работе для конечного бизнеса, с другой стороны для интеграторв, чтобы подстроить систему под бизнес»

> «первое это готовая под расширение платформа, по сути опенсорс продукт для интеграторв селф-хостед. СааС это сугубо мой продукт и продаваться как расширение не будет. Все остальные солюшены и плагины могут распространятся как бесплатно так и за деньги»

> «этот проект родился не с нуля, а как замена нашего текущего проекта, который мы внедряли своим клиентам, но при этом тртили много времени, потому что система не масштабировалась. Моя основная цель это ядро, фреймворк на подобии Filament, это мой ориентир. Я не стремлюсь сейчас зарабатывать, я хочу продукт, который будет помогать другим, и делать это легко.»

## 2. Problem

Rolling out a conversational assistant to a client on the existing system costs too much time: the system does not scale, and every new client demands manual work instead of reuse. The pain is first-hand and current — it is measurable on the owner's own client rollouts today, not inferred from a market hypothesis.

## 3. Users

- **The owner and his team** — run client rollouts continuously; they carry the pain daily and are the platform's first real user.
- **A developer or integrator inside the Laravel ecosystem** — needs a conversational assistant inside a client's project and already knows how to host and extend this stack. The primary external audience; installs self-hosted in single-tenant form.
- **The author of a third-party extension** — writes a Solution or a plugin and distributes it free or for money. Arrives later and is what confirms the ecosystem.
- **The end business** — arrives only through the owner's SaaS, never extends the platform, and never sees the core.

## 4. Why now

The trigger is real, not hypothetical: the current system carrying client rollouts has hit its scaling limit, and the replacement is already at release readiness. The question is not whether to start, but where the weeks immediately after release should go.

## 5. Out of scope

- **Multi-tenancy in the open distribution** — self-hosted installs single-tenant; the multi-tenant shell stays the owner's separate closed product.
- **Selling the SaaS as an extensible product** — the SaaS is not a subject of extension and is not part of the open distribution.
- **Earning money from the core right now** — monetisation is not the goal of this stage; the goal is core quality and ease of extension.
- **General-purpose process automation** — the focus is conversation inside messengers, not wiring arbitrary systems to one another.
- **Channels beyond the first one** — channel swappability is an architectural property, but no additional channel is promised to a date; they follow demand.

## 6. Risks

- **Assumes "open code plus a node builder" is the differentiator.** False: existing open constructors offer exactly that and already have communities. The real differentiators are the permissive licence, conversation as a first-class object, and a stack native to the Laravel developer. While the positioning is phrased the first way, the difference does not read at all.
- **Assumes an integrator will come for extensibility.** False if that integrator has no client demand for conversational assistants — extensibility does not create demand on its own, and the channel stays empty.
- **Filament as the reference point sets a bar for documentation and developer experience** that is hard to hold single-handed. The analogy promises an outside developer more than can realistically be sustained.
- **Conflicting priorities.** "Not trying to earn money right now" sits badly beside the SaaS shell, billing and marketplace present in the plans; which of the two signals leads has not been decided.
- **The work queue does not test the bet.** The order after release was assembled around finishing the core, and it closes none of the three stated pillars of the positioning.

## 7. Recommendation

Treat FaPost as a framework for conversational assistants aimed at the developer inside the Laravel ecosystem, not as one more open bot constructor: core plus extensions plus documentation, along the lines of Filament. Build the positioning on three pillars — a permissive licence that lets an integrator build a closed solution on top and sell it; conversation as a first-class object of the platform; ready-made free solutions as a starting point to adapt for a client. Test the bet not with a showcase for buyers but with the owner's own client rollouts, carried out strictly through the public extension contracts with no privileged access into the core — that is the only test available today for whether extending is genuinely easy. From this follows a re-ordering of the work after release: the solutions framework moves ahead of the rest because it carries two pillars out of three, and success is measured as a ladder — the owner's own rollouts get faster, then an outside developer extends the platform without the author's help, then the first third-party solution appears in the open.

## 8. Open questions

- Which of the three pillars carries the first sentence of the project description and the landing page — owner.
- Whether the work queue after release is rebuilt around the bet or left as it stands — owner, at the next plan review.
- The target time from installation to a first working assistant — never named; owner.
- How "not earning money right now" reconciles with the SaaS, billing and marketplace in the plans — owner.
