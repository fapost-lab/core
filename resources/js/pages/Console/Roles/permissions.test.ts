import { describe, expect, it } from 'vitest'
import { toggleValue } from '../Users/roles'
import { selectedInGroup, toggleGroup } from './permissions'
import type { PermissionGroup } from './types'

const users: PermissionGroup = {
  key: 'users',
  label: 'Users',
  permissions: [
    { value: 'manage_users', label: 'Manage users', description: '', sensitive: false },
    { value: 'manage_roles', label: 'Manage roles', description: '', sensitive: true },
  ],
}

describe('selectedInGroup', () => {
  it('counts only the group permissions that are selected', () => {
    expect(selectedInGroup(users, ['manage_roles', 'view_contacts'])).toBe(1)
    expect(selectedInGroup(users, [])).toBe(0)
  })
})

describe('toggleGroup', () => {
  it('adds every permission of the group once and keeps the others', () => {
    expect(toggleGroup(users, ['view_contacts', 'manage_users'], true)).toEqual(['view_contacts', 'manage_users', 'manage_roles'])
  })

  it('removes the group and keeps the others', () => {
    expect(toggleGroup(users, ['view_contacts', 'manage_users', 'manage_roles'], false)).toEqual(['view_contacts'])
  })
})

describe('toggleValue', () => {
  it('adds a value once and removes it', () => {
    expect(toggleValue(['a'], 'b', true)).toEqual(['a', 'b'])
    expect(toggleValue(['a', 'b'], 'b', true)).toEqual(['a', 'b'])
    expect(toggleValue(['a', 'b'], 'a', false)).toEqual(['b'])
  })
})
