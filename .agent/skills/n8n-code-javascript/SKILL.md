---
name: n8n-code-javascript
description: Write JavaScript code in n8n Code nodes. Use when writing JavaScript in n8n, using $input/$json/$node syntax, making HTTP requests with $helpers, working with dates using DateTime, troubleshooting Code node errors, choosing between Code node modes, or doing any custom data transformation in n8n. Always use this skill when a workflow needs a Code node — whether for data aggregation, filtering, API calls, format conversion, batch processing logic, or any custom JavaScript. Covers SplitInBatches loop patterns, cross-iteration data, pairedItem, and real-world production patterns.
---

Expert guidance for writing JavaScript code in n8n Code nodes.

---

### Quick Start
```javascript
const items = $input.all();
return items.map(item => ({
  json: { ...item.json, processed: true }
}));
```

### Critical Rules
1. **Return Format**: Must return `[{json: {...}}]`.
2. **Webhook Data**: Under `$json.body` (e.g. `$json.body.name`).
3. **Modes**: "All Items" (default/95%) vs "Each Item".
4. **Built-ins**: `$helpers.httpRequest()`, `DateTime` (Luxon), `$jmespath()`.

### Execution Modes
- **All Items**: Executes once. Best for aggregation, filtering, batch processing.
- **Each Item**: Executes per item. Best for independent transformations.

### Common Patterns
- **Aggregation**: Reduce many items to one report.
- **Regex Filtering**: Extract patterns from text.
- **PairedItem**: Use when creating new items to maintain mapping.
- **Static Data**: Use `$getWorkflowStaticData` for persistent loop accumulators.

### Error Solutions
- Check for `paired_item_no_info` error (use pairedItem).
- Avoid n8n expression syntax `{{}}` inside Code node.
- Guard against nulls with optional chaining `?.`.
- Always return a list, even if empty `[]`.
