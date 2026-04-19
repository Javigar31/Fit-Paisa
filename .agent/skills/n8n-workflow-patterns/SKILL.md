---
name: n8n-workflow-patterns
description: Proven workflow architectural patterns from real n8n workflows. Use when building new workflows, designing workflow structure, choosing workflow patterns, planning workflow architecture, or asking about webhook processing, HTTP API integration, database operations, AI agent workflows, batch processing, or scheduled tasks. Always consult this skill when the user asks to create, build, or design an n8n workflow, automate a process, or connect services.
---

Proven architectural patterns for building n8n workflows.

### 1. Webhook Processing (Most Common)
- Receive HTTP requests → Process → Output
- Pattern: Webhook → Validate → Transform → Respond/Notify

### 2. HTTP API Integration
- Fetch from REST APIs → Transform → Store/Use
- Pattern: Trigger → HTTP Request → Transform → Action → Error Handler

### 3. Database Operations
- Read/Write/Sync database data
- Pattern: Schedule → Query → Transform → Write → Verify

### 4. AI Agent Workflow
- AI agents with tools and memory
- Pattern: Trigger → AI Agent (Model + Tools + Memory) → Output

### 5. Scheduled Tasks
- Recurring automation workflows
- Pattern: Schedule → Fetch → Process → Deliver → Log

### 6. Batch Processing
- Process large datasets in chunks with API rate limits
- Pattern: Prepare → SplitInBatches → Process per batch → Accumulate → Aggregate

---

## Webhook Processing - Use when:
- Receiving data from external systems
- Building integrations (Slack commands, form submissions, GitHub webhooks)
- Need instant response to events

## HTTP API Integration - Use when:
- Fetching data from external APIs
- Synchronizing with third-party services
- Building data pipelines

## Database Operations - Use when:
- Syncing between databases
- Running database queries on schedule
- ETL workflows

## AI Agent Workflow - Use when:
- Building conversational AI
- Need AI with tool access
- Multi-step reasoning tasks

## Scheduled Tasks - Use when:
- Recurring reports or summaries
- Periodic data fetching
- Maintenance tasks

## Batch Processing - Use when:
- Processing large datasets that exceed API batch limits
- Need to accumulate results across multiple API calls
- Nested loops

---

### Implementation Checklist
- [ ] Identify the pattern
- [ ] List required nodes
- [ ] Understand data flow (input → transform → output)
- [ ] Plan error handling strategy
- [ ] Create workflow with appropriate trigger
- [ ] Configure authentication/credentials
- [ ] Validate complete workflow (validate_workflow)
- [ ] Test with sample data

---

### Common Gotchas
- **SplitInBatches**: `main[0]` is **done** (fires once), `main[1]` is **each batch**.
- **Google Sheets**: NEVER use `append` on sheets with formula columns; it breaks them. Use `values.update`.
- **Accumulation**: inside a loop, `$('Node').all()` only returns the LAST batch. Use `$getWorkflowStaticData('global')` in a Code node to accumulate.
