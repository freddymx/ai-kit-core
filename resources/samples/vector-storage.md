# Vector Storage: SQLite-vec and pgvector

ai-kit stores embeddings behind a single `Retriever` contract with two
interchangeable drivers, selected by `ai-kit.retrieval.driver`.

## SQLite (default)

The default driver is `sqlite`. It requires no extra infrastructure, which is
what makes the 30-minute demo possible. When the sqlite-vec (vec1) loadable
extension is available, it uses the extension's approximate nearest-neighbour
index for fast cosine search. When the extension is not present, it falls back to
an exact cosine scan computed in PHP over the collection's chunks. The fallback is
slower on large corpora but perfectly correct, and more than fast enough for
demos, prototypes, and small knowledge bases.

## pgvector (production)

For production workloads on PostgreSQL, set the driver to `pgvector`. Embeddings
are stored in a `vector` column indexed with HNSW (or IVFFlat), and similarity is
computed by the database using the `<=>` cosine-distance operator. This scales to
millions of chunks with low query latency.

## Portability

Every chunk also stores its raw embedding as JSON and the name of the embedding
model used. That makes re-indexing with a different model detectable, and lets you
migrate between drivers without re-embedding your documents.
