CREATE TABLE IF NOT EXISTS servers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    api_token TEXT NOT NULL,
    ssh_link_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ssh_port INT UNSIGNED NOT NULL DEFAULT 22,
    ssh_username VARCHAR(190) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS local_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    invoice_email VARCHAR(190) NULL,
    customer_number VARCHAR(190) NULL,
    company VARCHAR(190) NULL,
    first_name VARCHAR(190) NULL,
    last_name VARCHAR(190) NULL,
    phone VARCHAR(190) NULL,
    address TEXT NULL,
    postcode VARCHAR(40) NULL,
    city VARCHAR(190) NULL,
    region VARCHAR(190) NULL,
    country VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNSIGNED NOT NULL,
    local_user_id INT UNSIGNED NULL,
    external_id VARCHAR(190) NULL,
    domain VARCHAR(255) NOT NULL,
    owner_external_id VARCHAR(190) NULL,
    owner_name VARCHAR(190) NULL,
    registered_at DATE NULL,
    next_billing_at DATE NULL,
    billing_frequency ENUM('monthly', 'bimonthly', 'quarterly', 'halfyearly', 'yearly') NOT NULL DEFAULT 'yearly',
    last_change_at DATE NULL,
    registrar VARCHAR(190) NULL,
    domain_owner_contact TEXT NULL,
    domain_admin_c TEXT NULL,
    domain_tech_c TEXT NULL,
    domain_zone_c TEXT NULL,
    domain_status INT NULL,
    is_disabled TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    suspend_on DATE NULL,
    delete_on DATE NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_server_domain (server_id, domain),
    CONSTRAINT fk_domains_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_domains_local_user FOREIGN KEY(local_user_id) REFERENCES local_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS keyhelp_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNSIGNED NOT NULL,
    local_user_id INT UNSIGNED NULL,
    external_id VARCHAR(190) NOT NULL,
    username VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    raw_json JSON NOT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_server_user (server_id, external_id),
    CONSTRAINT fk_users_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_users_local_user FOREIGN KEY(local_user_id) REFERENCES local_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hosting_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(190) NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    limits_json JSON NOT NULL,
    scope ENUM('system', 'server') NOT NULL DEFAULT 'system',
    server_id INT UNSIGNED NULL,
    synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_server_hosting_package (server_id, external_id),
    CONSTRAINT fk_packages_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planned_actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    server_id INT UNSIGNED NULL,
    payload_json JSON NOT NULL,
    status ENUM('pending', 'done', 'failed') NOT NULL DEFAULT 'pending',
    result_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME NULL,
    CONSTRAINT fk_actions_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status ENUM('running', 'done', 'failed') NOT NULL,
    message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_tax_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    rate_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_tld_prices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tld VARCHAR(80) NOT NULL,
    registration_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    yearly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    change_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_rate_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_tld (tld),
    CONSTRAINT fk_billing_tld_tax FOREIGN KEY(tax_rate_id) REFERENCES billing_tax_rates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_domain_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    registration_price DECIMAL(10,2) NULL,
    yearly_price DECIMAL(10,2) NULL,
    change_price DECIMAL(10,2) NULL,
    discount_percent DECIMAL(7,3) NULL,
    tax_rate_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_domain_override (domain_id),
    CONSTRAINT fk_billing_override_domain FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_override_tax FOREIGN KEY(tax_rate_id) REFERENCES billing_tax_rates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_user_settings (
    user_id INT UNSIGNED PRIMARY KEY,
    discount_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    invoice_frequency ENUM('immediate', 'weekly', 'monthly') NOT NULL DEFAULT 'monthly',
    last_invoice_at DATE NULL,
    next_invoice_at DATE NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_billing_user_settings_user FOREIGN KEY(user_id) REFERENCES local_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_user_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    description_text TEXT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_rate_id INT UNSIGNED NULL,
    frequency ENUM('once', 'monthly', 'bimonthly', 'quarterly', 'halfyearly', 'yearly') NOT NULL DEFAULT 'monthly',
    booking_date DATE NOT NULL,
    last_billed_at DATE NULL,
    next_billing_at DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_billing_user_items_user FOREIGN KEY(user_id) REFERENCES local_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_user_items_tax FOREIGN KEY(tax_rate_id) REFERENCES billing_tax_rates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status ENUM('running', 'done', 'failed') NOT NULL DEFAULT 'running',
    last_run_at DATETIME NULL,
    current_until DATETIME NOT NULL,
    message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_pending_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    tax_rate_id INT UNSIGNED NULL,
    tax_rate_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    net_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    gross_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    service_date DATE NULL,
    billing_reference VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pending_billing_reference (billing_reference),
    KEY idx_pending_user (user_id),
    CONSTRAINT fk_billing_pending_user FOREIGN KEY(user_id) REFERENCES local_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_pending_tax FOREIGN KEY(tax_rate_id) REFERENCES billing_tax_rates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(80) NOT NULL,
    status ENUM('draft', 'pending_approval', 'approved', 'queued', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending_approval',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    period_start DATETIME NULL,
    period_end DATETIME NOT NULL,
    pdf_path VARCHAR(255) NULL,
    recipient_snapshot JSON NULL,
    sender_snapshot TEXT NULL,
    send_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    sent_at DATETIME NULL,
    paid_at DATE NULL,
    payment_reference VARCHAR(190) NULL,
    payment_note TEXT NULL,
    immutable_at DATETIME NULL,
    UNIQUE KEY uniq_invoice_number (invoice_number),
    CONSTRAINT fk_invoices_user FOREIGN KEY(user_id) REFERENCES local_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    tax_rate_id INT UNSIGNED NULL,
    tax_rate_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    net_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    gross_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    service_date DATE NULL,
    billing_reference VARCHAR(190) NOT NULL,
    UNIQUE KEY uniq_invoice_item_reference (billing_reference),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_items_tax FOREIGN KEY(tax_rate_id) REFERENCES billing_tax_rates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    paid_at DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference VARCHAR(190) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_billing_payments_user (user_id),
    CONSTRAINT fk_billing_payments_user FOREIGN KEY(user_id) REFERENCES local_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_payment_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_allocations_payment (payment_id),
    KEY idx_payment_allocations_invoice (invoice_id),
    CONSTRAINT fk_payment_allocations_payment FOREIGN KEY(payment_id) REFERENCES billing_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_allocations_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS billing_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor VARCHAR(190) NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT UNSIGNED NULL,
    details_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_billing_audit_entity (entity_type, entity_id),
    KEY idx_billing_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
