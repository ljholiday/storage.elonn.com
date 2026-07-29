CREATE TABLE IF NOT EXISTS storage_resources (
    id VARCHAR(80) NOT NULL PRIMARY KEY,
    media_type VARCHAR(255) NOT NULL,
    byte_length BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    owner VARCHAR(255) NOT NULL,
    created_by_service VARCHAR(120) NOT NULL,
    replaces_resource_id VARCHAR(80) NULL,
    storage_key VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    modified_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_storage_resources_owner (owner),
    INDEX idx_storage_resources_sha256 (sha256),
    INDEX idx_storage_resources_replaces (replaces_resource_id),
    INDEX idx_storage_resources_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
