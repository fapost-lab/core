/**
 * @typedef {{ id: string, type: string, version: number, config?: Record<string, unknown> }} FlowNode
 * @typedef {{ id: string, from: string, to: string, handle?: string }} FlowEdge
 * @typedef {{ node: FlowNode, handle: string, childrenByHandle: Record<string, TreeNode[]> }} TreeNode
 */

/**
 * Build a tree from a flat graph representation.
 * Root nodes are nodes without incoming edges.
 *
 * @param {FlowNode[]} nodes
 * @param {FlowEdge[]} edges
 * @returns {TreeNode[]}
 */
export function buildTree(nodes, edges) {
    /** @type {Record<string, FlowNode>} */
    const nodeMap = Object.fromEntries(nodes.map((node) => [node.id, node]));
    const incomingIds = new Set(edges.map((edge) => edge.to));
    const roots = nodes.filter((node) => !incomingIds.has(node.id));

    /**
     * @param {FlowNode} node
     * @param {string} handle
     * @param {Set<string>} visited
     * @returns {TreeNode}
     */
    function buildNode(node, handle = 'default', visited = new Set()) {
        if (visited.has(node.id)) {
            return { node, handle, childrenByHandle: {} };
        }

        const nextVisited = new Set(visited);
        nextVisited.add(node.id);

        const outgoing = edges.filter((edge) => edge.from === node.id);
        /** @type {Record<string, TreeNode[]>} */
        const childrenByHandle = {};

        for (const edge of outgoing) {
            const child = nodeMap[edge.to];
            if (!child) {
                continue;
            }

            const edgeHandle = edge.handle ?? 'default';
            if (!childrenByHandle[edgeHandle]) {
                childrenByHandle[edgeHandle] = [];
            }

            childrenByHandle[edgeHandle].push(buildNode(child, edgeHandle, nextVisited));
        }

        return { node, handle, childrenByHandle };
    }

    return roots.map((root) => buildNode(root));
}
