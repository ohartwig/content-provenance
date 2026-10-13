CREATE TABLE tx_provenance_signature (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    content_table varchar(100) DEFAULT '' NOT NULL,
    content_uid int(11) unsigned DEFAULT '0' NOT NULL,
    content_language int(11) DEFAULT '0' NOT NULL,
    content_hash varchar(64) DEFAULT '' NOT NULL,
    signature text,
    signed_by int(11) unsigned DEFAULT '0' NOT NULL,
    signed_by_name varchar(255) DEFAULT '' NOT NULL,
    signed_at int(11) unsigned DEFAULT '0' NOT NULL,
    key_id varchar(64) DEFAULT '' NOT NULL,
    revoked tinyint(1) unsigned DEFAULT '0' NOT NULL,
    revoked_at int(11) unsigned DEFAULT '0' NOT NULL,
    revoked_by int(11) unsigned DEFAULT '0' NOT NULL,
    revoked_reason text,
    PRIMARY KEY (uid),
    UNIQUE KEY content_lookup (content_table, content_uid, content_language),
    KEY key_id (key_id),
    KEY revoked (revoked)
);

CREATE TABLE tx_provenance_audit_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    content_table varchar(100) DEFAULT '' NOT NULL,
    content_uid int(11) unsigned DEFAULT '0' NOT NULL,
    content_language int(11) DEFAULT '0' NOT NULL,
    action varchar(20) DEFAULT '' NOT NULL,
    performed_by int(11) unsigned DEFAULT '0' NOT NULL,
    performed_by_name varchar(255) DEFAULT '' NOT NULL,
    performed_at int(11) unsigned DEFAULT '0' NOT NULL,
    field_changes text,
    ai_generated tinyint(1) unsigned DEFAULT '0' NOT NULL,
    ai_provider varchar(50) DEFAULT '' NOT NULL,
    ai_model varchar(100) DEFAULT '' NOT NULL,
    ai_reviewed_by int(11) unsigned DEFAULT '0' NOT NULL,
    ai_reviewed_by_name varchar(255) DEFAULT '' NOT NULL,
    ai_reviewed_at int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    KEY content_lookup (content_table, content_uid, content_language),
    KEY action (action),
    KEY ai_generated (ai_generated)
);

#
# Asset-provenance columns extending TYPO3 core tables. EU AI Act Art. 50
# compliance: who/what generated this media. AssetOrigin enum-string values
# are stored as-is (human/ai_generated/ai_assisted).
#

CREATE TABLE sys_file_metadata (
    tx_provenance_origin varchar(20) DEFAULT 'human' NOT NULL,
    tx_provenance_ai_system varchar(100) DEFAULT '' NOT NULL,
    tx_provenance_ai_prompt text,
    tx_provenance_detected_at int(11) unsigned DEFAULT '0' NOT NULL,
    tx_provenance_c2pa_status varchar(20) DEFAULT 'none' NOT NULL,
    KEY tx_provenance_origin (tx_provenance_origin)
);

CREATE TABLE sys_file_reference (
    tx_provenance_origin varchar(20) DEFAULT '' NOT NULL
);

CREATE TABLE pages (
    tx_provenance_origin varchar(20) DEFAULT 'human' NOT NULL,
    tx_provenance_ai_system varchar(100) DEFAULT '' NOT NULL,
    KEY tx_provenance_origin (tx_provenance_origin)
);
