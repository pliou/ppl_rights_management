#
# Table structure for table 'tx_pplrightsmanagement_history'
#
CREATE TABLE tx_pplrightsmanagement_history (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    backend_user_uid int(11) DEFAULT '0' NOT NULL,
    backend_user_name varchar(255) DEFAULT '' NOT NULL,
    impersonated_user_uid int(11) DEFAULT '0' NOT NULL,
    impersonated_user_name varchar(255) DEFAULT '' NOT NULL,
    scope varchar(80) DEFAULT '' NOT NULL,
    action varchar(80) DEFAULT '' NOT NULL,
    summary text,
    payload_before mediumtext,
    payload_after mediumtext,

    event_id varchar(40) DEFAULT '' NOT NULL,
    status varchar(20) DEFAULT '' NOT NULL,

    reverts_history_uid int(11) DEFAULT '0' NOT NULL,
    reverted_by_history_uid int(11) DEFAULT '0' NOT NULL,
    reverted_at int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY tstamp (tstamp),
    KEY backend_user_uid (backend_user_uid),
    KEY scope (scope),
    KEY event_id (event_id),
    KEY reverts_history_uid (reverts_history_uid),
    KEY reverted_by_history_uid (reverted_by_history_uid)
);
