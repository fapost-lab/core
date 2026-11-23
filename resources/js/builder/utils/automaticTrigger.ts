/**
 * An automatic trigger is one of any type but `message` (flow GLOSSARY): it starts runs without anyone writing to the
 * assistant, so making it live, changing or removing it needs the Manage automations permission. The server keeps
 * the same rule in `FlowPublishChanges::automatic()`; every builder check of it asks here.
 */
export function isAutomaticTrigger(type: unknown): boolean {
    return type !== 'message'
}
