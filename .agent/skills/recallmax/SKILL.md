---
name: recallmax
description: God-tier long-context memory for AI agents. Manages 500K-1M tokens, auto-summarizes history, and compresses multi-turn conversations without losing intent.
---

# RecallMax — Long-Context Memory Expert

## When to use
- Conversation exceeds 50+ turns.
- Injecting large documents (RAG) into context.
- Preventing hallucination drift in long threads.
- Verifying consistency across a massive history.

## Core Features
1. **Context Injection**: Deduplicates and attributes sources when adding large data.
2. **Adaptive Summarization**: Preserves **tone, sarcasm, and intent** while reducing token count.
3. **High-Density Compression**: Compresses 14 turns into ~800 tokens.
4. **Fact Verification**: Cross-references claims against the entire history.

## Best Practices
- ✅ Start RecallMax at the beginning of intensive sessions.
- ✅ Enable auto-summarization every 20-30 turns.
- ✅ Use compression before reaching window limits.
- ❌ Do not rely on raw truncation; it loses intent.
- ❌ Do not inject unvetted external content without dedup.
