#!/usr/bin/env bash
# SSH tunnel for the "mysql-prod" MCP server defined in .mcp.json.
#
# Prod MariaDB only accepts the `master` user from the server's own localhost,
# so we forward local port 3307 -> server localhost:3306 over the `oddminds`
# SSH key. Bring this up before querying the mysql-prod MCP server.
#
#   scripts/prod-db-tunnel.sh up      # open the tunnel (idempotent)
#   scripts/prod-db-tunnel.sh down    # close it
#   scripts/prod-db-tunnel.sh status  # up | down
set -euo pipefail

LOCAL_PORT=3307
TUNNEL_SPEC="${LOCAL_PORT}:127.0.0.1:3306"
MATCH="ssh -f -N -L ${TUNNEL_SPEC} oddminds"

is_up() { lsof -iTCP:"${LOCAL_PORT}" -sTCP:LISTEN -n >/dev/null 2>&1; }

case "${1:-up}" in
  up)
    if is_up; then
      echo "prod-db tunnel already up on localhost:${LOCAL_PORT}"
      exit 0
    fi
    ssh -f -N -L "${TUNNEL_SPEC}" oddminds
    echo "prod-db tunnel up: localhost:${LOCAL_PORT} -> oddminds:3306"
    ;;
  down)
    pkill -f "${MATCH}" && echo "prod-db tunnel closed" || echo "no prod-db tunnel running"
    ;;
  status)
    is_up && echo "up" || echo "down"
    ;;
  *)
    echo "usage: $0 {up|down|status}" >&2
    exit 1
    ;;
esac
