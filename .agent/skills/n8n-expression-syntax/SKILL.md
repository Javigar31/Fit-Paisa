---
name: n8n-expression-syntax
description: Validate n8n expression syntax and fix common errors. Use when writing n8n expressions, using {{}} syntax, accessing $json/$node variables, troubleshooting expression errors, mapping data between nodes, or referencing webhook data in workflows.
---

Expert guide for writing correct n8n expressions.

### The Double Curly Braces Basis
All dynamic content in n8n uses `{{expression}}`.
- ✅ `{{$json.email}}`
- ✅ `{{$node["HTTP Request"].json.data}}`
- ❌ `$json.email` (literal text)
- ❌ `{$json.email}` (invalid)

### $json - Current Node Output
Access data from the current node:
- `{{$json.fieldName}}`
- `{{$json['field with spaces']}}`
- `{{$json.nested.property}}`

### $node - Reference Other Nodes
Access data from any previous node (Case Sensitive!):
- `{{$node["Node Name"].json.fieldName}}`
- `{{$node["Webhook"].json.body.email}}`

### ⚠️ Webhook Data Trap
Webhook data is nested under `.body`.
- ❌ `{{$json.email}}`
- ✅ `{{$json.body.email}}`

### Code Nodes vs Expressions
Code nodes use **direct JavaScript access**, NOT expressions.
- ❌ `const email = '={{$json.email}}';`
- ✅ `const email = $json.email;`

### Rules of Thumb
1. **Always Use {{}}**: Mandatory for dynamic fields.
2. **Bracket Notation**: Use `['field name']` for spaces or special characters.
3. **Exact Names**: Node names are case-sensitive and must be in quotes.
4. **No Nesting**: Do not use `{{ {{$json.x}} }}`.

### common date formats
- `{{$now.toFormat('yyyy-MM-dd')}}`
- `{{$now.plus({days: 7}).toISO()}}`
- `{{DateTime.fromISO($json.date).toFormat('MMMM dd')}}`
