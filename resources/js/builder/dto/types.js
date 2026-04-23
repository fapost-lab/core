/**
 * @typedef {object} BuilderFlowPayload
 * @property {string} flowId
 * @property {string} name
 * @property {number} draftVersion
 * @property {number|null} publishedVersion
 * @property {unknown} [definition] Draft definition from API (array of nodes or { nodes, edges }).
 * @property {BuilderTriggerPayload|null} [trigger]
 * @property {string[]} [availableEvents]
 * @property {string|null} [publishedAt]
 */

/**
 * @typedef {object} BuilderTriggerPayload
 * @property {boolean} [_delete]
 * @property {'message'|'schedule'|'webhook'|'api'|'event'} type
 * @property {boolean} is_active
 * @property {number} priority
 * @property {Record<string, unknown>} config
 */

/**
 * @typedef {object} FlowDefinitionPayload
 * @property {unknown[]} nodes
 * @property {unknown[]} edges
 */

/**
 * @typedef {object} NodeTypePayload
 * @property {string} type
 * @property {number} version
 * @property {string} label
 * @property {string} category
 * @property {unknown} config_schema
 */

export {};
