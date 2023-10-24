CREATE ROLE "wallet.addressbook" LOGIN PASSWORD 'password';

create table withdrawal_adbk(
    adbkid bigserial not null primary key,
    uid bigint not null,
    netid varchar(65) not null,
    address varchar(255) not null,
    name varchar(255) not null,
    memo varchar(255) default null,
);
create unique index on withdrawal_adbk(uid, netid, name);
create unique index on withdrawal_adbk(uid, netid, address) where memo IS NULL;
create unique index on withdrawal_adbk(uid, netid, address, memo) where memo IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON withdrawal_adbk TO "wallet.addressbook";
GRANT SELECT, USAGE ON withdrawal_adbk_adbkid_seq TO "wallet.addressbook";
