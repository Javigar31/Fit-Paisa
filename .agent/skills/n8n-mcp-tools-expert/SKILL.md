---
name: n8n-mcp-tools-expert
description: Expert guide for using n8n-mcp MCP tools effectively. Use when searching for nodes, validating configurations, accessing templates, managing workflows, managing credentials, auditing instance security, or using any n8n-mcp tool. Provides tool selection guidance, parameter formats, and common patterns. IMPORTANT — Always consult this skill before calling any n8n-mcp tool — it prevents common mistakes like wrong nodeType formats, incorrect parameter structures, and inefficient tool usage. If the user mentions n8n, workflows, nodes, or automation and you have n8n MCP tools available, use this skill first.
---

Master guide for using n8n-mcp MCP server tools to build workflows.

---

n8n-mcp provides tools organized into categories:

1. **Node Discovery**
2. **Configuration Validation**
3. **Workflow Management**
4. **Template Library** - Search and deploy 2,700+ real workflows
5. **Data Tables** - Manage n8n data tables and rows (`n8n_manage_datatable`)
6. **Credential Management** - Full credential CRUD + schema discovery (`n8n_manage_credentials`)
7. **Security & Audit** - Instance security auditing with custom deep scan (`n8n_audit_instance`)
8. **Documentation & Guides** - Tool docs, AI agent guide, Code node guides

---

| Tool | Use When | Speed |
|------|----------|-------|
| `search_nodes` | Finding nodes by keyword | <20ms |
| `get_node` | Understanding node operations (detail="standard") | <10ms |
| `validate_node` | Checking configurations (mode="full") | <100ms |
| `n8n_create_workflow` | Creating workflows | 100-500ms |
| `n8n_update_partial_workflow` | Editing workflows (MOST USED!) | 50-200ms |
| `validate_workflow` | Checking complete workflow | 100-500ms |
| `n8n_deploy_template` | Deploy template to n8n instance | 200-500ms |
| `n8n_manage_datatable` | Managing data tables and rows | 50-500ms |
| `n8n_manage_credentials` | Credential CRUD + schema discovery | 50-500ms |
| `n8n_audit_instance` | Security audit (built-in + custom scan) | 500-5000ms |
| `n8n_autofix_workflow` | Auto-fix validation errors | 200-1500ms |

---

### Finding the Right Node
**Workflow**:
```javascript
1. search_nodes({query: "keyword"})
2. get_node({nodeType: "nodes-base.name"})
3. [Optional] get_node({nodeType: "nodes-base.name", mode: "docs"})
```

**Common pattern**: search → get_node (18s average)

### Validating Configuration
**Workflow**:
```javascript
1. validate_node({nodeType, config: {}, mode: "minimal"}) - Check required fields
2. validate_node({nodeType, config, profile: "runtime"}) - Full validation
3. [Repeat] Fix errors, validate again
```

### Managing Workflows
**Workflow**:
```javascript
1. n8n_create_workflow({name, nodes, connections})
2. n8n_validate_workflow({id})
3. n8n_update_partial_workflow({id, operations: [...]})
4. n8n_validate_workflow({id}) again
5. n8n_update_partial_workflow({id, operations: [{type: "activateWorkflow"}]})
```

---

**Two different formats** for different tools!

### Format 1: Search/Validate Tools
```javascript
"nodes-base.slack"
"nodes-base.httpRequest"
```

### Format 2: Workflow Tools
```javascript
"n8n-nodes-base.slack"
"n8n-nodes-base.httpRequest"
```

---

### Patterns & Best Practices
- Use `get_node` with `detail: "standard"` (default) - covers 95% of use cases.
- Use `intent` parameter in workflow updates for better AI guidance.
- Use `patchNodeField` for surgical edits.
- **Auto-sanitization** runs on all saves to fix operator structures.
- **Data tables** and **Credentials** have dedicated management tools.
- **Security audits** available via `n8n_audit_instance`.
