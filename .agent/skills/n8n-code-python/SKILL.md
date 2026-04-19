---
name: n8n-code-python
description: Write Python code in n8n Code nodes. Use when writing Python in n8n, using _input/_json/_node syntax, working with standard library, or need to understand Python limitations in n8n Code nodes. Use this skill when the user specifically requests Python for an n8n Code node. Note — JavaScript is recommended for 95% of use cases — only use Python when the user explicitly prefers it or the task requires Python-specific standard library capabilities (regex, hashlib, statistics).
---

Expert guidance for writing Python code in n8n Code nodes.

---

### ⚠️ JavaScript First
Recommend **JavaScript for 95% of use cases**. Use Python ONLY for specific standard library needs or explicit user preference.

### Quick Start
```python
items = _input.all()
processed = [{"json": {**item["json"], "processed": True}} for item in items]
return processed
```

### Critical Limitations
1. **NO EXTERNAL LIBRARIES**: No `requests`, `pandas`, `numpy`.
2. **Standard Library ONLY**: `json`, `datetime`, `re`, `hashlib`, `statistics`, etc.

### Rules & Data Access
1. **Return Format**: Must return list of dicts: `[{"json": {...}}]`.
2. **Webhook Data**: Under `_json["body"]`.
3. **Data Helpers**: `_input.all()`, `_input.first()`, `_input.item`.
4. **Beta vs Native**: Use **Python (Beta)** for better helper integration.

### Common Patterns
- **Data Transformation**: List comprehensions for efficient mapping.
- **Statistics**: Use the `statistics` module (mean, median, stdev).
- **Regex**: Use `re` for complex string matching.
- **Safe Access**: Always use `.get()` to avoid KeyErrors.

### Comparison
- Use JavaScript for HTTP requests and advanced date handling (Luxon).
- Use Python for math/stat heavy operations via standard library.
