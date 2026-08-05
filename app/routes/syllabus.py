from fastapi import APIRouter, File, UploadFile, Form, HTTPException
from fastapi.responses import HTMLResponse, JSONResponse
from pypdf import PdfReader
import io
import uuid
from datetime import datetime, timedelta
from pathlib import Path

from app.routes import search
from app.storage import load_syllabi, save_syllabi

router = APIRouter(prefix="/syllabus")

SYLLABI = load_syllabi()

UPLOAD_DIR = Path("uploads")
UPLOAD_DIR.mkdir(exist_ok=True)

def extract_text_from_pdf(file_bytes: bytes) -> str:
    reader = PdfReader(io.BytesIO(file_bytes))
    text = ""
    for page in reader.pages:
        text += page.extract_text() or ""
    return text

def guess_title_from_text(text: str) -> str:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if lines:
        return lines[0][:120]
    return "Untitled Syllabus"

def parse_syllabus_from_text(text: str, title: str, start_date: str, weekday: str, total_weeks: int):
    weeks = []
    sd = datetime.strptime(start_date, "%Y-%m-%d")
    for i in range(1, total_weeks + 1):
        week_date = sd + timedelta(weeks=i - 1)
        weeks.append({
            "week_no": i,
            "date": week_date.strftime("%Y-%m-%d"),
            "topic": f"Week {i}",
            "note": "",
            "material_ids": []
        })
    return {
        "syllabus_id": str(uuid.uuid4()),
        "title": title,
        "file_name": "",
        "file_path": "",
        "start_date": start_date,
        "weekday": weekday,
        "total_weeks": total_weeks,
        "text": text,
        "weeks": weeks,
        "schedule": weeks.copy(),
        "files": [],
        "created_at": datetime.now().isoformat()
    }

@router.get("", response_class=HTMLResponse)
async def syllabus_page():
    items = ""
    for s in SYLLABI:
        items += f"<li><a href='/progress/{s['syllabus_id']}/page'>{s['title']}</a> - {s['start_date']} (Weeks: {s['total_weeks']})</li>"
    return f"""
    <html>
        <head><title>Syllabus</title></head>
        <body>
            <h1>Syllabus</h1>
            <h2>Upload Syllabus PDF</h2>
            <form action="/syllabus/upload" method="post" enctype="multipart/form-data">
                <input type="file" name="file" accept="application/pdf" required><br><br>
                <label>Start Date (YYYY-MM-DD): <input type="text" name="start_date" value="2026-09-01"></label><br><br>
                <label>Weekday: <input type="text" name="weekday" value="Monday"></label><br><br>
                <label>Total Weeks: <input type="number" name="total_weeks" value="18"></label><br><br>
                <button type="submit">Upload & Create Syllabus</button>
            </form>
            <h2>Existing Syllabi</h2>
            <ul>{items}</ul>
            <a href="/">Back to Home</a>
        </body>
    </html>
    """

@router.post("/upload")
async def upload_syllabus(
    file: UploadFile = File(...),
    start_date: str = Form(...),
    weekday: str = Form(...),
    total_weeks: int = Form(...)
):
    if not file.filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Only PDF files are allowed.")

    content = await file.read()
    if not content:
        raise HTTPException(status_code=400, detail="請先上傳可解析的檔案，再建立課綱。")

    text = extract_text_from_pdf(content)
    if not text.strip():
        raise HTTPException(status_code=400, detail="無法解析教材內容，請重新上傳可辨識教材。")

    title = guess_title_from_text(text)
    syllabus = parse_syllabus_from_text(text, title, start_date, weekday, total_weeks)

    safe_name = f"{syllabus['syllabus_id']}_{file.filename}"
    file_path = UPLOAD_DIR / safe_name
    file_path.write_bytes(content)

    syllabus["file_name"] = file.filename
    syllabus["file_path"] = str(file_path.as_posix())
    SYLLABI.append(syllabus)
    save_syllabi(SYLLABI)

    if not hasattr(search, "SEARCH_SOURCES"):
        search.SEARCH_SOURCES = []
    search.add_search_source({
        "source_type": "syllabus",
        "title": syllabus["title"],
        "item_id": syllabus["syllabus_id"],
        "text": text,
        "file_name": file.filename,
        "created_at": syllabus["created_at"]
    })

    return JSONResponse({"status": "ok", "syllabus_id": syllabus["syllabus_id"]})

@router.get("/json")
async def list_syllabi_json():
    return JSONResponse(SYLLABI)