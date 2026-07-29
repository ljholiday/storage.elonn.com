# Storage

Storage is the Resource byte service for Elonn. It owns stored Resource bytes, Resource metadata, integrity digests, and byte retrieval.

Storage does not own Paint documents, Social photos, Maps tiles, World Objects, avatars, previews, or attachments. Those services own their domain objects and may reference Resources.

## Local Setup

1. Create database `elonn_storage`.
2. Apply migrations in `migrations/`.
3. Copy `.env.example` to `.env` and set database credentials plus service tokens.
4. Run `./test.sh`.

## Routes

- `GET /health`
- `GET /ready`
- `GET /`
- `POST /resources`
- `GET /resources/{id}/metadata`
- `GET /resources/{id}/content`
- `PUT /resources/{id}`
- `DELETE /resources/{id}`
- `POST /storage/call`

Resource operations require first-party service authentication.
