ALTER TABLE storage_resources
    MODIFY id VARCHAR(128) NOT NULL,
    MODIFY replaces_resource_id VARCHAR(128) NULL;
