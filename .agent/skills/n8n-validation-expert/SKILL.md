---
name: n8n-validation-expert
description: Interpret validation errors and guide fixing them. Use when encountering validation errors, validation warnings, false positives, operator structure issues, or need help understanding validation results. Also use when asking about validation profiles, error types, the validation loop process, or auto-fix capabilities. Consult this skill whenever a validate_node or validate_workflow call returns errors or warnings — it knows which warnings are false positives and which errors need real fixes.
---

Expert guide for interpreting and fixing n8n validation errors.

---

**Validate early, validate often**. Process is iterative (2-3 cycles).

### 1. Error Types
- `missing_required`: Required field missing.
- `invalid_value`: Value not allowed.
- `type_mismatch`: Wrong data type.
- `invalid_expression`: Expression syntax error.
- `invalid_reference`: Referenced node missing.

### 2. Validation Profiles
- `minimal`: Required fields only.
- `runtime`: Recommended (Standard).
- `ai-friendly`: Reduced false positives.
- `strict`: Production grade (All checks).

### 3. Auto-fix Capabilities
`n8n_autofix_workflow` fixes:
- Expression formats.
- Type/Version mismatches.
- Known node type corrections.
- Missing webhook paths.

### 4. Auto-Sanitization
Automatically fixes:
- **Binary vs Unary Operators**: Adds/removes `singleValue` based on operation.
- **Logic Metadata**: Adds missing options for IF/Switch.

### Best Practices
- Look for technical false positives (missing error handling, no retry).
- Follow the loop: Validate -> Read -> Fix -> Validate.
- Use `cleanStaleConnections` for "node not found" errors in workflows.
