# RAG Modes: Tool vs Inject

ai-kit supports two Retrieval-Augmented Generation strategies, switchable with
a single config flag: `ai-kit.mode`.

## Tool mode (default)

In tool mode the agent is given a `SearchKnowledgeBase` tool. The language model
decides when to search, issues one or more queries, reads the returned passages,
and composes an answer. This is *agentic RAG*: the model can search multiple
times, refine its query, and reason about what it finds. It shines when questions
are open-ended or require several lookups.

## Inject mode

In inject mode the agent is given no search tool. Instead, before the prompt runs,
`ContextInjector` retrieves the most relevant chunks for the user's message and
prepends them to the prompt inside a `<context>` block. This is *classic RAG*: one
retrieval, one generation. It is cheaper and more predictable, and a good fit when
every question is expected to be answerable from the knowledge base.

## Choosing a mode

Both modes consume the same `Retriever` contract, so switching is free and does
not change how documents are ingested. Start with tool mode for flexibility; move
to inject mode when you want tighter cost control and simpler behaviour.
