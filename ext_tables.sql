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

    PRIMARY KEY (uid),
    KEY tstamp (tstamp),
    KEY backend_user_uid (backend_user_uid),
    KEY scope (scope)
);
