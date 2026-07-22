# 05 · Group Storage Model

## 5.1 Concept

Группа — это вложенный объект в `contact.attributes` JSONB или в `flow_sessions.state.flow` JSONB. Не отдельная таблица, не отдельный namespace.

```json
{
  "name": "Иван",
  "form": {
    "input1": "value1",
    "input2": "value2"
  },
  "address": {
    "city": "Москва"
  }
}
```

## 5.2 Group vs leaf

Различение по `jsonb_typeof()`:
- `'object'` → группа
- иначе → leaf

## 5.3 Reserved группы

`contact.meta.*` — owned by webhook ingress. Reserved.

## 5.4 Discovery API (для UI и reports)

Все группы тенанта (для group dropdown в UI):

```sql
SELECT DISTINCT key
FROM contacts c, jsonb_each(c.attributes) AS e(key, value)
WHERE c.tenant_id = ?
  AND jsonb_typeof(value) = 'object'
  AND key NOT IN ('meta');  -- reserved
```

Cache в Redis set `tenant:{id}:contact_groups`, обновляется через listener `FlowDefinitionSaved`. Поскольку `assign.target` — литерал (см. [nodes/05-assign.md](nodes/05-assign.md)), extraction групп статически возможна:

```php
public function handle(FlowDefinitionSaved $event): void
{
    $groups = $this->extractGroups($event->definition);
    foreach ($groups as $group) {
        Redis::sadd("tenant:{$event->tenantId}:contact_groups", $group);
    }
}

private function extractGroups(FlowDefinition $def): array
{
    $groups = [];
    foreach ($def->nodes as $node) {
        if ($node['type'] === 'assign') {
            foreach ($node['config']['operations'] as $op) {
                if (preg_match('/^contact\.([^.]+)\./', $op['target'], $m)) {
                    $groups[] = $m[1];
                }
            }
        }
        if ($node['type'] === 'input') {
            $var = $node['config']['variable'];
            if ($var['storage'] === 'contact' && $var['group']) {
                $groups[] = $var['group'];
            }
        }
    }
    return array_unique($groups);
}
```

Поля внутри группы:

```sql
SELECT DISTINCT e.key, jsonb_typeof(e.value) AS type
FROM contacts c, jsonb_each(c.attributes->'<group>') AS e(key, value)
WHERE c.tenant_id = ?
  AND jsonb_typeof(c.attributes->'<group>') = 'object';
```

## 5.5 Reports

Базовые SQL запросы для V1 (без UI builder):

```sql
-- Все ответы группы для CSV экспорта
SELECT
  c.id,
  c.attributes->'<group>'->>'<field1>' AS field1,
  c.attributes->'<group>'->>'<field2>' AS field2
FROM contacts c
WHERE c.tenant_id = ?
  AND jsonb_typeof(c.attributes->'<group>') = 'object';
```

UI report builder — отдельный feature, не V1.

## 5.6 Indexing

V1: общий GIN на `attributes`:

```sql
CREATE INDEX idx_contacts_attributes_gin
ON contacts USING gin (attributes);
```

Точечные partial indexes — добавляются при появлении нагрузки на конкретные группы.

---

## Связано с

- [[01-state-model]] — state model
- [[05-contacts]] — Contact groups
- [[05-assign]] — assign нода обновляет group membership
