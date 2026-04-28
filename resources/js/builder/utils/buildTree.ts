import type { FlowEdge, FlowNode } from '@builder/dto/types'

export interface TreeNode {
    node: FlowNode
    handle: string
    childrenByHandle: Record<string, TreeNode[]>
}

/** Build a tree from a flat graph representation. Root nodes are nodes without incoming edges. */
export function buildTree(nodes: FlowNode[], edges: FlowEdge[]): TreeNode[] {
    const nodeMap: Record<string, FlowNode> = Object.fromEntries(nodes.map((node) => [node.id, node]))
    const incomingIds = new Set(edges.map((edge) => edge.to))
    const roots = nodes.filter((node) => !incomingIds.has(node.id))

    function buildNode(node: FlowNode, handle = 'default', visited = new Set<string>()): TreeNode {
        if (visited.has(node.id)) {
            return { node, handle, childrenByHandle: {} }
        }

        const nextVisited = new Set(visited)
        nextVisited.add(node.id)

        const outgoing = edges.filter((edge) => edge.from === node.id)
        const childrenByHandle: Record<string, TreeNode[]> = {}

        for (const edge of outgoing) {
            const child = nodeMap[edge.to]
            if (!child) {
                continue
            }

            const edgeHandle = edge.handle ?? 'default'
            if (!childrenByHandle[edgeHandle]) {
                childrenByHandle[edgeHandle] = []
            }

            childrenByHandle[edgeHandle].push(buildNode(child, edgeHandle, nextVisited))
        }

        return { node, handle, childrenByHandle }
    }

    return roots.map((root) => buildNode(root))
}

/** Recursively count all descendant nodes reachable from a given TreeNode. */
export function countDescendants(treeNode: TreeNode): number {
    let n = 0
    for (const children of Object.values(treeNode.childrenByHandle ?? {})) {
        for (const child of children) {
            n += 1 + countDescendants(child)
        }
    }
    return n
}
