from fastapi import APIRouter, Form
from fastapi.responses import HTMLResponse, StreamingResponse
from xhtml2pdf import pisa
import io

from app.routes import syllabus, materials

router = APIRouter(prefix="/export")

def build_html_for_export(syllabus_ids, material_ids):
    html = """
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: Helvetica, Arial, sans-serif; }
            h1, h2, h3 { color: #1f3c88; }
            .box { margin-bottom: 20px; padding: 10px; border: 1px solid #ccc; }
            .meta { color: #666; font-size: 12px; }
            pre { white-space: pre-wrap; word-wrap: break-word; }
        </style>
    </head>
    <body>
    <h1>UniversityAI Export Report</h1>
    """

    html += "<h2>Syllabi</h2>"
    if syllabus_ids:
        for sid in syllabus_ids:
            s = next((x for x in getattr(syllabus, "SYLLABI", []) if x.get("syllabus_id") == sid), None)
            if s:
                html += "<div class='box'>"
                html += f"<h3>{s.get('title', '')}</h3>"
                html += f"<div class='meta'>Start Date: {s.get('start_date', '')} | Weekday: {s.get('weekday', '')} | Total Weeks: {s.get('total_weeks', '')}</div>"
                html += f"<div class='meta'>File: {s.get('file_name', '')}</div>"
                html += "<ul>"
                for w in s.get("weeks", []):
                    html += f"<li>Week {w.get('week_no', '')} - {w.get('date', '')} - {w.get('topic', '')}</li>"
                html += "</ul>"
                html += "<pre>" + (s.get("text", "")[:1200] if s.get("text") else "") + "</pre>"
                html += "</div>"
    else:
        html += "<p>No syllabus selected.</p>"

    html += "<h2>Materials</h2>"
    if material_ids:
        for mid in material_ids:
            m = next((x for x in getattr(materials, "MATERIALS", []) if x.get("material_id") == mid), None)
            if m:
                html += "<div class='box'>"
                html += f"<h3>{m.get('title', '')}</h3>"
                html += f"<div class='meta'>Source Type: {m.get('source_type', '')}</div>"
                html += f"<div class='meta'>File: {m.get('file_name', '')}</div>"
                html += f"<p><strong>Summary:</strong> {m.get('summary', '')}</p>"
                html += "<pre>" + (m.get("text", "")[:1200] if m.get("text") else "") + "</pre>"
                html += "</div>"
    else:
        html += "<p>No material selected.</p>"

    html += "</body></html>"
    return html

def html_to_pdf_bytes(html: str) -> bytes:
    buffer = io.BytesIO()
    pisa.CreatePDF(io.StringIO(html), dest=buffer)
    return buffer.getvalue()

@router.get("", response_class=HTMLResponse)
async def export_page():
    s_items = ""
    for s in getattr(syllabus, "SYLLABI", []):
        s_items += f"""
        <label>
            <input type="checkbox" name="syllabus_ids" value="{s.get('syllabus_id', '')}">
            {s.get('title', '')} ({s.get('start_date', '')}, {s.get('total_weeks', '')} weeks)
        </label><br>
        """

    m_items = ""
    for m in getattr(materials, "MATERIALS", []):
        m_items += f"""
        <label>
            <input type="checkbox" name="material_ids" value="{m.get('material_id', '')}">
            {m.get('title', '')} [{m.get('source_type', '')}]
        </label><br>
        """

    return f"""
    <html>
        <head><title>Export</title></head>
        <body>
            <h1>Export to PDF</h1>
            <form action="/export/pdf" method="post">
                <h3>Select Syllabi</h3>
                {s_items if s_items else "<p>No syllabus available.</p>"}
                <h3>Select Materials</h3>
                {m_items if m_items else "<p>No material available.</p>"}
                <br>
                <button type="submit">Generate PDF</button>
            </form>
            <a href="/">Back to Home</a>
        </body>
    </html>
    """

@router.post("/pdf")
async def export_pdf(
    syllabus_ids: list[str] = Form(default=[]),
    material_ids: list[str] = Form(default=[])
):
    html = build_html_for_export(syllabus_ids, material_ids)
    pdf_bytes = html_to_pdf_bytes(html)
    return StreamingResponse(
        io.BytesIO(pdf_bytes),
        media_type="application/pdf",
        headers={"Content-Disposition": "attachment; filename=export.pdf"}
    )
