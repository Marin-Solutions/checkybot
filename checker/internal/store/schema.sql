CREATE TABLE IF NOT EXISTS websites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    uptime_check TINYINT(1) NOT NULL DEFAULT 0,
    ssl_check TINYINT(1) NOT NULL DEFAULT 0,
    uptime_interval INT NULL,
    package_interval VARCHAR(64) NULL,
    source VARCHAR(32) NULL,
    ssl_expiry_date DATETIME NULL,
    ssl_expiry_reminder_sent_at DATETIME NULL,
    current_status VARCHAR(32) NULL,
    status_summary TEXT NULL,
    latest_scheduled_result_at DATETIME NULL,
    diagnostic_queued_at DATETIME NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS website_log_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    website_id BIGINT UNSIGNED NOT NULL,
    ssl_expiry_date DATETIME NULL,
    http_status_code INT NULL,
    speed INT NULL,
    status VARCHAR(32) NULL,
    summary TEXT NULL,
    transport_error_type VARCHAR(32) NULL,
    transport_error_message VARCHAR(1000) NULL,
    transport_error_code INT NULL,
    run_source VARCHAR(32) NULL,
    is_on_demand TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT website_log_history_website_fk FOREIGN KEY (website_id) REFERENCES websites (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS monitor_apis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    http_method VARCHAR(16) NULL,
    headers LONGTEXT NULL,
    request_body_type VARCHAR(16) NULL,
    request_body LONGTEXT NULL,
    title VARCHAR(255) NULL,
    expected_status INT NULL,
    timeout_seconds INT NULL,
    retry_count INT NULL,
    max_response_time_ms INT NULL,
    data_path VARCHAR(255) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    save_failed_response TINYINT(1) NOT NULL DEFAULT 0,
    package_interval VARCHAR(64) NULL,
    current_status VARCHAR(32) NULL,
    status_summary TEXT NULL,
    latest_scheduled_result_at DATETIME NULL,
    diagnostic_queued_at DATETIME NULL,
    deleted_at DATETIME NULL,
    updated_at DATETIME NULL,
    created_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS monitor_api_assertions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    monitor_api_id BIGINT UNSIGNED NOT NULL,
    data_path VARCHAR(255) NULL,
    assertion_type VARCHAR(64) NULL,
    expected_type VARCHAR(64) NULL,
    comparison_operator VARCHAR(16) NULL,
    expected_value TEXT NULL,
    regex_pattern TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT monitor_api_assertions_fk FOREIGN KEY (monitor_api_id) REFERENCES monitor_apis (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS monitor_api_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    monitor_api_id BIGINT UNSIGNED NOT NULL,
    is_success TINYINT(1) NOT NULL,
    consecutive_count INT NOT NULL DEFAULT 1,
    response_time_ms INT NOT NULL,
    max_response_time_ms INT NULL,
    effective_timeout_seconds INT NULL,
    retry_count INT NULL,
    elapsed_wall_time_ms INT NULL,
    http_code INT NOT NULL,
    failed_assertions JSON NULL,
    response_body JSON NULL,
    status VARCHAR(32) NULL,
    summary TEXT NULL,
    transport_error_type VARCHAR(32) NULL,
    transport_error_message VARCHAR(1000) NULL,
    transport_error_code INT NULL,
    request_headers JSON NULL,
    response_headers JSON NULL,
    run_source VARCHAR(32) NULL,
    is_on_demand TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT monitor_api_results_fk FOREIGN KEY (monitor_api_id) REFERENCES monitor_apis (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checker_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(32) NOT NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checker_locks (
    lock_key VARCHAR(191) NOT NULL PRIMARY KEY,
    owner VARCHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NULL,
    updated_at DATETIME(6) NULL,
    INDEX checker_locks_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checker_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(32) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(32) NULL,
    status VARCHAR(32) NULL,
    summary TEXT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    processed_at DATETIME(6) NULL,
    INDEX checker_outbox_kind (kind),
    INDEX checker_outbox_created (created_at),
    INDEX checker_outbox_processed (processed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checker_shadow_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(32) NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    laravel_status VARCHAR(32) NULL,
    go_status VARCHAR(32) NULL,
    match_result VARCHAR(16) NOT NULL,
    detail VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY checker_shadow_subject (kind, subject_id)
) ENGINE=InnoDB;
