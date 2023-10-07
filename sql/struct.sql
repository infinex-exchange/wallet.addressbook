CREATE ROLE "wallet.addressbook" LOGIN PASSWORD 'password';

create table withdrawal_adbk(
    adbkid bigserial not null primary key,
    uid bigint not null,
    netid varchar(65) not null,
    address varchar(255) not null,
    name varchar(255) not null,
    memo varchar(255) default null
);

GRANT SELECT, INSERT, UPDATE, DELETE ON withdrawal_adbk TO "wallet.addressbook";
GRANT SELECT, USAGE ON withdrawal_adbk_adbkid_seq TO "wallet.addressbook";
