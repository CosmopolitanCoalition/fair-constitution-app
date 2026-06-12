BASE=http://localhost:8081
PY="/c/Program Files/Python311/python"
J=/tmp/fcwjars
mkdir -p "$J"

# tok JAR -- decoded XSRF token from a cookie jar (python, no awk)
tok() {
  "$PY" - "$1" <<'PYEOF'
import sys, urllib.parse
tok = ""
for line in open(sys.argv[1]):
    p = line.rstrip("\n").split("\t")
    if len(p) >= 7 and p[5] == "XSRF-TOKEN":
        tok = p[6]
print(urllib.parse.unquote(tok))
PYEOF
}

# _auth EMAIL -- one login + login-as attempt into the jar
_auth() {
  local email="$1" jar="$J/$email.jar"
  rm -f "$jar"
  curl -s -c "$jar" -o /dev/null "$BASE/login"
  curl -s -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $(tok "$jar")" -H "Accept: application/json" \
    --data-urlencode "email=$email" -o /dev/null "$BASE/dev/login-as"
}

# as EMAIL -- passwordless session jar named by email
as() { _auth "$1"; }

# P EMAIL PATH [SEL]  -- inertia props at dotted SEL (re-auths on a redirect)
P() {
  local email="$1" path="$2" sel="${3:-}" jar="$J/$1.jar" out tries=0
  while [ "$tries" -lt 4 ]; do
    out=$(curl -s -b "$jar" "$BASE$path")
    case "$out" in
      *data-page=*) echo "$out" | "$PY" /tmp/fcw_props.py "$sel"; return 0 ;;
    esac
    _auth "$email"
    tries=$((tries + 1))
  done
  echo '{"error":"unauthed after retries"}'
}

# X EMAIL PATH key=val ...  -- POST form. A 302 means the back()-redirect
# fired AFTER a successful engine filing (Inertia POSTs answer 303/302);
# only 419 (CSRF/session) is a real failure worth one re-auth + retry.
X() {
  local email="$1" path="$2" jar="$J/$email.jar"; shift 2
  local args=() kv code
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  code=$(curl -s -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $(tok "$jar")" -H "Accept: application/json" \
    "${args[@]}" -o /tmp/x.json -w "%{http_code}" "$BASE$path")
  if [ "$code" = "419" ]; then
    _auth "$email"
    code=$(curl -s -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $(tok "$jar")" -H "Accept: application/json" \
      "${args[@]}" -o /tmp/x.json -w "%{http_code}" "$BASE$path")
  fi
  echo "HTTP $code :: $(head -c 280 /tmp/x.json)"
}

# XJ EMAIL PATH  -- POST a raw JSON body from /tmp/body.json
XJ() {
  local email="$1" path="$2" jar="$J/$email.jar"
  local code
  code=$(curl -s -b "$jar" -c "$jar" -H "X-XSRF-TOKEN: $(tok "$jar")" -H "Accept: application/json" \
    -H "Content-Type: application/json" --data @/tmp/body.json \
    -o /tmp/x.json -w "%{http_code}" "$BASE$path")
  echo "HTTP $code :: $(head -c 280 /tmp/x.json)"
}
