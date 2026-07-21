# ai-kit Overview

ai-kit is a reusable Laravel toolkit for building Retrieval-Augmented
Generation (RAG) chat experiences quickly. It is built by SolutionForest, a Hong
Kong software agency, for rapidly prototyping AI chat on client Laravel projects.

## What it gives you

- **Ingestion**: upload PDF, text, HTML, or Markdown documents and turn them into
  searchable, embedded chunks.
- **Retrieval**: cosine-similarity search over a vector store, scoped to named
  knowledge bases called *collections*.
- **Agents**: a RAG agent built on the official Laravel AI SDK that either calls a
  search tool or has retrieved context injected into its prompt.
- **Chat UI**: a Livewire 3 component with streaming responses and source
  citations.
- **Admin**: a Filament panel for managing collections, documents, and viewing
  conversations, plus a Playground page for client demos.

## The 30-minute promise

The goal is simple: `composer require solutionforest/ai-kit-core`, run
`php artisan ai-kit:install`, and have a working RAG chat demo in under thirty
minutes on any Laravel application — no external vector database required.
