# Patroni-managed PostgreSQL 17 + PostGIS for the Phase G HA data tier.
# Built on the same PostGIS base the single-node stack uses, so a cluster node
# is byte-for-byte the same database the app already targets — Patroni only adds
# leader election (via etcd) and automatic failover on top.
FROM postgis/postgis:17-3.5

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3 python3-pip python3-psycopg2 \
        curl ca-certificates \
    && pip3 install --no-cache-dir --break-system-packages \
        "patroni[etcd3]" \
    && rm -rf /var/lib/apt/lists/*

# Patroni owns the data directory and runs as postgres.
RUN mkdir -p /var/lib/postgresql/data /etc/patroni \
    && chown -R postgres:postgres /var/lib/postgresql /etc/patroni

COPY docker/patroni/entrypoint.sh /usr/local/bin/patroni-entrypoint.sh
RUN chmod +x /usr/local/bin/patroni-entrypoint.sh

USER postgres

# Patroni REST API (leader probes / failover control) and Postgres.
EXPOSE 8008 5432

ENTRYPOINT ["/usr/local/bin/patroni-entrypoint.sh"]
