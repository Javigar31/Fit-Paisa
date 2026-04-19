---
name: n8n-node-configuration
description: Operation-aware node configuration guidance. Use when configuring nodes, understanding property dependencies, determining required fields, choosing between get_node detail levels, or learning common configuration patterns by node type. Always use this skill when setting up node parameters — it explains which fields are required for each operation, how displayOptions control field visibility, and when to use patchNodeField for surgical edits vs full node updates.
---

Expert guidance for operation-aware node configuration with property dependencies.

---

**Progressive disclosure**: Start minimal, add complexity as needed.

### 1. Operation-Aware Configuration
Resource + operation determine which fields are required. Fields change based on the selected action.

### 2. Property Dependencies
Fields appear/disappear based on other field values (controlled by `displayOptions`).

### 3. Progressive Discovery
- **get_node({detail: "standard"})** - DEFAULT (95% of needs).
- **get_node({mode: "search_properties"})** - Find specific fields.
- **get_node({detail: "full"})** - Complete schema (use sparingly).

### Standard Process
1. Identify node type and operation.
2. Use `get_node` (standard).
3. Configure required fields.
4. Validate config.
5. Search specific fields if unclear.
6. Add optional fields.
7. Final validation and deploy.

### Common Patterns
- **Resource/Operation**: Entity-based actions (Slack, Sheets).
- **HTTP-Based**: method, url, sendBody dependencies.
- **Database**: operation specific (query, insert, update).
- **Logic**: conditional structure requirements (IF, Switch).

### Key Tips
- Don't over-configure upfront.
- Always validate before deploying.
- Use `patchNodeField` for surgical string edits inside fields.
- Follow the discovery loop: standard -> search -> full.
