from __future__ import annotations

import os
from contextlib import contextmanager
from pathlib import Path
from typing import Any

import pymysql
from flask import Flask, jsonify, render_template_string, request
from pymysql.cursors import DictCursor


def load_env_file(path: Path) -> None:
    if not path.is_file():
        return

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip("'").strip('"')
        os.environ.setdefault(key, value)


BASE_DIR = Path(__file__).resolve().parent.parent
load_env_file(BASE_DIR / ".env")

app = Flask(__name__)


def env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


def db_settings() -> dict[str, Any]:
    return {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "user": os.getenv("DB_USER", "root"),
        "password": os.getenv("DB_PASS", ""),
        "database": os.getenv("DB_NAME", "lexsh_db"),
        "port": env_int("DB_PORT", 3306),
        "charset": os.getenv("DB_CHARSET", "utf8mb4"),
        "cursorclass": DictCursor,
        "autocommit": True,
    }


@contextmanager
def db_connection():
    connection = pymysql.connect(**db_settings())
    try:
        yield connection
    finally:
        connection.close()


def fetch_all(sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    with db_connection() as connection:
        with connection.cursor() as cursor:
            cursor.execute(sql, params)
            rows = cursor.fetchall()
    return list(rows)


def fetch_one(sql: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
    rows = fetch_all(sql, params)
    return rows[0] if rows else None


def fetch_value(sql: str, params: tuple[Any, ...] = (), key: str = "total") -> int:
    row = fetch_one(sql, params) or {key: 0}
    try:
        return int(row.get(key) or 0)
    except (TypeError, ValueError):
        return 0


def safe_limit(value: str | None, default: int = 10, max_value: int = 50) -> int:
    try:
        parsed = int(value or default)
    except ValueError:
        return default
    return max(1, min(parsed, max_value))


def analytics_payload() -> dict[str, Any]:
    roles = fetch_all("SELECT role, COUNT(*) AS total FROM users WHERE is_active = 1 GROUP BY role ORDER BY role")
    roles_map = {row["role"]: int(row["total"]) for row in roles}
    total_cases = fetch_value("SELECT COUNT(*) AS total FROM cases")
    active_lawyers_count = fetch_value("SELECT COUNT(*) AS total FROM lawyers WHERE status = 'active'")
    open_cases_count = fetch_value("SELECT COUNT(*) AS total FROM cases WHERE status IN ('open', 'ongoing')")
    risk_coverage_count = fetch_value("SELECT COUNT(*) AS total FROM clients")
    upcoming_appointments = fetch_value(
        """
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE scheduled_at >= NOW()
          AND status IN ('pending', 'confirmed')
          AND status <> 'deleted'
        """
    )
    case_files_total = fetch_value("SELECT COUNT(*) AS total FROM case_files")

    cases = fetch_all(
        """
        SELECT c.status, COUNT(*) AS total
        FROM cases c
        JOIN clients cl ON cl.id = c.client_id
        JOIN users cu ON cu.id = cl.user_id
        JOIN lawyers l ON l.id = c.lawyer_id
        JOIN users lu ON lu.id = l.user_id
        WHERE cu.is_active = 1
          AND lu.is_active = 1
          AND l.status = 'active'
        GROUP BY c.status
        ORDER BY c.status
        """
    )
    cases_map = {row["status"]: int(row["total"]) for row in cases}

    priorities = fetch_all(
        """
        SELECT c.priority, COUNT(*) AS total
        FROM cases c
        JOIN clients cl ON cl.id = c.client_id
        JOIN users cu ON cu.id = cl.user_id
        JOIN lawyers l ON l.id = c.lawyer_id
        JOIN users lu ON lu.id = l.user_id
        WHERE cu.is_active = 1
          AND lu.is_active = 1
          AND l.status = 'active'
        GROUP BY c.priority
        ORDER BY c.priority
        """
    )
    priorities_map = {row["priority"]: int(row["total"]) for row in priorities}

    documents_total = fetch_one("SELECT COUNT(*) AS total FROM documents") or {"total": 0}
    messages_total = fetch_one(
        "SELECT COUNT(*) AS total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread FROM messages"
    ) or {"total": 0, "unread": 0}
    notifications_total = fetch_one(
        "SELECT COUNT(*) AS total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread FROM notifications"
    ) or {"total": 0, "unread": 0}
    risk_rows = fetch_all("SELECT risk_level, COUNT(*) AS total FROM clients GROUP BY risk_level ORDER BY risk_level")
    failed_ips = fetch_all(
        """
        SELECT ip_address, COUNT(*) AS total
        FROM audit_logs
        WHERE action = 'failed_login'
        GROUP BY ip_address
        ORDER BY total DESC, ip_address ASC
        LIMIT 6
        """
    )
    failed_login_count = fetch_value("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'failed_login'")
    locked_accounts = fetch_value(
        "SELECT COUNT(*) AS total FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()"
    )
    latest_failed_login_row = fetch_one(
        "SELECT performed_at FROM audit_logs WHERE action = 'failed_login' ORDER BY performed_at DESC LIMIT 1"
    )
    latest_failed_login = latest_failed_login_row["performed_at"] if latest_failed_login_row else None

    recent_cases = fetch_all(
        """
        SELECT c.case_number, c.title, c.status, c.priority, c.filed_date,
               cu.full_name AS client_name, lu.full_name AS lawyer_name
        FROM cases c
        JOIN clients cl ON cl.id = c.client_id
        JOIN users cu ON cu.id = cl.user_id
        JOIN lawyers l ON l.id = c.lawyer_id
        JOIN users lu ON lu.id = l.user_id
        WHERE cu.is_active = 1
          AND lu.is_active = 1
          AND l.status = 'active'
        ORDER BY c.id DESC
        LIMIT 6
        """
    )

    recent_audit = fetch_all(
        """
        SELECT a.action, a.target_table, a.target_id, a.performed_at, COALESCE(u.full_name, 'System') AS full_name
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.performed_at DESC
        LIMIT 6
        """
    )

    active_lawyers = fetch_all(
        """
        SELECT cu.full_name AS lawyer_name, l.specialization, l.status
        FROM lawyers l
        JOIN users cu ON cu.id = l.user_id
        WHERE cu.is_active = 1
          AND l.status = 'active'
        ORDER BY cu.created_at DESC, cu.full_name ASC
        LIMIT 5
        """
    )

    active_clients = fetch_all(
        """
        SELECT cu.full_name AS client_name, cl.risk_level
        FROM clients cl
        JOIN users cu ON cu.id = cl.user_id
        WHERE cu.is_active = 1
        ORDER BY cu.created_at DESC, cu.full_name ASC
        LIMIT 5
        """
    )

    return {
        "users": {
            "total": sum(roles_map.values()),
            "by_role": roles_map,
        },
        "overview": {
            "total_cases": total_cases,
            "active_lawyers": active_lawyers_count,
            "open_cases": open_cases_count,
            "risk_coverage": risk_coverage_count,
            "upcoming_appointments": upcoming_appointments,
            "case_files": case_files_total,
        },
        "cases": {
            "total": total_cases,
            "by_status": cases_map,
            "by_priority": priorities_map,
        },
        "documents": {"total": int(documents_total["total"] or 0)},
        "messages": {
            "total": int(messages_total["total"] or 0),
            "unread": int(messages_total["unread"] or 0),
        },
        "notifications": {
            "total": int(notifications_total["total"] or 0),
            "unread": int(notifications_total["unread"] or 0),
        },
        "risk_overview": risk_rows,
        "security": {
            "failed_logins": failed_login_count,
            "locked_accounts": locked_accounts,
            "latest_failed_login": latest_failed_login,
            "top_failed_ips": failed_ips,
        },
        "recent_cases": recent_cases,
        "recent_audit": recent_audit,
        "active_lawyers": active_lawyers,
        "active_clients": active_clients,
    }


@app.get("/")
def home():
    return render_template_string(
        """
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>LEXSHIELD Flask Service</title>
          <style>
            :root {
              --bg: #070d18;
              --bg2: #13243f;
              --panel: rgba(255,255,255,.08);
              --border: rgba(255,255,255,.12);
              --text: #f5f7fb;
              --muted: rgba(245,247,251,.72);
              --gold: #f4df94;
            }
            * { box-sizing: border-box; }
            body {
              margin: 0;
              min-height: 100vh;
              font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
              color: var(--text);
              background:
                radial-gradient(circle at top left, rgba(244,223,148,.18), transparent 25%),
                linear-gradient(180deg, var(--bg), var(--bg2));
              display: grid;
              place-items: center;
              padding: 1rem;
            }
            .shell {
              width: min(100%, 1080px);
              display: grid;
              gap: 1rem;
            }
            .hero, .card {
              border: 1px solid var(--border);
              background: var(--panel);
              border-radius: 28px;
              box-shadow: 0 24px 70px rgba(0,0,0,.25);
              backdrop-filter: blur(18px);
            }
            .hero {
              padding: 2rem;
              display: grid;
              gap: 1rem;
            }
            .hero h1 { margin: 0; font-size: clamp(2rem, 4vw, 3.6rem); line-height: 1.05; }
            .hero p { margin: 0; max-width: 64ch; color: var(--muted); }
            .actions { display: flex; flex-wrap: wrap; gap: .75rem; }
            .button {
              display: inline-flex;
              align-items: center;
              justify-content: center;
              padding: .85rem 1rem;
              border-radius: 14px;
              text-decoration: none;
              font-weight: 700;
              border: 1px solid var(--border);
            }
            .button.primary { background: var(--gold); color: #141414; }
            .button.secondary { color: var(--text); background: rgba(255,255,255,.04); }
            .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
            .card { padding: 1rem; }
            .card h2 { margin: 0 0 .35rem; font-size: 1rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
            .card strong { font-size: 1.5rem; }
            @media (max-width: 840px) { .grid { grid-template-columns: 1fr; } }
          </style>
        </head>
        <body>
          <main class="shell">
            <section class="hero">
              <div class="pill" style="display:inline-flex;padding:.4rem .75rem;border-radius:999px;background:rgba(244,223,148,.12);border:1px solid rgba(244,223,148,.22);width:fit-content;">Flask microservice</div>
              <h1>LEXSHIELD Flask Service</h1>
              <p>
                This Python service now connects directly to MySQL, serves analytics, and exposes JSON endpoints for the project.
              </p>
              <div class="actions">
                <a class="button primary" href="/dashboard">Open Analytics Dashboard</a>
                <a class="button secondary" href="/api/analytics">View API JSON</a>
                <a class="button secondary" href="/health">Health Check</a>
              </div>
            </section>
          </main>
        </body>
        </html>
        """
    )


@app.get("/health")
def health():
    db_ok = False
    try:
        with db_connection() as connection:
            with connection.cursor() as cursor:
                cursor.execute("SELECT 1 AS ok")
                db_ok = bool(cursor.fetchone())
    except Exception as exc:  # pragma: no cover
        return jsonify(
            {
                "status": "degraded",
                "service": "lexshield-flask",
                "database": "down",
                "error": str(exc),
            }
        ), 500

    return jsonify(
        {
            "status": "ok",
            "service": "lexshield-flask",
            "database": "up" if db_ok else "down",
            "app_url": os.getenv("APP_URL", ""),
            "api_url": os.getenv("API_URL", ""),
        }
    )


@app.get("/api/analytics")
def api_analytics():
    return jsonify(analytics_payload())


@app.get("/api/cases")
def api_cases():
    limit = safe_limit(request.args.get("limit"), default=10, max_value=25)
    rows = fetch_all(
        """
        SELECT c.case_number, c.title, c.status, c.priority, c.filed_date, c.closed_date,
               cu.full_name AS client_name, lu.full_name AS lawyer_name
        FROM cases c
        JOIN clients cl ON cl.id = c.client_id
        JOIN users cu ON cu.id = cl.user_id
        JOIN lawyers l ON l.id = c.lawyer_id
        JOIN users lu ON lu.id = l.user_id
        WHERE cu.is_active = 1
          AND lu.is_active = 1
          AND l.status = 'active'
        ORDER BY c.id DESC
        LIMIT %s
        """,
        (limit,),
    )
    return jsonify({"items": rows, "limit": limit})


@app.get("/api/messages")
def api_messages():
    limit = safe_limit(request.args.get("limit"), default=10, max_value=25)
    case_id = request.args.get("case_id")
    params: tuple[Any, ...]
    where_clause = ""
    if case_id and case_id.isdigit():
        where_clause = "WHERE m.case_id = %s"
        params = (int(case_id), limit)
    else:
        params = (limit,)

    sql = f"""
        SELECT m.sent_at, m.is_read, c.case_number,
               su.full_name AS sender_name, su.role AS sender_role,
               ru.full_name AS receiver_name, ru.role AS receiver_role
        FROM messages m
        JOIN users su ON su.id = m.sender_id
        JOIN users ru ON ru.id = m.receiver_id
        JOIN cases c ON c.id = m.case_id
        {where_clause}
        ORDER BY m.sent_at DESC
        LIMIT %s
    """
    rows = fetch_all(sql, params)
    return jsonify({"items": rows, "limit": limit, "case_id": int(case_id) if case_id and case_id.isdigit() else None})


@app.get("/dashboard")
def dashboard():
    return render_dashboard_admin(analytics_payload())

    data = analytics_payload()

    def status_cards(mapping: dict[str, int], labels: list[str]) -> str:
        total = sum(mapping.values()) or 1
        parts = []
        for label in labels:
            value = mapping.get(label, 0)
            width = max(8, int((value / total) * 100))
            parts.append(
                f"""
                <div class="bar-row">
                  <div class="bar-head"><span>{label.title()}</span><strong>{value}</strong></div>
                  <div class="bar-track"><div class="bar-fill" style="width:{width}%"></div></div>
                </div>
                """
            )
        return "".join(parts)

    html = f"""
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>LEXSHIELD Analytics</title>
      <style>
        :root {{
          --bg: #f5f7fb;
          --bg2: #e9edf5;
          --surface: rgba(255,255,255,.92);
          --surface-strong: #fff;
          --border: rgba(10,22,40,.12);
          --text: #0a1628;
          --muted: #5e6b7f;
          --gold: #c9a84c;
          --shadow: 0 18px 50px rgba(10,22,40,.11);
          --radius: 24px;
        }}
        * {{ box-sizing: border-box; }}
        body {{
          margin: 0;
          font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          color: var(--text);
          background:
            radial-gradient(circle at top left, rgba(201,168,76,.15), transparent 28%),
            linear-gradient(180deg, var(--bg), var(--bg2));
          min-height: 100vh;
          padding: 1rem;
        }}
        .shell {{ max-width: 1320px; margin: 0 auto; display: grid; gap: 1rem; }}
        .hero, .card {{
          background: var(--surface);
          border: 1px solid var(--border);
          border-radius: 28px;
          box-shadow: var(--shadow);
          backdrop-filter: blur(16px);
        }}
        .hero {{ padding: 1.25rem; display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }}
        .hero h1 {{ margin: 0; font-size: clamp(1.4rem, 2.6vw, 2.3rem); }}
        .hero p {{ margin: .25rem 0 0; color: var(--muted); }}
        .button {{
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: .8rem 1rem;
          border-radius: 14px;
          text-decoration: none;
          font-weight: 700;
          border: 1px solid var(--border);
          color: inherit;
          background: var(--surface-strong);
        }}
        .button.primary {{ background: linear-gradient(135deg, var(--gold), #e5ca72); color: #1a1a1a; }}
        .grid {{ display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }}
        .card {{ padding: 1rem; }}
        .metric {{ display: grid; gap: .35rem; }}
        .metric span {{ color: var(--muted); font-size: .9rem; }}
        .metric strong {{ font-size: 1.9rem; letter-spacing: -.03em; }}
        .layout {{ display: grid; grid-template-columns: 1.05fr .95fr; gap: 1rem; }}
        .two-col {{ display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }}
        .list {{ display: grid; gap: .75rem; }}
        .item {{
          padding: .9rem 1rem;
          border-radius: 18px;
          border: 1px solid var(--border);
          background: rgba(255,255,255,.5);
          display: flex;
          justify-content: space-between;
          gap: 1rem;
        }}
        .item small, .muted {{ color: var(--muted); }}
        .pill {{
          display: inline-flex;
          align-items: center;
          padding: .3rem .65rem;
          border-radius: 999px;
          background: rgba(201,168,76,.14);
          border: 1px solid rgba(201,168,76,.25);
          width: fit-content;
          font-size: .8rem;
        }}
        .bars {{ display: grid; gap: .85rem; }}
        .bar-row {{ display: grid; gap: .4rem; }}
        .bar-head {{ display: flex; justify-content: space-between; gap: 1rem; }}
        .bar-track {{ height: 10px; border-radius: 999px; background: rgba(10,22,40,.08); overflow: hidden; }}
        .bar-fill {{ height: 100%; border-radius: inherit; background: linear-gradient(90deg, #c9a84c, #7fb069); }}
        .table-wrap {{ overflow-x: auto; }}
        table {{ width: 100%; border-collapse: collapse; }}
        th, td {{ text-align: left; padding: .75rem .6rem; border-bottom: 1px solid var(--border); vertical-align: top; }}
        th {{ color: var(--muted); font-size: .85rem; }}
        .endpoint-grid {{ display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }}
        code {{ display: inline-block; padding: .2rem .45rem; border-radius: 8px; background: rgba(10,22,40,.06); }}
        @media (max-width: 1100px) {{
          .grid, .layout, .two-col, .endpoint-grid {{ grid-template-columns: 1fr; }}
        }}
      </style>
    </head>
    <body>
      <main class="shell">
        <section class="hero">
          <div>
            <div class="pill">Direct MySQL analytics</div>
            <h1>LEXSHIELD Flask Analytics Dashboard</h1>
            <p>Live counts for users, cases, documents, messages, notifications, and recent activity.</p>
          </div>
          <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
            <a class="button primary" href="/api/analytics">JSON Analytics</a>
            <a class="button" href="/api/cases?limit=10">Cases API</a>
            <a class="button" href="/api/messages?limit=10">Messages API</a>
          </div>
        </section>

        <section class="grid">
          <div class="card metric"><span>Total Users</span><strong>{data["users"]["total"]}</strong></div>
          <div class="card metric"><span>Total Cases</span><strong>{data["cases"]["total"]}</strong></div>
          <div class="card metric"><span>Unread Messages</span><strong>{data["messages"]["unread"]}</strong></div>
          <div class="card metric"><span>Documents</span><strong>{data["documents"]["total"]}</strong></div>
        </section>

        <section class="layout">
          <article class="card">
            <h2 style="margin-top:0;">Role Breakdown</h2>
            <div class="bars">
              {status_cards(data["users"]["by_role"], ["admin", "lawyer", "client"])}
            </div>
          </article>
          <article class="card">
            <h2 style="margin-top:0;">Case Status</h2>
            <div class="bars">
              {status_cards(data["cases"]["by_status"], ["open", "ongoing", "closed", "archived"])}
            </div>
          </article>
        </section>

        <section class="two-col">
          <article class="card">
            <h2 style="margin-top:0;">Recent Cases</h2>
            <div class="list">
              {"".join(
                f"""
                <div class="item">
                  <div>
                    <strong>{row["case_number"]}</strong>
                    <div class="muted">{row["title"]}</div>
                    <small>{row["client_name"]} · {row["lawyer_name"]}</small>
                  </div>
                  <div style="text-align:right;">
                    <div class="pill">{row["status"]}</div>
                    <small>{row["priority"]}</small>
                  </div>
                </div>
                """
                for row in data["recent_cases"]
              )}
            </div>
          </article>
          <article class="card">
            <h2 style="margin-top:0;">Recent Messages</h2>
            <div class="list">
              {"".join(
                f"""
                <div class="item">
                  <div>
                    <strong>{row["sender_name"]} → {row["receiver_name"]}</strong>
                    <div class="muted">{row["case_number"]}</div>
                    <small>{row["sent_at"]}</small>
                  </div>
                  <div style="text-align:right;">
                    <div class="pill">{row["sender_role"]}</div>
                    <small>{'Unread' if int(row["is_read"]) == 0 else 'Read'}</small>
                  </div>
                </div>
                """
                for row in data["recent_messages"]
              )}
            </div>
          </article>
        </section>

        <section class="two-col">
          <article class="card">
            <h2 style="margin-top:0;">Active Lawyers</h2>
            <div class="list">
              {"".join(
                f"""
                <div class="item">
                  <strong>{row["lawyer_name"]}</strong>
                  <span>{row["specialization"]}</span>
                </div>
                """
                for row in data["active_lawyers"]
              )}
            </div>
          </article>
          <article class="card">
            <h2 style="margin-top:0;">Active Clients</h2>
            <div class="list">
              {"".join(
                f"""
                <div class="item">
                  <strong>{row["client_name"]}</strong>
                  <span>{row["risk_level"]} risk</span>
                </div>
                """
                for row in data["active_clients"]
              )}
            </div>
          </article>
        </section>

        <section class="card">
          <h2 style="margin-top:0;">API Endpoints</h2>
          <div class="endpoint-grid">
            <div class="item"><div><strong>/health</strong><div class="muted">Service + DB status</div></div><code>GET</code></div>
            <div class="item"><div><strong>/api/analytics</strong><div class="muted">All dashboard metrics</div></div><code>GET</code></div>
            <div class="item"><div><strong>/api/cases?limit=10</strong><div class="muted">Recent case list</div></div><code>GET</code></div>
            <div class="item"><div><strong>/api/messages?limit=10</strong><div class="muted">Recent messages</div></div><code>GET</code></div>
          </div>
        </section>
      </main>
    </body>
    </html>
    """
    return render_template_string(html)


def render_dashboard_admin(data: dict[str, Any]) -> str:
    def status_cards(mapping: dict[str, int], labels: list[str]) -> str:
        total = sum(mapping.values()) or 1
        parts = []
        for label in labels:
            value = mapping.get(label, 0)
            width = max(8, int((value / total) * 100))
            parts.append(
                f"""
                <div class="bar-row">
                  <div class="bar-head"><span>{label.title()}</span><strong>{value}</strong></div>
                  <div class="bar-track"><div class="bar-fill" style="width:{width}%"></div></div>
                </div>
                """
            )
        return "".join(parts)

    risk_map = {row["risk_level"]: int(row["total"]) for row in data["risk_overview"]}
    recent_cases_html = "".join(
        f"""
        <div class="item">
          <div>
            <strong>{row["case_number"]}</strong>
            <div class="muted">{row["title"]}</div>
            <small>{row["client_name"]} | {row["lawyer_name"]}</small>
          </div>
          <div style="text-align:right;">
            <div class="pill">{row["status"]}</div>
            <small>{row["priority"]}</small>
          </div>
        </div>
        """
        for row in data["recent_cases"]
    ) or '<div class="item"><strong>No recent cases</strong><span>Add cases in the web app to populate this view.</span></div>'
    recent_audit_html = "".join(
        f"""
        <div class="item">
          <div>
            <strong>{row["action"]}</strong>
            <div class="muted">{row["full_name"]}</div>
            <small>{row["target_table"]}:{row["target_id"] or '-'}</small>
          </div>
          <span>{row["performed_at"]}</span>
        </div>
        """
        for row in data["recent_audit"]
    ) or '<div class="item"><strong>No audit entries</strong><span>Audit events will appear here.</span></div>'
    active_lawyers_html = "".join(
        f"""
        <div class="item">
          <strong>{row["lawyer_name"]}</strong>
          <span>{row["specialization"]}</span>
        </div>
        """
        for row in data["active_lawyers"]
    ) or '<div class="item"><strong>No active lawyers</strong><span>Create lawyers from the web app admin panel.</span></div>'
    active_clients_html = "".join(
        f"""
        <div class="item">
          <strong>{row["client_name"]}</strong>
          <span>{row["risk_level"]} risk</span>
        </div>
        """
        for row in data["active_clients"]
    ) or '<div class="item"><strong>No active clients</strong><span>Register or add clients in the web app.</span></div>'
    failed_ips_html = "".join(
        f"""
        <div class="item">
          <strong>{row["ip_address"]}</strong>
          <span>{row["total"]} attempts</span>
        </div>
        """
        for row in data["security"]["top_failed_ips"]
    ) or '<div class="item"><strong>No suspicious IPs</strong><span>No failed login activity recorded yet.</span></div>'

    html = f"""
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>LEXSHIELD Analytics</title>
      <style>
        :root {{
          --bg: #f5f7fb;
          --bg2: #e9edf5;
          --surface: rgba(255,255,255,.92);
          --surface-strong: #fff;
          --border: rgba(10,22,40,.12);
          --text: #0a1628;
          --muted: #5e6b7f;
          --gold: #c9a84c;
          --shadow: 0 18px 50px rgba(10,22,40,.11);
        }}
        * {{ box-sizing: border-box; }}
        body {{
          margin: 0;
          font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          color: var(--text);
          background:
            radial-gradient(circle at top left, rgba(201,168,76,.15), transparent 28%),
            linear-gradient(180deg, var(--bg), var(--bg2));
          min-height: 100vh;
          padding: 1rem;
        }}
        .shell {{ max-width: 1320px; margin: 0 auto; display: grid; gap: 1rem; }}
        .hero, .card {{
          background: var(--surface);
          border: 1px solid var(--border);
          border-radius: 28px;
          box-shadow: var(--shadow);
          backdrop-filter: blur(16px);
        }}
        .hero {{ padding: 1.25rem; display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }}
        .hero h1 {{ margin: 0; font-size: clamp(1.4rem, 2.6vw, 2.3rem); }}
        .hero p {{ margin: .25rem 0 0; color: var(--muted); max-width: 62ch; }}
        .button {{
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: .8rem 1rem;
          border-radius: 14px;
          text-decoration: none;
          font-weight: 700;
          border: 1px solid var(--border);
          color: inherit;
          background: var(--surface-strong);
        }}
        .button.primary {{ background: linear-gradient(135deg, var(--gold), #e5ca72); color: #1a1a1a; }}
        .grid {{ display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }}
        .two-col {{ display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }}
        .three-col {{ display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }}
        .card {{ padding: 1rem; }}
        .metric {{ display: grid; gap: .35rem; }}
        .metric span {{ color: var(--muted); font-size: .9rem; }}
        .metric strong {{ font-size: 1.9rem; letter-spacing: -.03em; }}
        .list {{ display: grid; gap: .75rem; }}
        .item {{
          padding: .9rem 1rem;
          border-radius: 18px;
          border: 1px solid var(--border);
          background: rgba(255,255,255,.5);
          display: flex;
          justify-content: space-between;
          gap: 1rem;
        }}
        .item small, .muted {{ color: var(--muted); }}
        .pill {{
          display: inline-flex;
          align-items: center;
          padding: .3rem .65rem;
          border-radius: 999px;
          background: rgba(201,168,76,.14);
          border: 1px solid rgba(201,168,76,.25);
          width: fit-content;
          font-size: .8rem;
        }}
        .bars {{ display: grid; gap: .85rem; }}
        .bar-row {{ display: grid; gap: .4rem; }}
        .bar-head {{ display: flex; justify-content: space-between; gap: 1rem; }}
        .bar-track {{ height: 10px; border-radius: 999px; background: rgba(10,22,40,.08); overflow: hidden; }}
        .bar-fill {{ height: 100%; border-radius: inherit; background: linear-gradient(90deg, #c9a84c, #7fb069); }}
        .endpoint-grid {{ display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }}
        code {{ display: inline-block; padding: .2rem .45rem; border-radius: 8px; background: rgba(10,22,40,.06); }}
        @media (max-width: 1100px) {{
          .grid, .two-col, .three-col, .endpoint-grid {{ grid-template-columns: 1fr; }}
        }}
      </style>
    </head>
    <body>
      <main class="shell">
        <section class="hero">
          <div>
            <div class="pill">Admin-aligned analytics</div>
            <h1>LEXSHIELD Flask Analytics Dashboard</h1>
            <p>This dashboard now follows the same operational view as the admin web app: cases, lawyers, risk coverage, security activity, audit feed, and current active accounts.</p>
          </div>
          <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
            <a class="button primary" href="/api/analytics">JSON Analytics</a>
            <a class="button" href="/api/cases?limit=10">Cases API</a>
            <a class="button" href="/api/messages?limit=10">Messages API</a>
          </div>
        </section>

        <section class="grid">
          <div class="card metric"><span>Total Cases</span><strong>{data["overview"]["total_cases"]}</strong></div>
          <div class="card metric"><span>Active Lawyers</span><strong>{data["overview"]["active_lawyers"]}</strong></div>
          <div class="card metric"><span>Open Cases</span><strong>{data["overview"]["open_cases"]}</strong></div>
          <div class="card metric"><span>Risk Coverage</span><strong>{data["overview"]["risk_coverage"]}</strong></div>
        </section>

        <section class="two-col">
          <article class="card">
            <h2 style="margin-top:0;">Risk Overview</h2>
            <div class="bars">{status_cards(risk_map, ["low", "medium", "high", "critical"])}</div>
          </article>
          <article class="card">
            <h2 style="margin-top:0;">Security Activity</h2>
            <div class="list">
              <div class="item"><strong>Failed logins</strong><span>{data["security"]["failed_logins"]}</span></div>
              <div class="item"><strong>Locked accounts</strong><span>{data["security"]["locked_accounts"]}</span></div>
              <div class="item"><strong>Latest failed login</strong><span>{data["security"]["latest_failed_login"] or "None"}</span></div>
            </div>
          </article>
        </section>

        <section class="two-col">
          <article class="card">
            <h2 style="margin-top:0;">Recent Cases</h2>
            <div class="list">{recent_cases_html}</div>
          </article>
          <article class="card">
            <h2 style="margin-top:0;">Audit Feed</h2>
            <div class="list">{recent_audit_html}</div>
          </article>
        </section>

        <section class="two-col">
          <article class="card">
            <h2 style="margin-top:0;">Active Lawyers</h2>
            <div class="list">{active_lawyers_html}</div>
          </article>
          <article class="card">
            <h2 style="margin-top:0;">Active Clients</h2>
            <div class="list">{active_clients_html}</div>
          </article>
        </section>

        <section class="three-col">
          <article class="card metric"><span>Upcoming Appointments</span><strong>{data["overview"]["upcoming_appointments"]}</strong></article>
          <article class="card metric"><span>Case Files</span><strong>{data["overview"]["case_files"]}</strong></article>
          <article class="card metric"><span>Unread Notifications</span><strong>{data["notifications"]["unread"]}</strong></article>
        </section>

        <section class="card">
          <h2 style="margin-top:0;">Top Failed IPs</h2>
          <div class="list">{failed_ips_html}</div>
        </section>

        <section class="card">
          <h2 style="margin-top:0;">API Endpoints</h2>
          <div class="endpoint-grid">
            <div class="item"><div><strong>/health</strong><div class="muted">Service + DB status</div></div><code>GET</code></div>
            <div class="item"><div><strong>/api/analytics</strong><div class="muted">All dashboard metrics</div></div><code>GET</code></div>
            <div class="item"><div><strong>/api/cases?limit=10</strong><div class="muted">Recent case list</div></div><code>GET</code></div>
            <div class="item"><div><strong>/api/messages?limit=10</strong><div class="muted">Recent messages</div></div><code>GET</code></div>
          </div>
        </section>
      </main>
    </body>
    </html>
    """
    return render_template_string(html)


if __name__ == "__main__":
    port = env_int("FLASK_PORT", 5000)
    app.run(host="127.0.0.1", port=port, debug=True)

