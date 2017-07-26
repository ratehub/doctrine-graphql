#!/usr/bin/env bash
psql -U postgres -c "create user testuser with password 'aeqeacadq';"
psql -U postgres << EOF
        create database testdb with owner testuser;
        grant all privileges on database testdb to testuser;
        \c testdb;
EOF

export PGPASSWORD='aeqeacadq'
psql -U testuser -h localhost -d testdb << EOF
        CREATE OR REPLACE FUNCTION "reset_sequence" (tablename text) RETURNS "pg_catalog"."void" AS
        \$body\$
        DECLARE
        BEGIN
        EXECUTE 'SELECT setval( '''
                || tablename
                || '_id_seq'', '
                || '(SELECT id + 1 FROM "'
                || tablename
                || '" ORDER BY id DESC LIMIT 1), false)';
        END;
        \$body\$  LANGUAGE 'plpgsql';
EOF
