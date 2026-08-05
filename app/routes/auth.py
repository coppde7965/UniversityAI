from fastapi import APIRouter, Form, Query
from fastapi.responses import HTMLResponse, RedirectResponse

from app.state import TEACHER_ID
from app.routes import syllabus, materials

router = APIRouter()

@router.get("/login", response_class=HTMLResponse)
async def login_page():
    return """
    <html>
        <head>
            <meta charset="utf-8">
            <title>Login</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; background: #f7f8fb; }
                .box { max-width: 420px; padding: 24px; border: 1px solid #ccc; border-radius: 12px; background: white; }
                input { width: 100%; padding: 10px; margin: 6px 0 16px 0; box-sizing: border-box; }
                button { padding: 10px 16px; }
            </style>
        </head>
        <body>
            <div class="box">
                <h1>UniversityAI Login</h1>
                <form action="/login" method="post">
                    <label>Username:</label>
                    <input type="text" name="username" value="admin">

                    <label>Password:</label>
                    <input type="password" name="password" value="admin">

                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
    </html>
    """

@router.post("/login")
async def login_submit(username: str = Form(...), password: str = Form(...)):
    if username == "admin" and password == "admin":
        return RedirectResponse(url="/teacher?user_id=teacher-fixed-id", status_code=303)
    return HTMLResponse(
        """
        <html>
            <head><title>Login Failed</title></head>
            <body>
                <h1>Login Failed</h1>
                <p>Invalid username or password.</p>
                <a href="/login">Back to Login</a>
            </body>
        </html>
        """,
        status_code=401,
    )

@router.get("/dashboard", response_class=HTMLResponse)
async def dashboard():
    syllabi_data = getattr(syllabus, "SYLLABI", [])
    mats = getattr(materials, "MATERIALS", [])

    total_syllabi = len(syllabi_data)
    total_materials = len(mats)
    total_weeks = sum(int(s.get("total_weeks", 0) or 0) for s in syllabi_data)
    total_week_rows = sum(len(s.get("weeks", [])) for s in syllabi_data)

    syllabi_rows = ""
    for s in syllabi_data:
        weeks = s.get("weeks", [])
        if weeks:
            done_count = sum(
                1 for w in weeks
                if w.get("topic") and w.get("topic") != f"Week {w.get('week_no')}"
            )
            completion = int((done_count / len(weeks)) * 100)
        else:
            completion = 0

        sid = s.get("syllabus_id", "")
        syllabi_rows += f"""
        <div class="card">
            <h3>{s.get('title', '')}</h3>
            <p><strong>File:</strong> {s.get('file_name', '')}</p>
            <p><strong>Start Date:</strong> {s.get('start_date', '')} | <strong>Weekday:</strong> {s.get('weekday', '')}</p>
            <p><strong>Total Weeks:</strong> {s.get('total_weeks', '')} | <strong>Created:</strong> {s.get('created_at', '')}</p>
            <p><strong>Progress:</strong> {completion}%</p>
            <div class="bar"><div class="fill" style="width:{completion}%"></div></div>
            <p>
                <a href="/syllabus/{sid}">View</a> |
                <a href="/progress/{sid}">Progress JSON</a>
            </p>
        </div>
        """

    recent_materials = ""
    for m in mats[-5:][::-1]:
        recent_materials += f"""
        <li>
            <strong>{m.get('title', '')}</strong> [{m.get('source_type', '')}]<br>
            <span>{m.get('summary', '')[:180]}</span>
        </li>
        """

    week_items = ""
    for s in syllabi_data:
        for w in s.get("weeks", []):
            week_items += f"""
            <tr>
                <td>{s.get('title', '')}</td>
                <td>Week {w.get('week_no', '')}</td>
                <td>{w.get('date', '')}</td>
                <td>{w.get('topic', '')}</td>
                <td>{w.get('note', '')}</td>
            </tr>
            """

    return f"""
    <html>
        <head>
            <title>Dashboard</title>
            <style>
                body {{
                    font-family: Arial, sans-serif;
                    margin: 24px;
                    background: #f7f8fb;
                }}
                h1, h2, h3 {{
                    color: #1f3c88;
                }}
                .topbar {{
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                }}
                .stats {{
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 16px;
                    margin-bottom: 24px;
                }}
                .stat {{
                    background: white;
                    padding: 16px;
                    border-radius: 12px;
                    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
                }}
                .grid {{
                    display: grid;
                    grid-template-columns: 1.2fr 1fr;
                    gap: 20px;
                    margin-bottom: 24px;
                }}
                .panel {{
                    background: white;
                    padding: 18px;
                    border-radius: 12px;
                    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
                }}
                .card {{
                    background: #fff;
                    border: 1px solid #e5e5e5;
                    border-radius: 12px;
                    padding: 16px;
                    margin-bottom: 16px;
                }}
                .bar {{
                    width: 100%;
                    background: #e9ecef;
                    border-radius: 999px;
                    overflow: hidden;
                    height: 12px;
                }}
                .fill {{
                    height: 12px;
                    background: linear-gradient(90deg, #4e73df, #36b9cc);
                }}
                table {{
                    width: 100%;
                    border-collapse: collapse;
                    background: white;
                }}
                th, td {{
                    border: 1px solid #ddd;
                    padding: 10px;
                    font-size: 14px;
                    vertical-align: top;
                }}
                th {{
                    background: #f1f4ff;
                    text-align: left;
                }}
                ul {{
                    padding-left: 18px;
                }}
                .nav a {{
                    margin-right: 12px;
                }}
                .small {{
                    color: #666;
                    font-size: 13px;
                }}
            </style>
        </head>
        <body>
            <div class="topbar">
                <div>
                    <h1>課綱與進度總覽</h1>
                    <div class="small">UniversityAI Dashboard</div>
                </div>
                <div class="nav">
                    <a href="/syllabus?user_id={TEACHER_ID}">Syllabus</a>
                    <a href="/search">Search</a>
                    <a href="/export">Export</a>
                    <a href="/login">Logout</a>
                </div>
            </div>

            <div class="stats">
                <div class="stat"><h3>{total_syllabi}</h3><p>課綱數量</p></div>
                <div class="stat"><h3>{total_materials}</h3><p>教材數量</p></div>
                <div class="stat"><h3>{total_weeks}</h3><p>總週數</p></div>
                <div class="stat"><h3>{total_week_rows}</h3><p>已產生週次列</p></div>
            </div>

            <div class="grid">
                <div class="panel">
                    <h2>課綱清單</h2>
                    {syllabi_rows if syllabi_rows else "<p>目前尚無上傳課綱。</p>"}
                </div>

                <div class="panel">
                    <h2>近期教材</h2>
                    <ul>
                        {recent_materials if recent_materials else "<li>目前尚無上傳教材。</li>"}
                    </ul>
                </div>
            </div>

            <div class="panel" style="margin-bottom: 24px;">
                <h2>每週進度總表</h2>
                <table>
                    <tr>
                        <th>課綱</th>
                        <th>週次</th>
                        <th>日期</th>
                        <th>主題</th>
                        <th>備註</th>
                    </tr>
                    {week_items if week_items else "<tr><td colspan='5'>目前尚無進度資料。</td></tr>"}
                </table>
            </div>

            <div class="panel">
                <h2>快速操作</h2>
                <ul>
                    <li><a href="/syllabus?user_id={TEACHER_ID}">上傳或查看課綱</a></li>
                    <li><a href="/search">搜尋課綱與教材</a></li>
                    <li><a href="/export">匯出 PDF</a></li>
                </ul>
            </div>
        </body>
    </html>
    """

@router.get("/teacher")
async def teacher_redirect(
    user_id: str | None = Query(None),
    userid: str | None = Query(None),
):
    uid = user_id or userid
    if uid != TEACHER_ID:
        return RedirectResponse(url="/login", status_code=302)
    return RedirectResponse(url="/dashboard", status_code=302)
