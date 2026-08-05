from fastapi import APIRouter, Request, File, UploadFile
from fastapi.responses import HTMLResponse, RedirectResponse
from typing import Optional
import os
import uuid
from datetime import datetime

from app.state import TEACHER_ID

router = APIRouter()

@router.get("/materials", response_class=HTMLResponse)
async def materials_page(request: Request):
    return """
    <html>
        <head>
            <meta charset="utf-8">
            <title>Materials</title>
        </head>
        <body>
            <h1>Materials</h1>
            <form action="/schedule/demo-syllabus/materials/upload" method="post" enctype="multipart/form-data">
                <input type="file" name="material_files" multiple>
                <button type="submit">Upload</button>
            </form>
        </body>
    </html>
    """

@router.post("/schedule/{syllabus_id}/materials/upload")
async def upload_schedule_materials(
    request: Request,
    syllabus_id: str,
    material_files: Optional[list[UploadFile]] = File(None)
):
    syllabi = getattr(request.app.state, "syllabi", {})
    if syllabus_id not in syllabi:
        return RedirectResponse(url=f"/syllabus?user_id={TEACHER_ID}", status_code=302)

    os.makedirs("uploads", exist_ok=True)
    incoming = material_files or []
    s = syllabi[syllabus_id]

    for f in incoming:
        if not f or not f.filename:
            continue
        safe_name = f"{uuid.uuid4()}_{f.filename}"
        path = os.path.join("uploads", safe_name)
        with open(path, "wb") as fp:
            fp.write(await f.read())
        s.setdefault("files", []).append({
            "id": str(uuid.uuid4()),
            "filename": f.filename,
            "filepath": path,
            "created_at": datetime.now().isoformat(),
            "title": f.filename,
            "type": "upload"
        })

    return RedirectResponse(url=f"/progress/{syllabus_id}/page", status_code=302)
