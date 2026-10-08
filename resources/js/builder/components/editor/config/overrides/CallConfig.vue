<script setup lang="ts">
/**
 * Call node config — request builder for the transport layer.
 *
 * Edits a local `form` mirror (hydrated from config on node switch) and
 * recompiles it into the canonical config shape on every change:
 *   { transport, target, parameters{prefixed}, transport_options, body_mode,
 *     save_to_variable, result_mapping[] }
 *
 * HTTP transport gets the full builder (method+url, body kv/raw, query,
 * headers, auth, options); handler transport gets a simple action-id +
 * verbatim params form. Response handling (whole-response save + optional
 * field mapping) is shared.
 */
import {computed, reactive, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import TemplateInput from './TemplateInput.vue'
import SearchableSelect from '../SearchableSelect.vue'
import JsonTree from '../JsonTree.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import {useBuilderStore} from '@builder/store/builderStore'
import {type CallTestResult, testCall} from '@builder/api/builderApi'
import type {Variable} from '@builder/dto/types'

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

const knownGroups    = useKnownGroups()
const builderStore   = useBuilderStore()
const availableActions = computed(() => builderStore.availableActions)

// Literal example expression for help text — kept as data so the template
// compiler doesn't try to parse the inner {{ }} as an interpolation.
const EXAMPLE_EXPR = '{{flow.response.body.id}}'
const PLACEHOLDER_HINT = '{{…}}'
// The whole-response save is always a structured object → force json type so
// the picker can expand its nested fields.
const JSON_TYPE_OPTION = [{ value: 'json', label: 'JSON' }]

const METHODS       = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
const SUCCESS_WHEN  = [
    { value: '2xx',          label: '2xx only' },
    { value: '2xx_or_4xx',   label: '2xx or 4xx' },
    { value: 'any_response', label: 'Any response' },
]

interface KvRow { field: string; value: string }
interface MapRow { from: string; to: Variable | null }

const form = reactive({
    transport:    'http' as 'http' | 'handler',
    method:       'POST',
    url:          '',
    // handler
    actionId:     '',
    handlerParams: [] as KvRow[],
    // http body
    bodyMode:     'kv' as 'kv' | 'raw',
    bodyRows:     [] as KvRow[],
    bodyRaw:      '',
    // http query/headers
    queryRows:    [] as KvRow[],
    headerRows:   [] as KvRow[],
    // auth
    authMode:     'none' as 'none' | 'bearer' | 'basic',
    authBearer:   '',
    authUser:     '',
    authPass:     '',
    // options
    timeout:      10 as number,
    successWhen:  '2xx',
    // response
    saveTo:       null as Variable | null,
    mappings:     [] as MapRow[],
})

function rowsFromPrefix(params: Record<string, unknown>, prefix: string): KvRow[] {
    const out: KvRow[] = []
    for (const [k, v] of Object.entries(params)) {
        if (k.startsWith(prefix)) out.push({ field: k.slice(prefix.length), value: String(v ?? '') })
    }
    return out
}

function hydrate() {
    const cfg = (props.node.config ?? {}) as Record<string, unknown>
    const params = (cfg.parameters && typeof cfg.parameters === 'object' ? cfg.parameters : {}) as Record<string, unknown>
    const opts   = (cfg.transport_options && typeof cfg.transport_options === 'object' ? cfg.transport_options : {}) as Record<string, unknown>

    form.transport = cfg.transport === 'handler' ? 'handler' : 'http'

    const target = typeof cfg.target === 'string' ? cfg.target : ''
    if (form.transport === 'http') {
        const space = target.indexOf(' ')
        if (space > 0) {
            form.method = target.slice(0, space).toUpperCase()
            form.url    = target.slice(space + 1)
        } else {
            form.method = 'POST'
            form.url    = target
        }
    } else {
        form.actionId = target
    }

    form.bodyRows   = rowsFromPrefix(params, 'body.')
    form.queryRows  = rowsFromPrefix(params, 'query.')
    form.headerRows = rowsFromPrefix(params, 'headers.')

    // handler params = everything (no prefixes expected)
    form.handlerParams = Object.entries(params).map(([field, value]) => ({ field, value: String(value ?? '') }))

    // auth
    if (typeof params['auth.bearer'] === 'string') {
        form.authMode = 'bearer'; form.authBearer = params['auth.bearer'] as string
    } else if (typeof params['auth.basic.username'] === 'string' || typeof params['auth.basic.password'] === 'string') {
        form.authMode = 'basic'
        form.authUser = String(params['auth.basic.username'] ?? '')
        form.authPass = String(params['auth.basic.password'] ?? '')
    } else {
        form.authMode = 'none'; form.authBearer = ''; form.authUser = ''; form.authPass = ''
    }

    form.bodyRaw     = typeof opts.body_raw === 'string' ? opts.body_raw : ''
    form.bodyMode    = (cfg.body_mode === 'raw' || (form.bodyRaw.trim() !== '' && cfg.body_mode !== 'kv')) ? 'raw' : 'kv'
    form.timeout     = typeof opts.timeout === 'number' ? opts.timeout : Number(opts.timeout ?? 10) || 10
    form.successWhen = typeof opts.success_when === 'string' ? opts.success_when : '2xx'

    form.saveTo   = (cfg.save_to_variable && typeof cfg.save_to_variable === 'object')
        ? cfg.save_to_variable as Variable : null
    form.mappings = Array.isArray(cfg.result_mapping)
        ? (cfg.result_mapping as Array<Record<string, unknown>>).map(m => ({
            from: typeof m.from === 'string' ? m.from : '',
            to:   (m.to && typeof m.to === 'object') ? m.to as Variable : null,
        }))
        : []
}

hydrate()
watch(() => props.node.id, hydrate)

function commit() {
    const parameters: Record<string, string> = {}
    let target: string

    if (form.transport === 'handler') {
        target = form.actionId.trim()
        for (const r of form.handlerParams) {
            if (r.field.trim() !== '') parameters[r.field] = r.value
        }
    } else {
        target = `${form.method} ${form.url}`.trim()
        if (form.bodyMode === 'kv') {
            for (const r of form.bodyRows) if (r.field.trim() !== '') parameters[`body.${r.field}`] = r.value
        }
        for (const r of form.queryRows)  if (r.field.trim() !== '') parameters[`query.${r.field}`] = r.value
        for (const r of form.headerRows) if (r.field.trim() !== '') parameters[`headers.${r.field}`] = r.value
        if (form.authMode === 'bearer' && form.authBearer.trim() !== '') {
            parameters['auth.bearer'] = form.authBearer
        } else if (form.authMode === 'basic') {
            if (form.authUser.trim() !== '') parameters['auth.basic.username'] = form.authUser
            if (form.authPass.trim() !== '') parameters['auth.basic.password'] = form.authPass
        }
    }

    const transport_options: Record<string, unknown> = {
        timeout:      form.timeout,
        success_when: form.successWhen,
    }
    if (form.transport === 'http' && form.bodyMode === 'raw' && form.bodyRaw.trim() !== '') {
        transport_options.body_raw = form.bodyRaw
    }

    emit('update:config', {
        transport:         form.transport,
        target,
        parameters,
        transport_options,
        body_mode:         form.transport === 'http' ? form.bodyMode : undefined,
        save_to_variable:  form.saveTo ?? undefined,
        result_mapping:    form.mappings
            .filter(m => m.from.trim() !== '' && m.to)
            .map(m => ({ from: m.from, to: m.to })),
    })
}

// Row helpers
function addRow(rows: KvRow[]) { rows.push({ field: '', value: '' }); commit() }
function removeRow(rows: KvRow[], i: number) { rows.splice(i, 1); commit() }

function addMapping() { form.mappings.push({ from: '', to: null }); commit() }
function removeMapping(i: number) { form.mappings.splice(i, 1); commit() }
function onMappingTo(i: number, v: Variable) { form.mappings[i].to = v; commit() }

// Add a mapping pre-filled with a path clicked in the test response tree.
function addMappingFrom(path: string) {
    const existing = form.mappings.find(m => m.from === path)
    if (existing) return
    form.mappings.push({ from: path, to: null })
    commit()
}

// ── Test request ──────────────────────────────────────────────────────────────
const VAR_RE = /\{\{\s*([\w.]+)\s*\}\}/g

// Unique {{paths}} referenced anywhere in the compiled config — drives the
// "Test values" form so the author can supply data the live call needs.
const referencedVars = computed<string[]>(() => {
    const cfg = (props.node.config ?? {}) as Record<string, unknown>
    const found = new Set<string>()
    const scan = (s: unknown) => {
        if (typeof s !== 'string') return
        for (const m of s.matchAll(VAR_RE)) found.add(m[1])
    }
    scan(cfg.target)
    const params = (cfg.parameters && typeof cfg.parameters === 'object' ? cfg.parameters : {}) as Record<string, unknown>
    for (const v of Object.values(params)) scan(v)
    const opts = (cfg.transport_options && typeof cfg.transport_options === 'object' ? cfg.transport_options : {}) as Record<string, unknown>
    scan(opts.body_raw)
    return [...found].sort()
})

const sampleValues = reactive<Record<string, string>>({})

const needsWarning = computed(() =>
    form.transport === 'handler' || form.method !== 'GET',
)

const testLoading = ref(false)
const testError   = ref<string | null>(null)
const testResult  = ref<CallTestResult | null>(null)

// Root bag the JsonTree walks — paths match result_mapping `from` semantics.
const testBag = computed(() => testResult.value === null ? null : {
    status:  testResult.value.status_code,
    headers: testResult.value.headers,
    body:    testResult.value.body,
})

// Flatten the last test response into dot-paths so the result_mapping "from"
// field can autocomplete real paths the JSON actually contains.
function flattenPaths(value: unknown, prefix: string, out: string[], cap = 300): void {
    if (out.length >= cap || value === null || typeof value !== 'object') return
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
        if (out.length >= cap) return
        const p = prefix === '' ? k : `${prefix}.${k}`
        out.push(p)
        if (v !== null && typeof v === 'object') flattenPaths(v, p, out, cap)
    }
}

const responsePaths = computed<string[]>(() => {
    if (!testBag.value) return []
    const out: string[] = []
    flattenPaths(testBag.value, '', out)
    return out
})

// ── Remember response structure (for picker autocomplete) ─────────────────────
const manualJson = ref('')
const manualParsed = computed<unknown>(() => {
    const t = manualJson.value.trim()
    if (t === '') return undefined
    try { return JSON.parse(t) } catch { return undefined }
})
const manualInvalid = computed(() => manualJson.value.trim() !== '' && manualParsed.value === undefined)

// Paths to persist — manual JSON (treated as the response body) takes priority,
// else the last test response. Always relative to the whole-response variable.
const structurePaths = computed<string[]>(() => {
    if (manualParsed.value !== undefined) {
        const out: string[] = []
        flattenPaths({ body: manualParsed.value }, '', out)
        return out
    }
    return responsePaths.value
})

const saveToPath = computed<string>(() => {
    const v = form.saveTo
    if (!v?.name) return ''
    if (v.storage === 'contact') return v.group ? `contact.${v.group}.${v.name}` : `contact.${v.name}`
    return `flow.${v.name}`
})

const savedStructureCount = computed<number>(() => {
    const rp = (props.node.config as Record<string, unknown>)?.response_paths
    return Array.isArray(rp) ? rp.length : 0
})

const canRemember = computed(() => !!form.saveTo?.name && structurePaths.value.length > 0)

function rememberStructure() {
    if (!canRemember.value) return
    emit('update:config', { response_paths: structurePaths.value })
}

async function runTest() {
    const cfg = (props.node.config ?? {}) as Record<string, unknown>
    testLoading.value = true
    testError.value   = null
    try {
        const sample: Record<string, string> = {}
        for (const k of referencedVars.value) sample[k] = sampleValues[k] ?? ''
        testResult.value = await testCall(
            {
                transport:         cfg.transport,
                target:            cfg.target,
                parameters:        cfg.parameters,
                transport_options: cfg.transport_options,
            },
            sample,
        )
    } catch (e) {
        testError.value = e instanceof Error ? e.message : 'Request failed'
        testResult.value = null
    } finally {
        testLoading.value = false
    }
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Transport" default-open>
            <div class="config-field">
                <div class="field-label">Transport</div>
                <select class="field-input" :value="form.transport" @change="form.transport = ($event.target as HTMLSelectElement).value as 'http'|'handler'; commit()">
                    <option value="http">HTTP request</option>
                    <option value="handler">Handler (action)</option>
                </select>
            </div>
        </AccordionSection>

        <!-- ───────────────── HTTP ───────────────── -->
        <template v-if="form.transport === 'http'">
            <AccordionSection title="Request" default-open>
                <div class="config-field row">
                    <select class="field-input method-sel" :value="form.method" @change="form.method = ($event.target as HTMLSelectElement).value; commit()">
                        <option v-for="m in METHODS" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <TemplateInput
                        v-model="form.url"
                        placeholder="https://api.example.com/users/{{flow.user_id}}"
                        mono
                        @update:model-value="commit"
                    />
                </div>
            </AccordionSection>

            <AccordionSection title="Body">
                <div class="config-field">
                    <div class="mode-toggle">
                        <button :class="['mode-pill', { active: form.bodyMode === 'kv' }]"  @click="form.bodyMode = 'kv'; commit()">Key-value</button>
                        <button :class="['mode-pill', { active: form.bodyMode === 'raw' }]" @click="form.bodyMode = 'raw'; commit()">Raw JSON</button>
                    </div>
                </div>
                <template v-if="form.bodyMode === 'kv'">
                    <div v-for="(r, i) in form.bodyRows" :key="i" class="config-field row">
                        <input class="field-input kv-key" placeholder="field" :value="r.field" @input="r.field = ($event.target as HTMLInputElement).value; commit()">
                        <TemplateInput v-model="r.value" placeholder="value" @update:model-value="commit" />
                        <button class="del-btn" @click="removeRow(form.bodyRows, i)">×</button>
                    </div>
                    <button class="add-btn" @click="addRow(form.bodyRows)">+ Add field</button>
                </template>
                <template v-else>
                    <TemplateInput v-model="form.bodyRaw" multiline mono :rows="6" placeholder='{ "name": "{{contact.name}}" }' @update:model-value="commit" />
                </template>
            </AccordionSection>

            <AccordionSection title="Query params">
                <div v-for="(r, i) in form.queryRows" :key="i" class="config-field row">
                    <input class="field-input kv-key" placeholder="key" :value="r.field" @input="r.field = ($event.target as HTMLInputElement).value; commit()">
                    <TemplateInput v-model="r.value" placeholder="value" @update:model-value="commit" />
                    <button class="del-btn" @click="removeRow(form.queryRows, i)">×</button>
                </div>
                <button class="add-btn" @click="addRow(form.queryRows)">+ Add param</button>
            </AccordionSection>

            <AccordionSection title="Headers">
                <div v-for="(r, i) in form.headerRows" :key="i" class="config-field row">
                    <input class="field-input kv-key" placeholder="Header" :value="r.field" @input="r.field = ($event.target as HTMLInputElement).value; commit()">
                    <TemplateInput v-model="r.value" placeholder="value" @update:model-value="commit" />
                    <button class="del-btn" @click="removeRow(form.headerRows, i)">×</button>
                </div>
                <button class="add-btn" @click="addRow(form.headerRows)">+ Add header</button>
            </AccordionSection>

            <AccordionSection title="Auth">
                <div class="config-field">
                    <select class="field-input" :value="form.authMode" @change="form.authMode = ($event.target as HTMLSelectElement).value as 'none'|'bearer'|'basic'; commit()">
                        <option value="none">None</option>
                        <option value="bearer">Bearer token</option>
                        <option value="basic">Basic auth</option>
                    </select>
                </div>
                <div v-if="form.authMode === 'bearer'" class="config-field">
                    <div class="field-label">Token</div>
                    <TemplateInput v-model="form.authBearer" placeholder="{{flow.api_token}}" @update:model-value="commit" />
                </div>
                <template v-if="form.authMode === 'basic'">
                    <div class="config-field">
                        <div class="field-label">Username</div>
                        <TemplateInput v-model="form.authUser" placeholder="user" @update:model-value="commit" />
                    </div>
                    <div class="config-field">
                        <div class="field-label">Password</div>
                        <TemplateInput v-model="form.authPass" placeholder="{{flow.password}}" @update:model-value="commit" />
                    </div>
                </template>
            </AccordionSection>

            <AccordionSection title="Options">
                <div class="config-field">
                    <div class="field-label">Timeout (seconds)</div>
                    <input class="field-input" style="width:100px" type="number" min="1" max="300" :value="form.timeout" @input="form.timeout = Number(($event.target as HTMLInputElement).value) || 10; commit()">
                </div>
                <div class="config-field">
                    <div class="field-label">Success when</div>
                    <select class="field-input" :value="form.successWhen" @change="form.successWhen = ($event.target as HTMLSelectElement).value; commit()">
                        <option v-for="s in SUCCESS_WHEN" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <p class="field-help">Determines which responses route to <em>success</em> vs <em>error</em>. Transport failures always go to <em>error</em>.</p>
                </div>
            </AccordionSection>
        </template>

        <!-- ───────────────── HANDLER ───────────────── -->
        <template v-else>
            <AccordionSection title="Action" default-open>
                <div class="config-field">
                    <div class="field-label">Action id</div>
                    <SearchableSelect
                        :model-value="form.actionId"
                        :options="availableActions"
                        placeholder="crm.create_deal"
                        empty-text="No actions available"
                        allow-custom
                        mono
                        @update:model-value="form.actionId = $event; commit()"
                    />
                    <p class="field-help">Registered by an active Solution/Plugin. The list is empty until one provides an action handler — you can still type an id to wire it ahead of time.</p>
                </div>
            </AccordionSection>
            <AccordionSection title="Parameters">
                <div v-for="(r, i) in form.handlerParams" :key="i" class="config-field row">
                    <input class="field-input kv-key" placeholder="key" :value="r.field" @input="r.field = ($event.target as HTMLInputElement).value; commit()">
                    <TemplateInput v-model="r.value" placeholder="value" @update:model-value="commit" />
                    <button class="del-btn" @click="removeRow(form.handlerParams, i)">×</button>
                </div>
                <button class="add-btn" @click="addRow(form.handlerParams)">+ Add parameter</button>
            </AccordionSection>
        </template>

        <!-- ───────────────── TEST (shared) ───────────────── -->
        <AccordionSection title="Test request" default-open>
            <div v-if="referencedVars.length > 0" class="config-field">
                <div class="field-label">Test values</div>
                <div v-for="v in referencedVars" :key="v" class="config-field row">
                    <span class="test-var-key">{{ v }}</span>
                    <input
                        class="field-input"
                        :value="sampleValues[v] ?? ''"
                        placeholder="sample value"
                        @input="sampleValues[v] = ($event.target as HTMLInputElement).value"
                    >
                </div>
                <p class="field-help">No session at build time — these fill the <code>{{ PLACEHOLDER_HINT }}</code> placeholders for the test.</p>
            </div>

            <div v-if="needsWarning" class="test-warn">
                ⚠ This sends a <strong>real</strong> {{ form.transport === 'handler' ? 'action invocation' : form.method + ' request' }} — it may create or modify data.
            </div>

            <button class="test-btn" :disabled="testLoading" @click="runTest">
                {{ testLoading ? 'Sending…' : (needsWarning ? 'Send real request' : 'Send test request') }}
            </button>

            <div v-if="testError" class="test-error">{{ testError }}</div>

            <div v-if="testResult" class="test-result">
                <div class="test-status-row">
                    <span class="test-badge" :class="testResult.success ? 'ok' : 'fail'">
                        {{ testResult.success ? 'success' : 'error' }}
                    </span>
                    <span v-if="testResult.status_code !== null" class="test-meta">HTTP {{ testResult.status_code }}</span>
                    <span v-if="testResult.error_code" class="test-meta">{{ testResult.error_code }}</span>
                    <span class="test-meta">{{ testResult.duration_ms }} ms</span>
                </div>
                <div class="test-tree-hint">Click a field to map it →</div>
                <div class="test-tree">
                    <JsonTree v-if="testBag" :value="testBag" @select="addMappingFrom" />
                </div>
            </div>

            <!-- Capture structure → picker autocomplete -->
            <div class="config-field structure-block">
                <div class="field-label">Response structure for hints</div>
                <p class="field-help">
                    Remember the response shape so its fields autocomplete everywhere this variable is used
                    (Assign, conditions, messages). Source: the last test above, or paste a sample below.
                </p>
                <textarea
                    class="field-input mono"
                    rows="3"
                    :value="manualJson"
                    placeholder='{ "data": { "id": 1, "name": "..." } }'
                    @input="manualJson = ($event.target as HTMLTextAreaElement).value"
                />
                <div v-if="manualInvalid" class="test-error">Invalid JSON</div>

                <button class="test-btn structure-btn" :disabled="!canRemember" @click="rememberStructure">
                    Remember structure ({{ structurePaths.length }} fields)
                </button>

                <p v-if="!form.saveTo?.name" class="field-help">Set “Save full response to” below first.</p>
                <p v-else-if="savedStructureCount > 0" class="field-help structure-ok">
                    ✓ {{ savedStructureCount }} fields available in the picker under <code>{{ saveToPath }}</code>.
                </p>
            </div>
        </AccordionSection>

        <!-- ───────────────── RESPONSE (shared) ───────────────── -->
        <AccordionSection title="Response">
            <div class="config-field">
                <div class="field-label">Save full response to</div>
                <VariableStorageEditor
                    :model-value="form.saveTo"
                    :type-options="JSON_TYPE_OPTION"
                    :known-groups="knownGroups"
                    :owner-node-id="String(props.node.id)"
                    :show-type="false"
                    show-storage
                    show-group
                    @update:model-value="(v: Variable) => { form.saveTo = v; commit() }"
                />
                <p class="field-help">Writes <code>{ status, body, headers }</code>. Extract fields later with Assign — e.g. <code>{{ EXAMPLE_EXPR }}</code>.</p>
            </div>
        </AccordionSection>

        <AccordionSection title="Field mapping (optional)">
            <div v-for="(m, i) in form.mappings" :key="i" class="map-row">
                <div class="config-field">
                    <div class="field-label">Response path</div>
                    <SearchableSelect
                        :model-value="m.from"
                        :options="responsePaths"
                        placeholder="body.data.id"
                        empty-text="Run a test to suggest paths"
                        allow-custom
                        mono
                        @update:model-value="m.from = $event; commit()"
                    />
                </div>
                <div class="config-field">
                    <div class="field-label">→ Variable</div>
                    <VariableStorageEditor
                        :model-value="m.to"
                        :known-groups="knownGroups"
                        :owner-node-id="String(props.node.id)"
                        show-storage
                        show-group
                        @update:model-value="(v: Variable) => onMappingTo(i, v)"
                    />
                </div>
                <button class="del-btn map-del" @click="removeMapping(i)">× Remove mapping</button>
            </div>
            <button class="add-btn" @click="addMapping">+ Add mapping</button>
            <p class="field-help">Paths: <code>body.*</code>, <code>status</code>, <code>headers.*</code>, <code>metadata.*</code>. Missing path → empty.</p>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input class="field-input mono" style="font-size:11.5px" :value="props.node.id" readonly>
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.config-field { margin-bottom: 10px; }
.config-field.row { display: flex; gap: 6px; align-items: flex-start; }
.field-label { font-size: 12px; color: var(--text-2); margin-bottom: 4px; }
.field-input {
    width: 100%;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: var(--font-sans);
    font-size: 12.5px;
    color: var(--text);
    outline: none;
}
.field-input:focus { border-color: var(--primary); background: #fff; }
.field-input.mono { font-family: var(--font-mono); font-size: 12px; }
.method-sel { flex: 0 0 92px; }
.kv-key { flex: 0 0 38%; }
.field-help { margin: 5px 0 0; font-size: 11.5px; color: var(--text-3); line-height: 1.5; }
.field-help code { font-family: var(--font-mono); }
.mode-toggle { display: flex; gap: 4px; }
.mode-pill {
    flex: 1; padding: 5px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--surface-2); font-size: 12px; color: var(--text-2); cursor: pointer;
}
.mode-pill.active { border-color: var(--primary); color: var(--primary); background: var(--primary-bg, #f0f4f8); }
.del-btn {
    background: transparent; border: none; color: var(--text-3); cursor: pointer;
    font-size: 16px; padding: 4px 2px 0; line-height: 1; flex-shrink: 0;
}
.del-btn:hover { color: var(--rose, #e05252); }
.add-btn {
    width: 100%; padding: 6px; border: 1px dashed var(--border-2); border-radius: 6px;
    background: transparent; font-size: 12px; color: var(--text-3); cursor: pointer;
}
.add-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-bg, #f0f4f8); }
.map-row {
    border: 1px solid var(--border); border-radius: 8px; padding: 8px; margin-bottom: 8px; background: var(--surface);
}
.map-del { font-size: 11.5px; color: var(--text-3); }

.test-var-key {
    flex: 0 0 40%;
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-top: 7px;
}
.test-warn {
    margin-bottom: 8px;
    padding: 7px 9px;
    border-radius: 6px;
    background: var(--amber-bg, #fff8e1);
    color: var(--amber, #9a6a00);
    font-size: 11.5px;
    line-height: 1.4;
}
.test-btn {
    width: 100%;
    padding: 7px;
    border: 1px solid var(--primary, #5b7fa6);
    border-radius: 6px;
    background: var(--primary-bg, #eef2f7);
    color: var(--primary, #5b7fa6);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}
.test-btn:disabled { opacity: 0.6; cursor: default; }
.test-error {
    margin-top: 8px;
    padding: 7px 9px;
    border-radius: 6px;
    background: var(--rose-bg, #fdecec);
    color: var(--rose, #c0392b);
    font-size: 11.5px;
}
.test-result { margin-top: 10px; }
.test-status-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.test-badge {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 4px;
    letter-spacing: 0.03em;
}
.test-badge.ok   { background: var(--sage-bg); color: var(--sage); }
.test-badge.fail { background: var(--rose-bg); color: var(--rose); }
.test-meta { font-size: 11px; color: var(--text-3); }
.test-tree-hint { font-size: 11px; color: var(--text-3); font-style: italic; margin-bottom: 4px; }
.test-tree {
    max-height: 280px;
    overflow: auto;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
}
.structure-block {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed var(--border);
}
.structure-btn { margin-top: 6px; }
.structure-ok { color: var(--sage, #5a7d52); }
</style>
