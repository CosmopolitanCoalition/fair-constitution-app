#!/usr/bin/env bash
# Generate a per-node patroni.yml from environment, then hand off to Patroni.
# Patroni manages PostgreSQL: it bootstraps the primary, clones replicas, holds
# the leader key in etcd, and auto-promotes a replica when the primary dies.
#
# Node-specific values arrive as env (compose sets them per service):
#   NODE_NAME                  unique member name (patroni1 / patroni2 / …)
#   NODE_HOST                  this node's hostname on the compose network
#   ETCD_HOSTS                 etcd endpoints, e.g. http://etcd:2379
#   PG_SUPERUSER / PG_PASSWORD app/superuser login (matches the app's DB_USERNAME)
#   PG_REPL_USER / PG_REPL_PASSWORD  streaming-replication login
#   PG_DB                      the application database to create on bootstrap
set -euo pipefail

NODE_NAME="${NODE_NAME:?NODE_NAME required}"
NODE_HOST="${NODE_HOST:-$(hostname -i | awk '{print $1}')}"
ETCD_HOSTS="${ETCD_HOSTS:-http://etcd:2379}"
PG_SUPERUSER="${PG_SUPERUSER:-fc_user}"
PG_PASSWORD="${PG_PASSWORD:-fc_password}"
PG_REPL_USER="${PG_REPL_USER:-replicator}"
PG_REPL_PASSWORD="${PG_REPL_PASSWORD:-replicator_password}"
PG_DB="${PG_DB:-fair_constitution}"
DATA_DIR="/var/lib/postgresql/data/pgdata"
BIN_DIR="/usr/lib/postgresql/17/bin"

cat > /etc/patroni/patroni.yml <<YAML
scope: cga-cluster
namespace: /cga/
name: ${NODE_NAME}

restapi:
  listen: 0.0.0.0:8008
  connect_address: ${NODE_HOST}:8008

etcd3:
  hosts: ${ETCD_HOSTS}

bootstrap:
  # Written to etcd ONCE by the first node; the cluster-wide source of truth.
  dcs:
    ttl: 30
    loop_wait: 10
    retry_timeout: 10
    maximum_lag_on_failover: 1048576
    postgresql:
      use_pg_rewind: true
      parameters:
        max_connections: 200
        wal_level: replica
        hot_standby: "on"
        max_wal_senders: 10
        max_replication_slots: 10
        wal_keep_size: 256MB
  initdb:
    - encoding: UTF8
    - data-checksums
  # The HA equivalent of init.sql: create the app database + the PostGIS stack
  # so a freshly-bootstrapped cluster is identical to the single-node DB.
  post_init: /usr/local/bin/patroni-post-init.sh
  pg_hba:
    - host replication ${PG_REPL_USER} 0.0.0.0/0 md5
    - host all all 0.0.0.0/0 md5

postgresql:
  listen: 0.0.0.0:5432
  connect_address: ${NODE_HOST}:5432
  data_dir: ${DATA_DIR}
  bin_dir: ${BIN_DIR}
  authentication:
    superuser:
      username: ${PG_SUPERUSER}
      password: ${PG_PASSWORD}
    replication:
      username: ${PG_REPL_USER}
      password: ${PG_REPL_PASSWORD}

tags:
  nofailover: false
  noloadbalance: false
  clonefrom: false
  nosync: false
YAML

# post_init runs inside the bootstrapping primary; create app DB + PostGIS.
cat > /usr/local/bin/patroni-post-init.sh <<'POSTINIT'
#!/usr/bin/env bash
set -euo pipefail
# $1 is a psql connection string Patroni passes to post_init.
CONN="$1"
psql "$CONN" -v ON_ERROR_STOP=1 <<SQL
  SELECT 'CREATE DATABASE ${PG_DB:-fair_constitution}'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${PG_DB:-fair_constitution}')\gexec
SQL
psql "${CONN%/*}/${PG_DB:-fair_constitution}" -v ON_ERROR_STOP=1 <<SQL
  CREATE EXTENSION IF NOT EXISTS postgis;
  CREATE EXTENSION IF NOT EXISTS postgis_raster;
  CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
SQL
POSTINIT
chmod +x /usr/local/bin/patroni-post-init.sh

exec patroni /etc/patroni/patroni.yml
