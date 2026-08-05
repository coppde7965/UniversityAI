from fastapi import FastAPI
from fastapi.responses import HTMLResponse

from app.storage import load_syllabi, load_materials
from app.routes.auth import router as auth_router
from app.routes.progress import router as progress_router
from app.routes.syllabus import router as syllabus_router
from app.routes.materials import router as materials_router
from app.routes.search import router as search_router
from app.routes.export import router as export_router

app = FastAPI(title="UniversityAI")

app.include_router(auth_router)
app.include_router(progress_router)
app.include_router(syllabus_router)
app.include_router(materials_router)
app.include_router(search_router)
app.include_router(export_router)


@app.get("/", response_class=HTMLResponse)
async def home():
    syllabi = load_syllabi()
    materials = load_materials()
    recent_syllabi = syllabi[-3:][::-1]
    recent_materials = materials[-3:][::-1]

    syllabus_items = ""
    for s in recent_syllabi:
        syllabus_items += f"<li><strong>{s.get('title','Untitled')}</strong> - {s.get('created_at','')}</li>"

    material_items = ""
    for m in recent_materials:
        material_items += f"<li><strong>{m.get('title','Untitled')}</strong> [{m.get('source_type','')}] - {m.get('created_at','')}</li>"

    return f"""
    <html>
        <head>
            <title>UniversityAI</title>
            <style>
                body {{ font-family: Arial, sans-serif; margin: 24px; background: #f7f8fb; }}
                .box {{ background: white; padding: 18px; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 18px; }}
                a {{ margin-right: 12px; }}
                .grid {{ display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }}
            </style>
        </head>
        <body>
            <div class="box">
                <h1>UniversityAI</h1>
                <p>課綱與進度管理後台入口</p>
                <p>
                    <a href="/login">Login</a>
                    <a href="/dashboard">Dashboard</a>
                    <a href="/progress">Progress</a>
                    <a href="/docs">API Docs</a>
                </p>
            </div>
            <div class="grid">
                <div class="box">
                    <h2>最近更新課綱</h2>
                    <ul>{syllabus_items if syllabus_items else '<li>目前沒有課綱資料。</li>'}</ul>
                </div>
                <div class="box">
                    <h2>最近更新教材</h2>
                    <ul>{material_items if material_items else '<li>目前沒有教材資料。</li>'}</ul>
                </div>
            </div>
        </body>
    </html>
    """